<?php
// ------------------------------------
// Load Environment Variables & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Database Connection
// ------------------------------------
require_once 'db.php';
if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// JWT Verification from HttpOnly Cookie
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ✅ Read token from HttpOnly cookie instead of Authorization header
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authorization token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data; // User info from token if needed
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Read JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid input data."]);
    exit;
}

// ------------------------------------
// Extract form data
// ------------------------------------
$name  = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$phone = trim($input["phone"] ?? "");

// ------------------------------------
// Validation
// ------------------------------------
if (empty($name) || empty($phone)) {
    echo json_encode(["status" => "error", "message" => "Name and phone are required."]);
    exit;
}

// ------------------------------------
// Check duplicate phone
// ------------------------------------
$checkSql = "SELECT id FROM users WHERE phone = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("s", $phone);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "User with this phone already exists."]);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// ------------------------------------
// Insert new user
// ------------------------------------
$sql = "INSERT INTO users (name, email, phone, created_at) VALUES (?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $name, $email, $phone);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "User added successfully!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
