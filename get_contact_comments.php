<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

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

// ------------------------------------
// JWT VERIFICATION LOGIC
// ------------------------------------
// ✅ Retrieve token from HttpOnly cookie instead of Authorization header
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization cookie missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Include Database
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// Get Contact ID
// ------------------------------------
$contact_id = $_GET['contact_id'] ?? '';

if (empty($contact_id)) {
    echo json_encode(["success" => false, "message" => "Missing contact ID."]);
    exit;
}

// ------------------------------------
// Fetch Comments
// ------------------------------------
$sql = "SELECT id, status, comment, 
                DATE_FORMAT(created_at, '%d-%m-%Y %h:%i %p') AS created_at 
        FROM contact_comments 
        WHERE contact_id = ? 
        ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $contact_id);
$stmt->execute();
$result = $stmt->get_result();

$comments = [];
while ($row = $result->fetch_assoc()) {
    $comments[] = $row;
}

echo json_encode(["success" => true, "comments" => $comments]);

$stmt->close();
$conn->close();
