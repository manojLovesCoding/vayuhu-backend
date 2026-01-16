<?php
// -------------------------
// Load Environment & CORS
// -------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// -------------------------
// Prevent PHP warnings from breaking JSON
// -------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// -------------------------
// ✅ Include JWT Library
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Use secret from .env
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// -------------------------
// ✅ Verify JWT Token from HttpOnly Cookie ONLY
// -------------------------
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

// -------------------------
// Database Connection
// -------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// -------------------------
// Upload Limits
// -------------------------
ini_set("upload_max_filesize", "5M");
ini_set("post_max_size", "10M");

// -------------------------
// Validate Request Method
// -------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

// -------------------------
// Required Fields
// -------------------------
$required = ["added_by", "blog_heading", "blog_description"];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || trim($_POST[$field]) === "") {
        echo json_encode(["success" => false, "message" => "$field is required"]);
        exit;
    }
}

// -------------------------
// Sanitize Inputs
// -------------------------
function val($key) {
    return isset($_POST[$key]) ? trim($_POST[$key]) : null;
}

$added_by = val("added_by");
$blog_heading = val("blog_heading");
$blog_description = val("blog_description");

// -------------------------
// Duplicate Blog Heading Check
// -------------------------
$chk = $conn->prepare("SELECT id FROM blogs WHERE blog_heading = ?");
$chk->bind_param("s", $blog_heading);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Blog heading already exists"]);
    exit;
}
$chk->close();

// -------------------------
// Image Upload Validation
// -------------------------
if (!isset($_FILES["blog_image"]) || $_FILES["blog_image"]["error"] !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "Blog image is required or upload failed."]);
    exit;
}

$fileTmp = $_FILES["blog_image"]["tmp_name"];
$originalName = $_FILES["blog_image"]["name"];
$fileSize = $_FILES["blog_image"]["size"];

// Basic size check (5MB)
$maxBytes = 5 * 1024 * 1024;
if ($fileSize > $maxBytes) {
    echo json_encode(["success" => false, "message" => "Image exceeds maximum allowed size of 5MB"]);
    exit;
}

// Determine MIME type
$mime = null;
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $fileTmp);
        if ($detected !== false) $mime = $detected;
        finfo_close($finfo);
    }
}

if (!$mime) {
    $gs = @getimagesize($fileTmp);
    if ($gs && isset($gs['mime'])) $mime = $gs['mime'];
}

if (!$mime) $mime = $_FILES["blog_image"]["type"] ?? null;

$allowed = [
    "image/jpeg", "image/pjpeg", "image/jpg",
    "image/png", "image/x-png",
    "image/webp",
    "image/gif",
    "image/avif"
];

if (!in_array(strtolower($mime), $allowed, true)) {
    echo json_encode([
        "success" => false,
        "message" => "Invalid image format: detected('$mime'). Allowed: jpeg/png/webp/gif."
    ]);
    exit;
}

// Ensure upload dir exists
$uploadDir = "uploads/blogs/";
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        echo json_encode(["success" => false, "message" => "Failed to create upload directory"]);
        exit;
    }
}

// Generate safe file name and move
$safeName = uniqid("blog_") . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $originalName);
$filePath = $uploadDir . $safeName;

if (!move_uploaded_file($fileTmp, $filePath)) {
    echo json_encode(["success" => false, "message" => "Image upload failed (move_uploaded_file)"]);
    exit;
}

// -------------------------
// Insert Blog
// -------------------------
$sql = "INSERT INTO blogs 
        (added_by, blog_heading, blog_description, blog_image, created_at)
        VALUES (?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssss",
    $added_by,
    $blog_heading,
    $blog_description,
    $filePath
);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Blog added successfully",
        "user" => $userData
    ]);
} else {
    echo json_encode(["success" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
