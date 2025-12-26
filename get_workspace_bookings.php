<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------
// CORS HEADERS
// ------------------
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ NEW: Define secret key
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

try {
    include "db.php";

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
        $decoded_user_id = $decoded->data->id; // Extract user ID from token
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // Read request body
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload.");
    }

    $user_id = (int)($data['user_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception("Invalid user_id.");
    }

    // ✅ NEW: SECURITY CHECK - Compare token ID with request ID
    if ((int)$decoded_user_id !== $user_id) {
        http_response_code(403);
        throw new Exception("Unauthorized: Identity mismatch.");
    }

    // Fetch bookings
    // 🟢 UPDATED SELECT: Added `wb.seat_codes` to the list
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
        // Format start_time and end_time as HH:MM AM/PM
        if (!empty($row['start_time'])) {
            $row['start_time'] = date("h:i A", strtotime($row['start_time']));
        }
        if (!empty($row['end_time'])) {
            $row['end_time'] = date("h:i A", strtotime($row['end_time']));
        }

        // Optionally format dates as well (e.g., Nov 27, 2025)
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
    // If status code hasn't been set by auth logic, default to 400
    if (http_response_code() == 200) {
        http_response_code(400);
    }
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>