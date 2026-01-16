<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

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
// ✅ JWT VERIFICATION FROM COOKIE
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization token missing"]);
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
$id = $_GET['id'] ?? '';

if (empty($id)) {
    echo json_encode(["success" => false, "message" => "Missing contact ID."]);
    exit;
}

// ------------------------------------
// Fetch Contact
// ------------------------------------
$sql = "SELECT id, name, email, phone, status, comments, 
               DATE_FORMAT(created_at, '%d-%m-%Y %h:%i %p') AS created_at
        FROM contact_requests 
        WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Contact not found."]);
    exit;
}

$contact = $result->fetch_assoc();

echo json_encode(["success" => true, "contact" => $contact]);

$stmt->close();
$conn->close();
