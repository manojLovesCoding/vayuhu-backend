<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'];

// Get Authorization header (Checking both cases for server compatibility)
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401); // ✅ Set proper HTTP status
    echo json_encode(["status" => "error", "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token not found in header"]);
    exit;
}

$token = $matches[1];

try {
    // Verify and decode the token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data;

    // Secured response logic
    echo json_encode([
        "status" => "success",
        "message" => "Token is valid",
        "user" => $userData
    ]);
} catch (Exception $e) {
    http_response_code(401); // ✅ Set proper HTTP status for expired/invalid
    echo json_encode([
        "status" => "error", 
        "message" => "Invalid or expired token",
        "details" => $e->getMessage() // Optional: remove in production
    ]);
}
?>