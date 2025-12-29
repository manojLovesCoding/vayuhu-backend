<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization missing"]);
    exit;
}

$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

$user_id = $data['user_id'] ?? null;
if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Identity mismatch"]);
    exit;
}

// ✅ UPDATED SQL: Joins with the 'spaces' table to get the HOURLY rate 
// specifically for visitor passes, regardless of the host's plan.
$sql = "
    SELECT 
        wb.booking_id,
        wb.workspace_title,
        wb.start_date,
        wb.end_date,
        s.per_hour as hourly_guest_rate -- 🟢 Fetch hourly rate from master table
    FROM workspace_bookings wb
    JOIN spaces s ON wb.space_id = s.id -- Join to get space details
    WHERE wb.user_id = ? AND wb.end_date >= CURDATE()
    ORDER BY wb.start_date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$bookings = [];
while ($row = $result->fetch_assoc()) {
    // ✅ NEW: Override the price with the hourly rate for visitors
    // This ensures even monthly users (₹4500) only charge guests the hourly rate (₹100)
    $row['price_per_unit'] = $row['hourly_guest_rate']; 

    // ✅ Store RAW dates for frontend validation
    $row['start_date_raw'] = $row['start_date']; 
    
    // Format for display
    $row['start_date_display'] = date("M d, Y", strtotime($row['start_date']));
    
    $bookings[] = $row;
}

echo json_encode(["success" => true, "bookings" => $bookings]);

$stmt->close();
$conn->close();
?>