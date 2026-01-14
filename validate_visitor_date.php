<?php
// -----------------------------------
// Load Environment & Centralized CORS
// -----------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// -----------------------------------
// JWT Verification
// -----------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

require_once 'db.php';

// -----------------------------------
// Read JSON input
// -----------------------------------
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

// ---------------- JWT CHECK ----------------
// Get JWT from HttpOnly cookie
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ---------------- INPUT ----------------
$user_id      = $data['user_id'] ?? null;
$booking_id   = $data['booking_id'] ?? null;
$visitingDate = $data['visitingDate'] ?? null;

if (!$user_id || !$booking_id || !$visitingDate) {
    echo json_encode([
        "success" => false,
        "message" => "user_id, booking_id and visitingDate are required"
    ]);
    exit;
}

// ---------------- SECURITY CHECK ----------------
if ((int)$user_id !== (int)$decoded_user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Identity mismatch"]);
    exit;
}

// ---------------- SUCCESS ----------------
echo json_encode([
    "success" => true,
    "message" => "Visiting date is valid"
]);

$conn->close();
