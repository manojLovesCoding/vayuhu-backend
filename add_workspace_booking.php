<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers

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
    $token = $_COOKIE['auth_token'] ?? null; 

    if (!$token) {
        http_response_code(401);
        throw new Exception("No session found. Please log in.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
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
        throw new Exception("Invalid plan_type.");
    }
    if ($terms_accepted !== 1) throw new Exception("Terms must be accepted.");

    // --- Validate dates and times ---
    if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $start_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $end_date)) {
        throw new Exception("Invalid date format. Expected YYYY-MM-DD.");
    }

    // Sunday logic
    if (($plan_type === 'hourly' || $plan_type === 'daily') && (date('w', strtotime($start_date)) == 0 || date('w', strtotime($end_date)) == 0)) {
        throw new Exception("Bookings cannot be made on Sundays.");
    }

    // --- HOURLY VALIDATION (1-HOUR INCREMENTS) ---
    if ($plan_type === 'hourly') {
        if (!$start_time || !$end_time) throw new Exception("Start and End times are required for hourly plans.");
        
        $t1 = new DateTime($start_time);
        $t2 = new DateTime($end_time);

        // 1. Check if minutes match (e.g., 03:15 and 04:15)
        if ($t1->format('i') !== $t2->format('i')) {
            throw new Exception("Hourly bookings must be in full-hour intervals (minutes must match).");
        }

        // 2. Ensure end is after start
        if ($t2 <= $t1) {
            throw new Exception("End time must be later than start time.");
        }

        // 3. Ensure at least 1 hour
        $diff = $t1->diff($t2);
        if ($diff->h < 1 && $diff->d == 0) {
            throw new Exception("Minimum booking duration is 1 hour.");
        }
        
        // Update total_hours to be exact based on calculation
        $total_hours = $diff->h + ($diff->d * 24);
    }

    // Standardize time format for MySQL
    if ($start_time && strlen($start_time) === 5) $start_time .= ':00';
    if ($end_time && strlen($end_time) === 5) $end_time .= ':00';

    // --- Validate user and space exist ---
    $stmt = $conn->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) throw new Exception("User not found.");
    $stmt->close();

    $stmt = $conn->prepare("SELECT 1 FROM spaces WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $space_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) throw new Exception("Workspace not found.");
    $stmt->close();

    // --- Conflict Check ---
    $stmt = $conn->prepare("
        SELECT 1 FROM workspace_bookings 
        WHERE space_id = ? AND status != 'cancelled'
          AND (
               (plan_type = 'hourly' AND start_date = ? AND NOT (? <= start_time OR ? >= end_time))
            OR (plan_type = 'daily' AND start_date = ?)
            OR (plan_type = 'monthly' AND NOT (? < start_date OR ? > end_date))
          ) LIMIT 1
    ");
    $stmt->bind_param("issssss", $space_id, $start_date, $end_time, $start_time, $start_date, $start_date, $end_date);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) throw new Exception("Workspace is already booked for this slot.");
    $stmt->close();

    // --- ID Generation ---
    $today = date("Ymd");
    $query = "SELECT booking_id FROM workspace_bookings WHERE booking_id LIKE 'BKG-$today-%' ORDER BY booking_id DESC LIMIT 1";
    $result = $conn->query($query);
    $nextNum = ($result && $row = $result->fetch_assoc()) ? str_pad((int)substr($row['booking_id'], -3) + 1, 3, '0', STR_PAD_LEFT) : "001";
    $booking_id = "BKG-$today-$nextNum";

    // --- Insert ---
    $status = 'pending';
    $stmt = $conn->prepare("
        INSERT INTO workspace_bookings (
            booking_id, user_id, space_id, seat_codes, workspace_title, plan_type,
            start_date, end_date, start_time, end_time,
            total_days, total_hours, num_attendees,
            price_per_unit, base_amount, gst_amount, discount_amount, final_amount,
            coupon_code, referral_source, terms_accepted, status, payment_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("siisssssssiidddddssisss", 
        $booking_id, $user_id, $space_id, $seat_codes, $workspace_title, $plan_type,
        $start_date, $end_date, $start_time, $end_time,
        $total_days, $total_hours, $num_attendees,
        $price_per_unit, $base_amount, $gst_amount, $discount_amount, $final_amount,
        $coupon_code, $referral_source, $terms_accepted, $status, $payment_id
    );

    if (!$stmt->execute()) throw new Exception("Save failed: " . $stmt->error);
    $stmt->close();

    if (!empty($payment_id)) {
        $update = $conn->prepare("UPDATE workspace_bookings SET status = 'confirmed' WHERE booking_id = ?");
        $update->bind_param("s", $booking_id);
        $update->execute();
        $update->close();
    }

    echo json_encode(["success" => true, "booking_id" => $booking_id, "status" => !empty($payment_id) ? 'confirmed' : 'pending']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}