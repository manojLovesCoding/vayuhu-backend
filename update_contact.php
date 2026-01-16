<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// ------------------------------------
// DATABASE CONNECTION
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// ✅ INCLUDE JWT LIBRARY
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// ✅ JWT SECRET FROM ENV
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// ✅ GET & VERIFY JWT FROM COOKIE
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null; // ✅ Read from HttpOnly cookie

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization token missing in cookies"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id; // Use if you want to validate user permissions
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Read JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["success" => false, "message" => "Invalid input data."]);
    exit;
}

// ------------------------------------
// Extract Input Fields
// ------------------------------------
$id = intval($input["id"] ?? 0);
$name = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$phone = trim($input["phone"] ?? "");
$status = trim($input["status"] ?? "Pending");

// ------------------------------------
// Validation
// ------------------------------------
if (empty($id) || empty($name) || empty($phone)) {
    echo json_encode(["success" => false, "message" => "Name, phone, and ID are required."]);
    exit;
}

// ------------------------------------
// Verify Contact Exists
// ------------------------------------
$checkSql = "SELECT id FROM contact_requests WHERE id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $id);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Contact not found."]);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// ------------------------------------
// Check for Duplicate Phone (excluding current contact)
// ------------------------------------
$dupSql = "SELECT id FROM contact_requests WHERE phone = ? AND id != ?";
$dupStmt = $conn->prepare($dupSql);
$dupStmt->bind_param("si", $phone, $id);
$dupStmt->execute();
$dupStmt->store_result();

if ($dupStmt->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Another contact already has this phone number."]);
    $dupStmt->close();
    $conn->close();
    exit;
}
$dupStmt->close();

// ------------------------------------
// Update Contact Details
// ------------------------------------
$sql = "UPDATE contact_requests 
        SET name = ?, email = ?, phone = ?, status = ?, updated_at = NOW() 
        WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssssi", $name, $email, $phone, $status, $id);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Contact updated successfully."]);
} else {
    echo json_encode(["success" => false, "message" => "Database error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
