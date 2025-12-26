<?php
// ------------------------------------
// CORS CONFIG
// ------------------------------------
$allowed_origin = "http://localhost:5173";

header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// ERROR HANDLING
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// ✅ JWT VERIFICATION (ADDED)
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match login.php

// Get Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    // Decode and verify the token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid, we can proceed. User data is in $decoded->data
} catch (Exception $e) {
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
// FETCH BLOGS
// ------------------------------------
$sql = "SELECT 
            id, 
            added_by, 
            blog_heading, 
            blog_description, 
            blog_image, 
            status,
            created_at,
            updated_at
        FROM blogs
        ORDER BY id DESC";

$result = $conn->query($sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "SQL Error: " . $conn->error
    ]);
    exit;
}

$blogs = [];

while ($row = $result->fetch_assoc()) {
    // Convert empty image to null
    $row["blog_image"] = !empty($row["blog_image"]) ? $row["blog_image"] : null;

    // Format dates (optional)
    $row["created_at"] = $row["created_at"] ?? null;
    $row["updated_at"] = $row["updated_at"] ?? null;

    $blogs[] = $row;
}

// ------------------------------------
// AUTO-ROTATE BLOG ORDER DAILY
// ------------------------------------
$totalBlogs = count($blogs);

if ($totalBlogs > 0) {
    $dayOfYear = date('z'); 
    $shift = $dayOfYear % $totalBlogs;

    $blogs = array_merge(
        array_slice($blogs, -$shift),
        array_slice($blogs, 0, -$shift)
    );
}

// ------------------------------------
// RESPONSE
// ------------------------------------
echo json_encode([
    "success" => true,
    "total" => count($blogs),
    "data" => $blogs
], JSON_UNESCAPED_SLASHES);

$conn->close();
?>