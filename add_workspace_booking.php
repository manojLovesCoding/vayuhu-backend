<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

try {
    // ------------------------------------
    // JWT VERIFICATION FROM COOKIE
    // ------------------------------------
    $token = $_COOKIE['auth_token'] ?? null; // <-- read JWT from HttpOnly cookie

    if (!$token) {
        http_response_code(401);
        throw new Exception("No session found. Please log in.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // User is authenticated, you can access $decoded->data->id if needed
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token. Please log in again.");
    }

    // ------------------------------------
    include 'db.php';

    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON payload.");

    // --- Extract and validate input ---
    $user_id         = (int)($data['user_id'] ?? 0);
    $space_id        = (int)($data['space_id'] ?? 0);

    // Capture seat codes array or string
    $seat_codes_raw  = $data['selected_codes'] ?? $data['seat_codes'] ?? '';
    $seat_codes      = is_array($seat_codes_raw) ? implode(", ", $seat_codes_raw) : trim($seat_codes_raw);

    $workspace_title = trim($data['workspace_title'] ?? '');
    $plan_type       = strtolower(trim($data['plan_type'] ?? ''));
    $start_date      = trim($data['start_date'] ?? '');
    $end_date        = trim($data['end_date'] ?? '');
    $start_time      = trim($data['start_time'] ?? null);
    $end_time        = trim($data['end_time'] ?? null);
    $total_days      = (int)($data['total_days'] ?? 1);
    $total_hours     = (int)($data['total_hours'] ?? 1);
    $num_attendees   = (int)($data['num_attendees'] ?? 1);
    $price_per_unit  = (float)($data['price_per_unit'] ?? 0);
    $base_amount     = (float)($data['base_amount'] ?? 0);
    $gst_amount      = (float)($data['gst_amount'] ?? 0);
    $discount_amount = (float)($data['discount_amount'] ?? 0);
    $final_amount    = (float)($data['final_amount'] ?? 0);
    $coupon_code     = trim($data['coupon_code'] ?? '');
    $referral_source = trim($data['referral_source'] ?? '');
    $terms_accepted  = (int)($data['terms_accepted'] ?? 0);
    $payment_id      = trim($data['payment_id'] ?? '');


    if ($user_id <= 0 || $space_id <= 0 || !$workspace_title || !$plan_type || !$start_date || !$end_date) {
        throw new Exception("Missing required fields.");
    }
    if (!in_array($plan_type, ['hourly', 'daily', 'monthly'])) {
        throw new Exception("Invalid plan_type. Must be 'hourly', 'daily', or 'monthly'.");
    }
    if ($terms_accepted !== 1) throw new Exception("Terms must be accepted.");

    // --- Validate dates and times ---
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date)) {
        throw new Exception("Invalid date format. Expected YYYY-MM-DD.");
    }
    if (($plan_type === 'hourly' || $plan_type === 'daily') && (date('w', strtotime($start_date)) == 0 || date('w', strtotime($end_date)) == 0)) {
        throw new Exception("Bookings cannot be made on Sundays.");
    }
    if ($plan_type === 'monthly' && date('w', strtotime($start_date)) == 0) {
        throw new Exception("Monthly bookings cannot start on Sundays.");
    }
    if ($start_time && !preg_match("/^\d{2}:\d{2}$/", $start_time)) throw new Exception("Invalid start_time format. Expected HH:MM.");
    if ($end_time && !preg_match("/^\d{2}:\d{2}$/", $end_time)) throw new Exception("Invalid end_time format. Expected HH:MM.");
    if ($start_time && strlen($start_time) === 5) $start_time .= ':00';
    if ($end_time && strlen($end_time) === 5) $end_time .= ':00';

    // --- Validate user and space exist ---
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) throw new Exception("Invalid user_id: user not found.");
    $stmt->close();

    $stmt = $conn->prepare("SELECT 1 FROM spaces WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $space_id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows === 0) throw new Exception("Invalid space_id: space not found.");
    $stmt->close();

    // --- Check for booking conflicts ---
    $stmt = $conn->prepare("
        SELECT plan_type, start_date, end_date, start_time, end_time
        FROM workspace_bookings
        WHERE space_id = ?
          AND (
                (plan_type = 'hourly' AND start_date = ? AND NOT (? <= start_time OR ? >= end_time))
             OR (plan_type = 'daily' AND start_date = ?)
             OR (plan_type = 'monthly' AND NOT (? < start_date OR ? > end_date))
              )
        LIMIT 1
    ");
    $stmt->bind_param("issssss", $space_id, $start_date, $end_time, $start_time, $start_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $conflict = $result->fetch_assoc();
        throw new Exception("This workspace is already booked for the selected time/date.");
    }
    $stmt->close();

    // --- Generate booking ID ---
    $today = date("Ymd");
    $query = "SELECT booking_id FROM workspace_bookings WHERE booking_id LIKE 'BKG-$today-%' ORDER BY booking_id DESC LIMIT 1";
    $result = $conn->query($query);
    $nextNum = ($result && $row = $result->fetch_assoc()) ? str_pad((int)substr($row['booking_id'], -3) + 1, 3, '0', STR_PAD_LEFT) : "001";
    $booking_id = "BKG-$today-$nextNum";

    // --- Insert booking as pending ---
    $status = 'pending';

    $stmt = $conn->prepare("
        INSERT INTO workspace_bookings (
    booking_id, user_id, space_id, seat_codes, workspace_title, plan_type,
    start_date, end_date, start_time, end_time,
    total_days, total_hours, num_attendees,
    price_per_unit, base_amount, gst_amount, discount_amount, final_amount,
    coupon_code, referral_source, terms_accepted, status, payment_id
)
 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

    ");

    $stmt->bind_param(
        "siisssssssiidddddssisss",
        $booking_id,
        $user_id,
        $space_id,
        $seat_codes,
        $workspace_title,
        $plan_type,
        $start_date,
        $end_date,
        $start_time,
        $end_time,
        $total_days,
        $total_hours,
        $num_attendees,
        $price_per_unit,
        $base_amount,
        $gst_amount,
        $discount_amount,
        $final_amount,
        $coupon_code,
        $referral_source,
        $terms_accepted,
        $status,
        $payment_id
    );


    if (!$stmt->execute()) throw new Exception("Could not save booking. " . $stmt->error);
    $stmt->close();

    // --- Simulate payment processing ---
    $payment_successful = !empty($payment_id); // Replaced with real payment gateway response
    if ($payment_successful) {
        $update = $conn->prepare("UPDATE workspace_bookings SET status = 'confirmed' WHERE booking_id = ?");
        $update->bind_param("s", $booking_id);
        $update->execute();
        $update->close();
    }

    $conn->close();

    echo json_encode([
        "success" => true,
        "message" => "Booking saved successfully.",
        "booking_id" => $booking_id,
        "status" => $payment_successful ? 'confirmed' : 'pending'
    ]);
} catch (Exception $e) {
    $code = (strpos($e->getMessage(), "token") !== false || strpos($e->getMessage(), "Authorization") !== false) ? 401 : 400;
    http_response_code($code);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
