<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
header("Access-Control-Allow-Credentials: true");

include "db.php"; // ✅ Use your actual DB file name

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login script
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; user context is available in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}
// ------------------------------------

// Read input
$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input['user_id']) || !isset($input['status']) || !isset($input['comment'])) {
    echo json_encode(["success" => false, "message" => "Invalid input"]);
    exit;
}

$user_id = intval($input['user_id']);
$status = trim($input['status']);
$comment = trim($input['comment']);
$follow_up_date = !empty($input['follow_up_date']) ? $input['follow_up_date'] : null;
$follow_up_time = !empty($input['follow_up_time']) ? $input['follow_up_time'] : null;

// ✅ 1. Insert into user_comments
$sql = "INSERT INTO user_comments (user_id, status, comment, follow_up_date, follow_up_time, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("issss", $user_id, $status, $comment, $follow_up_date, $follow_up_time);

if ($stmt->execute()) {
    // ✅ 2. Update user status in users table
    $update = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $update->bind_param("si", $status, $user_id);
    $update->execute();
    $update->close();

    echo json_encode(["success" => true, "message" => "Comment added & user status updated"]);
} else {
    echo json_encode(["success" => false, "message" => "Database error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>