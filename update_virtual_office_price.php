<?php
// -----------------------------------
// CORS + Headers
// -----------------------------------
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// -----------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// -----------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login script
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; you can access $decoded->data if needed
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
    exit;
}

// -----------------------------------
// DB Connection
// -----------------------------------
include "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

if (
    empty($data['id']) ||
    empty($data['min_duration']) ||
    empty($data['max_duration']) ||
    empty($data['price']) ||
    empty($data['status'])
) {
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit;
}

$id = $conn->real_escape_string($data['id']);
$min_duration = $conn->real_escape_string($data['min_duration']);
$max_duration = $conn->real_escape_string($data['max_duration']);
$price = $conn->real_escape_string($data['price']);
$status = $conn->real_escape_string($data['status']);

$sql = "UPDATE virtualoffice_prices 
        SET min_duration='$min_duration', 
            max_duration='$max_duration', 
            price='$price', 
            status='$status'
        WHERE id='$id'";

if ($conn->query($sql)) {
    echo json_encode(["status" => "success", "message" => "Record updated successfully."]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update record."]);
}

$conn->close();
?>