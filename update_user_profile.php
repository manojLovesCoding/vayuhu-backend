<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// ------------------------------------
// Database connection
// ------------------------------------
include "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// ✅ Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// ✅ JWT SECRET FROM ENV
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// ✅ JWT Verification from HttpOnly Cookie
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication token missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id; // Extract user ID from token
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Validate POST
// ------------------------------------
if (!isset($_POST['id'])) {
    echo json_encode(["success" => false, "message" => "User ID missing"]);
    exit;
}

$id = intval($_POST['id']);

// ✅ SECURITY CHECK - Ensure token owner matches ID
if ((int)$decoded_user_id !== $id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized: You cannot update this profile"]);
    exit;
}

$name    = $_POST['name'] ?? '';
$phone   = $_POST['phone'] ?? '';
$dob     = $_POST['dob'] ?? '';
$address = $_POST['address'] ?? '';

// Validate required fields
if (empty($id) || empty($name) || empty($phone)) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

// ------------------------------------
// Handle profile picture upload
// ------------------------------------
$profile_pic_path = null;
if (isset($_FILES['profilePic']) && $_FILES['profilePic']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = __DIR__ . "/uploads/profile_pics/";
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $file_tmp = $_FILES['profilePic']['tmp_name'];
    $file_name = uniqid("user_") . "_" . basename($_FILES['profilePic']['name']);
    $target_path = $upload_dir . $file_name;

    if (move_uploaded_file($file_tmp, $target_path)) {
        $profile_pic_path = "uploads/profile_pics/" . $file_name;
    }
}

// ------------------------------------
// Build and execute update query
// ------------------------------------
if ($profile_pic_path) {
    $sql = "UPDATE users 
            SET name=?, phone=?, dob=?, address=?, profile_pic=? 
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssi", $name, $phone, $dob, $address, $profile_pic_path, $id);
} else {
    $sql = "UPDATE users 
            SET name=?, phone=?, dob=?, address=? 
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssi", $name, $phone, $dob, $address, $id);
}

// Execute update
if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Profile updated successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update profile"]);
}

$stmt->close();
$conn->close();
?>
