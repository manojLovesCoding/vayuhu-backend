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
// Prevent PHP Warnings from breaking JSON
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

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
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Database
// ------------------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Fetch Coupons
// ------------------------------------
$sql = "SELECT id, coupon_code, valid_from, valid_to, user_type, space_type, discount, min_price, max_price, pack_type, email, mobile
        FROM coupons
        ORDER BY created_at DESC";

$result = $conn->query($sql);

$coupons = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $coupons[] = $row;
    }
    echo json_encode(["success" => true, "coupons" => $coupons]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to fetch coupons"]);
}

$conn->close();
