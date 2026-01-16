<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Prevent PHP warnings from breaking JSON
// ------------------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ------------------------------------
// Database
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT Secret
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// JWT Verification from HttpOnly Cookie
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authentication cookie missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data;
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
// Extract Form Data
// ------------------------------------
$name  = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$phone = trim($input["phone"] ?? "");

// ------------------------------------
// Validation
// ------------------------------------
if (empty($name) || empty($phone)) {
    echo json_encode(["status" => "error", "message" => "Name and phone number are required."]);
    exit;
}

// ------------------------------------
// Check for Duplicate Phone
// ------------------------------------
$checkSql = "SELECT id FROM contact_requests WHERE phone = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("s", $phone);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Contact with this phone already exists."]);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// ------------------------------------
// Insert Contact Request
// ------------------------------------
$status = "Pending";
$sql = "INSERT INTO contact_requests (name, email, phone, status, created_at) VALUES (?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssss", $name, $email, $phone, $status);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Contact request added successfully!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
