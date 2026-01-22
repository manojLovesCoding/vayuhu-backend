<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Error Handling
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// JWT VERIFICATION
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ✅ Get token ONLY from HttpOnly Cookie
//$token = $_COOKIE['auth_token'] ?? null;

{/*if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}*/}

// ------------------------------------
// Database Connection
// ------------------------------------
require_once "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Fetch Blogs
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
    $row["blog_image"] = !empty($row["blog_image"]) ? $row["blog_image"] : null;
    $row["created_at"] = $row["created_at"] ?? null;
    $row["updated_at"] = $row["updated_at"] ?? null;
    $blogs[] = $row;
}

// ------------------------------------
// Auto-Rotate Blog Order Daily
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
// Response
// ------------------------------------
echo json_encode([
    "success" => true,
    "total" => count($blogs),
    "data" => $blogs
], JSON_UNESCAPED_SLASHES);

$conn->close();
?>
