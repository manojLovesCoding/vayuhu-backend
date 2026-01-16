<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads env vars (JWT_SECRET)
require_once __DIR__ . '/config/cors.php';  // centralized CORS + OPTIONS handling

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json");

// ------------------------------------
// Database
// ------------------------------------
include "db.php";

// ------------------------------------
// JWT VERIFICATION LOGIC
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ JWT secret from environment
$secret_key = $_ENV['JWT_SECRET'];

try {
    // ✅ Read token from HttpOnly cookie
    $token = $_COOKIE['auth_token'] ?? null;
    if (!$token) {
        http_response_code(401);
        throw new Exception("Authorization token missing in cookies.");
    }

    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; user data available in $decoded->data if needed
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}

// ------------------------------------
// Business Logic (UNCHANGED)
// ------------------------------------
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid user ID"]);
    exit;
}

$sql = "SELECT id, user_id, status, comment, 
               COALESCE(follow_up_date, '-') AS follow_up_date, 
               COALESCE(follow_up_time, '-') AS follow_up_time, 
               DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
        FROM user_comments
        WHERE user_id = ?
        ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$comments = [];
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
}

echo json_encode(["success" => true, "comments" => $comments]);

$stmt->close();
$conn->close();
