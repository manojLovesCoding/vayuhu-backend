<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'];

// ✅ Get Authorization header safely
$authHeader = null;

// Try getallheaders()
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
}

// Fallback to $_SERVER (works on Apache, Nginx, PHP-FPM)
if (!$authHeader) {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
}

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authorization header missing"]);
    exit;
}

// Extract Bearer token
if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token not found in header"]);
    exit;
}

$token = $matches[1];

try {
    // Verify and decode JWT
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data;

    echo json_encode([
        "status" => "success",
        "message" => "Token is valid",
        "user" => $userData
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or expired token",
        "details" => $e->getMessage() // Optional: remove in production
    ]);
}
