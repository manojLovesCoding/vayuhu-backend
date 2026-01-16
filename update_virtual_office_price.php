<?php
// -----------------------------------
// Load Environment & Centralized CORS
// -----------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// -----------------------------------
// ✅ JWT VERIFICATION (HttpOnly Cookie)
// -----------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ✅ Read token from HttpOnly cookie instead of Authorization header
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authentication required"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Access token data: $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
    exit;
}

// -----------------------------------
// DB Connection
// -----------------------------------
include "db.php";

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// -----------------------------------
// Read JSON Input
// -----------------------------------
$data = json_decode(file_get_contents("php://input"), true);

if (
    empty($data['id']) ||
    empty($data['min_duration']) ||
    empty($data['max_duration']) ||
    empty($data['price']) ||
    empty($data['gst']) ||
    empty($data['status'])
) {
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit;
}

$id           = $conn->real_escape_string($data['id']);
$min_duration = $conn->real_escape_string($data['min_duration']);
$max_duration = $conn->real_escape_string($data['max_duration']);
$price        = $conn->real_escape_string($data['price']);
$gst          = $conn->real_escape_string($data['gst']);
$status       = $conn->real_escape_string($data['status']);

// -----------------------------------
// ✅ GST Validation
// -----------------------------------
if ($gst < 0 || $gst > 28) {
    echo json_encode([
        "status" => "error",
        "message" => "GST must be between 0 and 28"
    ]);
    exit;
}

// -----------------------------------
// Update Query
// -----------------------------------
$sql = "UPDATE virtualoffice_prices 
        SET min_duration='$min_duration', 
            max_duration='$max_duration', 
            price='$price',
            gst='$gst',
            status='$status'
        WHERE id='$id'";

if ($conn->query($sql)) {
    echo json_encode(["status" => "success", "message" => "Record updated successfully."]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update record."]);
}

$conn->close();
