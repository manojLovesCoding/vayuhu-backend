<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Centralized CORS headers & OPTIONS handling

// ------------------------------------
// Database Connection
// ------------------------------------
include "db.php"; // ✅ Use your actual DB file name
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// JWT Verification (from HttpOnly cookie)
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

try {
    // ✅ Read token from HttpOnly cookie
    $token = $_COOKIE['auth_token'] ?? null;
    if (!$token) {
        http_response_code(401);
        throw new Exception("Authorization token missing in cookies.");
    }

    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data; // User info
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}

// ------------------------------------
// Read Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

if (
    !$input ||
    !isset($input['user_id']) ||
    !isset($input['status']) ||
    !isset($input['comment'])
) {
    echo json_encode(["success" => false, "message" => "Invalid input"]);
    exit;
}

$user_id = intval($input['user_id']);
$status = trim($input['status']);
$comment = trim($input['comment']);
$follow_up_date = !empty($input['follow_up_date']) ? $input['follow_up_date'] : null;
$follow_up_time = !empty($input['follow_up_time']) ? $input['follow_up_time'] : null;

// ------------------------------------
// Insert into user_comments
// ------------------------------------
$sql = "INSERT INTO user_comments (user_id, status, comment, follow_up_date, follow_up_time, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(["success" => false, "message" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("issss", $user_id, $status, $comment, $follow_up_date, $follow_up_time);

if ($stmt->execute()) {
    // ------------------------------------
    // Update user status in users table
    // ------------------------------------
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
