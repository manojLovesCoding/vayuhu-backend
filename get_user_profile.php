<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
// ✅ Added Authorization to Allowed Headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

include "db.php";

// ✅ NEW: Include JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ NEW: Define secret key
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
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

$baseURL = "http://localhost/vayuhuBackend"; // change if folder differs

// ✅ Get user id from query string
if (!isset($_GET['id'])) {
    echo json_encode(["success" => false, "message" => "User ID missing"]);
    exit;
}

$id = intval($_GET['id']);

// ✅ NEW: Security Check - Ensure user can only access their own profile
if ((int)$decoded_user_id !== $id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access to this profile"]);
    exit;
}

$sql = "SELECT id, name, email, phone, dob, address, profile_pic FROM users WHERE id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $row = $result->fetch_assoc()) {
    foreach ($row as $key => $value) {
        $row[$key] = $value ?? "";
    }

    if (!empty($row['profile_pic'])) {
        $row['profile_pic'] = $baseURL . '/' . $row['profile_pic'];
    }

    echo json_encode(["success" => true, "user" => $row]);
} else {
    echo json_encode(["success" => false, "message" => "User not found"]);
}

$stmt->close();
$conn->close();
?>