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

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid JSON input."]);
    exit;
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id; 
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

$user_id = $data['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access: Identity mismatch."]);
    exit;
}

// ✅ FIXED SELECT: Join specifically on booking_id to prevent duplicates
$sql = "
    SELECT DISTINCT
        v.id,
        v.name,
        v.contact_no,
        v.email,
        v.visiting_date,
        v.visiting_time,
        v.reason,
        v.payment_id,
        v.amount_paid,
        v.added_on,
        c.company_name,
        wb.workspace_title as host_workspace
    FROM visitors v
    LEFT JOIN company_profile c ON v.company_id = c.id
    -- 🟢 CRITICAL: Link only to the specific booking ID recorded for this visitor
    LEFT JOIN workspace_bookings wb ON v.booking_id = wb.booking_id
    WHERE v.user_id = ?
    ORDER BY v.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$visitors = [];
while ($row = $result->fetch_assoc()) {
    $visitors[] = [
        "id"            => $row["id"],
        "name"          => $row["name"],
        "contact"       => $row["contact_no"],
        "email"         => $row["email"],
        "company_name"  => $row["company_name"] ?: "—",
        "workspace"     => $row["host_workspace"] ?: "Manual Entry",
        "visiting_date" => $row["visiting_date"],
        "visiting_time" => $row["visiting_time"],
        "reason"        => $row["reason"],
        "payment_id"    => $row["payment_id"],    
        "amount_paid"   => $row["amount_paid"],   
        "added_on"      => $row["added_on"]
    ];
}

if (count($visitors) > 0) {
    echo json_encode(["success" => true, "visitors" => $visitors]);
} else {
    echo json_encode(["success" => false, "message" => "No visitors found"]);
}

$stmt->close();
$conn->close();
?>