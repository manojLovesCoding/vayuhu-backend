<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// ERROR HANDLING
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// INCLUDE JWT LIBRARY
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT SECRET FROM ENV
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// VERIFY JWT TOKEN (HttpOnly Cookie)
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// DATABASE CONNECTION
// ------------------------------------
require_once "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// VALIDATE INPUT
// ------------------------------------
$id = $_POST['id'] ?? null;
$blog_heading = $_POST['blog_heading'] ?? '';
$blog_description = $_POST['blog_description'] ?? '';
$status = $_POST['status'] ?? 'Active';

if (!$id) {
    echo json_encode(["success" => false, "message" => "Blog ID is required"]);
    exit;
}

if (!$blog_heading || !$blog_description) {
    echo json_encode(["success" => false, "message" => "Heading and Description are required"]);
    exit;
}

// ------------------------------------
// HANDLE IMAGE UPLOAD
// ------------------------------------
$blog_image = null;
if (isset($_FILES['blog_image']) && $_FILES['blog_image']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['blog_image']['tmp_name'];
    $fileName = basename($_FILES['blog_image']['name']);
    $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array(strtolower($fileExt), $allowedExts)) {
        echo json_encode(["success" => false, "message" => "Invalid image type"]);
        exit;
    }

    $uploadDir = "uploads/blogs/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $newFileName = "blog_" . time() . "." . $fileExt;
    $destPath = $uploadDir . $newFileName;

    if (move_uploaded_file($fileTmpPath, $destPath)) {
        $blog_image = $destPath;
    } else {
        echo json_encode(["success" => false, "message" => "Failed to upload image"]);
        exit;
    }
}

// ------------------------------------
// UPDATE BLOG
// ------------------------------------
$blog_image_sql = $blog_image ? ", blog_image = '" . $conn->real_escape_string($blog_image) . "'" : "";

$sql = "UPDATE blogs SET 
            blog_heading = '" . $conn->real_escape_string($blog_heading) . "',
            blog_description = '" . $conn->real_escape_string($blog_description) . "',
            status = '" . $conn->real_escape_string($status) . "',
            updated_at = NOW()
            $blog_image_sql
        WHERE id = " . intval($id);

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Blog updated successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "SQL Error: " . $conn->error]);
}

$conn->close();
