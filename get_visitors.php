<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true"); // ✅ Added for secure communication
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ NEW: Define secret key (Must match your other scripts)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

require_once 'db.php';

// ✅ Read JSON input
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid JSON input."]);
    exit;
}

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// ------------------------------------
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
    $decoded_user_id = $decoded->data->id; // Extract user ID from token
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

// ✅ NEW: SECURITY CHECK - Token ID must match Payload User ID
if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access: Identity mismatch."]);
    exit;
}

// ✅ Fetch visitors with company name joined
$sql = "
    SELECT 
        v.id,
        v.name,
        v.contact_no,
        v.email,
        v.visiting_date,
        v.visiting_time,
        v.reason,
        v.added_on,
        c.company_name
    FROM visitors v
    LEFT JOIN company_profile c ON v.company_id = c.id
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
        "visiting_date" => $row["visiting_date"],
        "visiting_time" => $row["visiting_time"],
        "reason"        => $row["reason"],
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