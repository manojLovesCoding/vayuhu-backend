<?php
// -------------------------
// Load Environment & CORS
// -------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Handles CORS preflight and headers

// -------------------------
// Prevent PHP warnings from breaking JSON
// -------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

// -------------------------
// JWT Library
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// -------------------------
// Use secret from .env
// -------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

try {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing.");
    }

    // Extract token from "Bearer <token>" or fallback
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    } else {
        $token = $authHeader;
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        $userData = (array)$decoded->data;
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // -------------------------
    // Database Connection
    // -------------------------
    include 'db.php';

    // -------------------------
    // Parse JSON input
    // -------------------------
    $inputData = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON payload.");

    if (!isset($inputData['bookings']) || !is_array($inputData['bookings'])) {
        throw new Exception("Invalid format. Expected 'bookings' array.");
    }

    $bookings = $inputData['bookings'];
    $responseIds = [];

    $conn->begin_transaction();

    $today = date("Ymd");
    $query = "SELECT booking_id FROM workspace_bookings WHERE booking_id LIKE 'BKG-$today-%' ORDER BY booking_id DESC LIMIT 1";
    $result = $conn->query($query);

    $currentSequence = ($result && $row = $result->fetch_assoc())
        ? (int)substr($row['booking_id'], -3)
        : 0;

    foreach ($bookings as $index => $data) {

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

        $base_amount     = round((float)($data['base_amount'] ?? 0));
        $gst_amount      = round((float)($data['gst_amount'] ?? 0));
        $discount_amount = round((float)($data['discount_amount'] ?? 0));
        $final_amount    = round((float)($data['final_amount'] ?? 0));

        $coupon_code     = trim($data['coupon_code'] ?? '');
        $referral_source = trim($data['referral_source'] ?? '');
        $terms_accepted  = (int)($data['terms_accepted'] ?? 0);
        $payment_id      = trim($data['payment_id'] ?? null);

        if ($user_id <= 0 || $space_id <= 0 || !$workspace_title || !$plan_type || !$start_date || !$end_date) {
            throw new Exception("Item #" . ($index + 1) . ": Missing required fields.");
        }

        if (($plan_type === 'hourly' || $plan_type === 'daily') && (date('w', strtotime($start_date)) == 0 || date('w', strtotime($end_date)) == 0)) {
            throw new Exception("Item #" . ($index + 1) . ": Bookings cannot be made on Sundays.");
        }

        if ($start_time && strlen($start_time) === 5) $start_time .= ':00';
        if ($end_time && strlen($end_time) === 5) $end_time .= ':00';

        // Conflict Check
        $stmt = $conn->prepare("
            SELECT plan_type FROM workspace_bookings
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
        $resCheck = $stmt->get_result();
        if ($resCheck && $resCheck->num_rows > 0) {
            throw new Exception("Item #" . ($index + 1) . " ($workspace_title) is already booked for this time.");
        }
        $stmt->close();

        $currentSequence++;
        $booking_id = "BKG-$today-" . str_pad($currentSequence, 3, '0', STR_PAD_LEFT);
        $responseIds[] = $booking_id;
        $status = 'confirmed';

        $stmt = $conn->prepare("
            INSERT INTO workspace_bookings (
                booking_id, user_id, space_id, seat_codes, workspace_title, plan_type,
                start_date, end_date, start_time, end_time,
                total_days, total_hours, num_attendees,
                price_per_unit, base_amount, gst_amount, discount_amount, final_amount,
                coupon_code, referral_source, terms_accepted, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "siisssssssiidddddssiss",
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
            $status
        );

        if (!$stmt->execute()) {
            throw new Exception("Database error on Item #" . ($index + 1) . ": " . $stmt->error);
        }
        $stmt->close();
    }

    $conn->commit();
    $conn->close();

    echo json_encode([
        "success" => true,
        "message" => "All bookings confirmed successfully.",
        "booking_ids" => $responseIds
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno == 0) {
        $conn->rollback();
        $conn->close();
    }

    http_response_code(strpos($e->getMessage(), "token") !== false ? 401 : 400);

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
