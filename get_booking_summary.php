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

// ✅ Load JWT Libraries
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your other scripts

try {
    include "db.php";

    // ------------------------------------
    // ✅ JWT VERIFICATION (ADDED)
    // ------------------------------------
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        throw new Exception("Authorization header missing.");
    }

    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        $decoded_user_id = $decoded->data->id; // ID from the secure token
    } catch (Exception $e) {
        http_response_code(401); // Unauthorized
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

    // ✅ SECURITY CHECK: Ensure token ID matches requested user_id
    if ($decoded_user_id !== $user_id) {
        http_response_code(403); // Forbidden
        throw new Exception("Unauthorized access to this dashboard.");
    }

    // -------------------------------
    // Fetch all bookings for the user
    // -------------------------------
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
            wb.status,
            wb.created_at
        FROM workspace_bookings wb
        JOIN spaces s ON wb.space_id = s.id
        WHERE wb.user_id = ?
        ORDER BY wb.created_at DESC
    ");
    
    if (!$stmt) {
        throw new Exception("Database prepare() failed.");
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $bookings = [];
    $today = date("Y-m-d");
    $summary = [
        "total" => 0,
        "ongoing" => 0,
        "completed" => 0,
        "upcoming" => 0
    ];

    while ($row = $result->fetch_assoc()) {
        // Format times
        if (!empty($row['start_time'])) {
            $row['start_time'] = date("h:i A", strtotime($row['start_time']));
        }
        if (!empty($row['end_time'])) {
            $row['end_time'] = date("h:i A", strtotime($row['end_time']));
        }

        // Format dates
        $row['start_date'] = date("Y-m-d", strtotime($row['start_date']));
        $row['end_date']   = date("Y-m-d", strtotime($row['end_date']));

        $bookings[] = $row;
        $summary["total"]++;

        // Categorize bookings
        if ($row['start_date'] <= $today && $row['end_date'] >= $today) {
            $summary["ongoing"]++;
        } elseif ($row['end_date'] < $today) {
            $summary["completed"]++;
        } elseif ($row['start_date'] > $today) {
            $summary["upcoming"]++;
        }
    }

    echo json_encode([
        "success" => true,
        "summary" => $summary,
        "bookings" => $bookings
    ]);

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    // Note: http_response_code might already be set in nested catches
    if (http_response_code() == 200) http_response_code(400); 
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>