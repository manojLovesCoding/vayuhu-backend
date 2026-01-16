<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';  // Handles CORS & OPTIONS

// ------------------------------------
// Prevent PHP Warnings from breaking JSON
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT Verification from HttpOnly Cookie
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// Look for JWT in HttpOnly cookie "adminToken"
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication cookie missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data; // verified user info
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Database Connection
// ------------------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Allow big uploads (if needed in future)
// ------------------------------------
ini_set("upload_max_filesize", "5M");
ini_set("post_max_size", "10M");

// ------------------------------------
// Validate POST Method
// ------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

// ------------------------------------
// Required Fields
// ------------------------------------
$required = ["coupon_code", "valid_from", "valid_to", "discount", "user_type", "space_type", "pack_type"];
foreach ($required as $r) {
    if (empty($_POST[$r])) {
        echo json_encode(["success" => false, "message" => "$r is required"]);
        exit;
    }
}

// ------------------------------------
// Sanitize Inputs
// ------------------------------------
function val($key) {
    return isset($_POST[$key]) && $_POST[$key] !== "" ? trim($_POST[$key]) : null;
}

$coupon_code = val("coupon_code");
$valid_from  = val("valid_from");
$valid_to    = val("valid_to");
$user_type   = val("user_type");
$space_type  = val("space_type");
$discount    = val("discount");
$min_price   = val("min_price");
$max_price   = val("max_price");
$pack_type   = val("pack_type");
$email       = val("email");
$mobile      = val("mobile");

// ------------------------------------
// Duplicate Check
// ------------------------------------
$chk = $conn->prepare("SELECT id FROM coupons WHERE coupon_code = ?");
$chk->bind_param("s", $coupon_code);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Coupon code already exists"]);
    exit;
}
$chk->close();

// ------------------------------------
// Insert Coupon
// ------------------------------------
$sql = "INSERT INTO coupons 
        (coupon_code, valid_from, valid_to, user_type, space_type, discount, min_price, max_price, pack_type, email, mobile, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sssssdddsss",
    $coupon_code,
    $valid_from,
    $valid_to,
    $user_type,
    $space_type,
    $discount,
    $min_price,
    $max_price,
    $pack_type,
    $email,
    $mobile
);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Coupon added successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
