<?php
// add_bulk_bookings.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CORS Headers
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login script

try {
    // ------------------------------------
    // ✅ NEW: JWT VERIFICATION LOGIC
    // ------------------------------------
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing.");
    }

    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // User is authenticated
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }
    // ------------------------------------

    include 'db.php';

    // 2. Decode the BULK payload
    $inputData = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON payload.");

    // Expecting: { "bookings": [ ... ] }
    if (!isset($inputData['bookings']) || !is_array($inputData['bookings'])) {
        throw new Exception("Invalid format. Expected 'bookings' array.");
    }

    $bookings = $inputData['bookings'];
    $responseIds = [];
    
    // 3. START TRANSACTION (The Safety Net)
    $conn->begin_transaction();

    // 4. Get the starting Booking ID Number for today
    $today = date("Ymd");
    $query = "SELECT booking_id FROM workspace_bookings WHERE booking_id LIKE 'BKG-$today-%' ORDER BY booking_id DESC LIMIT 1";
    $result = $conn->query($query);
    
    $currentSequence = ($result && $row = $result->fetch_assoc()) 
        ? (int)substr($row['booking_id'], -3) 
        : 0;

    // 5. Loop through every booking in the cart
    foreach ($bookings as $index => $data) {
        
        // --- A. VALIDATION ---
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
        $payment_id      = trim($data['payment_id'] ?? null);

        if ($user_id <= 0 || $space_id <= 0 || !$workspace_title || !$plan_type || !$start_date || !$end_date) {
            throw new Exception("Item #".($index+1).": Missing required fields.");
        }
        if (!in_array($plan_type, ['hourly', 'daily', 'monthly'])) {
            throw new Exception("Item #".($index+1).": Invalid plan_type.");
        }
        
        if (($plan_type === 'hourly' || $plan_type === 'daily') && (date('w', strtotime($start_date)) == 0 || date('w', strtotime($end_date)) == 0)) {
            throw new Exception("Item #".($index+1).": Bookings cannot be made on Sundays.");
        }
        
        if ($start_time && strlen($start_time) === 5) $start_time .= ':00';
        if ($end_time && strlen($end_time) === 5) $end_time .= ':00';

        // --- B. CONFLICT CHECK ---
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
            throw new Exception("Item #".($index+1)." ($workspace_title) is already booked for this time.");
        }
        $stmt->close();

        // --- C. ID GENERATION ---
        $currentSequence++; 
        $nextNum = str_pad($currentSequence, 3, '0', STR_PAD_LEFT);
        $booking_id = "BKG-$today-$nextNum";
        
        $responseIds[] = $booking_id;
        $status = 'confirmed'; 

        // --- D. INSERT QUERY ---
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
            $booking_id, $user_id, $space_id, $seat_codes, $workspace_title, $plan_type,
            $start_date, $end_date, $start_time, $end_time,
            $total_days, $total_hours, $num_attendees,
            $price_per_unit, $base_amount, $gst_amount, $discount_amount, $final_amount,
            $coupon_code, $referral_source, $terms_accepted, $status
        );

        if (!$stmt->execute()) {
            throw new Exception("Database error on Item #".($index+1).": " . $stmt->error);
        }
        $stmt->close();
    }

    // 6. COMMIT TRANSACTION
    $conn->commit();
    $conn->close();

    echo json_encode([
        "success" => true,
        "message" => "All bookings confirmed successfully.",
        "booking_ids" => $responseIds
    ]);

} catch (Exception $e) {
    // 7. ROLLBACK
    if (isset($conn) && $conn instanceof mysqli && $conn->connect_errno == 0) {
        $conn->rollback();
        $conn->close();
    }
    
    // Set 401 status for auth errors, otherwise 400
    if (strpos($e->getMessage(), "Authorization") !== false || strpos($e->getMessage(), "token") !== false) {
        http_response_code(401);
    } else {
        http_response_code(400);
    }

    echo json_encode([
        "success" => false, 
        "message" => $e->getMessage()
    ]);
}
?>