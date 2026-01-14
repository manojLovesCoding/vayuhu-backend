<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Database Connection
// ------------------------------------
include "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// JWT VERIFICATION FROM COOKIE
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// Get token from cookie
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized: No token found."]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id; // Extract user ID from token
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized: Invalid or expired token."]);
    exit;
}

// ------------------------------------
// FETCH PROFILE LOGIC
// ------------------------------------
$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID is required."]);
    exit;
}

// Security check - Ensure token owner matches requested profile
if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access to this profile."]);
    exit;
}

// Fetch company profile
$sql = "SELECT * FROM company_profile WHERE user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $profile = $result->fetch_assoc();

    if ($profile['logo']) {
        $profile['logo'] = "http://localhost/vayuhuBackend/" . $profile['logo'];
    }

    echo json_encode(["success" => true, "profile" => $profile]);
} else {
    echo json_encode(["success" => false, "message" => "No company profile found."]);
}

$stmt->close();
$conn->close();
