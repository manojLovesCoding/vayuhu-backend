<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Include Database Connection
// ------------------------------------
include "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT Secret
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

try {
    // ------------------------------------
    // ✅ JWT VERIFICATION FROM COOKIE
    // ------------------------------------
    if (!isset($_COOKIE['auth_token'])) {
        http_response_code(401);
        throw new Exception("Authentication token missing.");
    }

    $token = $_COOKIE['auth_token'];

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        $decoded_user_id = $decoded->data->id; // Extract user ID from token
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // ------------------------------------
    // Read Request Body
    // ------------------------------------
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload.");
    }

    $user_id = (int)($data['user_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception("Invalid user_id.");
    }

    // ✅ SECURITY CHECK - Compare token ID with request ID
    if ((int)$decoded_user_id !== $user_id) {
        http_response_code(403);
        throw new Exception("Unauthorized: Identity mismatch.");
    }

    // ------------------------------------
    // Fetch Workspace Bookings
    // ------------------------------------
    $stmt = $conn->prepare("
        SELECT 
            wb.booking_id,
            wb.space_id,
            s.space_code,
            wb.seat_codes, 
            wb.workspace_title,
            wb.plan_type,
            wb.start_date,
            wb.end_date,
            wb.start_time,
            wb.end_time,
            wb.total_days,
            wb.total_hours,
            wb.num_attendees,
            wb.price_per_unit,
            wb.base_amount,
            wb.gst_amount,
            wb.discount_amount,
            wb.final_amount,
            wb.coupon_code,
            wb.referral_source,
            wb.terms_accepted,
            wb.status,
            wb.created_at
        FROM workspace_bookings wb
        JOIN spaces s ON wb.space_id = s.id
        WHERE wb.user_id = ?
        ORDER BY wb.created_at DESC
    ");

    if (!$stmt) {
        throw new Exception("Prepare failed.");
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['start_time'])) {
            $row['start_time'] = date("h:i A", strtotime($row['start_time']));
        }
        if (!empty($row['end_time'])) {
            $row['end_time'] = date("h:i A", strtotime($row['end_time']));
        }

        $row['start_date'] = date("M d, Y", strtotime($row['start_date']));
        $row['end_date']   = date("M d, Y", strtotime($row['end_date']));

        $bookings[] = $row;
    }

    echo json_encode([
        "success" => true,
        "bookings" => $bookings
    ]);

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    if (http_response_code() == 200) {
        http_response_code(400);
    }
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
