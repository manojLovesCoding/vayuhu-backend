<?php
// ------------------------------------
// CORS Configuration
// ------------------------------------
$allowed_origin = "http://localhost:5173"; // Update when deployed
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login script)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// ------------------------------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Successfully verified. User data is in $decoded->data
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
?>