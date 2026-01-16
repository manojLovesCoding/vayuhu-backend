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
// JWT VERIFICATION LOGIC (HttpOnly Cookie)
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token cookie missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data; // optional: user info
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Include Database Connection
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// Fetch All Contacts
// ------------------------------------
$sql = "SELECT id, name, email, phone, status, comments, 
               DATE_FORMAT(created_at, '%d-%m-%Y %h:%i %p') AS date 
        FROM contact_requests 
        ORDER BY created_at DESC";

$result = $conn->query($sql);

$contacts = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $contacts[] = $row;
    }
}

// ------------------------------------
// Send JSON Response
// ------------------------------------
echo json_encode($contacts);

$conn->close();
?>
