<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

include "db.php";

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

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

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
    $decoded_user_id = $decoded->data->id; // Extract user ID from token
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

$baseURL = "http://localhost/vayuhuBackend"; // change if folder differs

// ------------------------------------
// Get user id from query string
// ------------------------------------
if (!isset($_GET['id'])) {
    echo json_encode(["success" => false, "message" => "User ID missing"]);
    exit;
}

$id = intval($_GET['id']);

// ------------------------------------
// Security Check - Ensure user can only access their own profile
// ------------------------------------
if ((int)$decoded_user_id !== $id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access to this profile"]);
    exit;
}

// ------------------------------------
// Fetch user data
// ------------------------------------
$sql = "SELECT id, name, email, phone, dob, address, profile_pic FROM users WHERE id = ? LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $row = $result->fetch_assoc()) {
    foreach ($row as $key => $value) {
        $row[$key] = $value ?? "";
    }

    if (!empty($row['profile_pic'])) {
        $row['profile_pic'] = $baseURL . '/' . $row['profile_pic'];
    }

    echo json_encode(["success" => true, "user" => $row]);
} else {
    echo json_encode(["success" => false, "message" => "User not found"]);
}

$stmt->close();
$conn->close();
