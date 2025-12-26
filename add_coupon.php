<?php
// -------------------------
// CORS
// -------------------------
$allowed_origin = "http://localhost:5173";
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization"); 
header("Access-Control-Allow-Credentials: true");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

// -------------------------
// Prevent PHP Warnings from breaking JSON
// -------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// -------------------------
// ✅ Include JWT Library
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login/signup scripts)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// -------------------------
// ✅ JWT VERIFICATION (ADDED)
// -------------------------
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
    // Successfully verified. User info is in $decoded->data if needed.
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// -------------------------
// Database
// -------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// -------------------------
// Allow big uploads (if needed in future)
// -------------------------
ini_set("upload_max_filesize", "5M");
ini_set("post_max_size", "10M");

// -------------------------
// Validate POST
// -------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

// -------------------------
// Required Fields
// -------------------------
$required = ["coupon_code", "valid_from", "valid_to", "discount", "user_type", "space_type", "pack_type"];
foreach ($required as $r) {
    if (empty($_POST[$r])) {
        echo json_encode(["success" => false, "message" => "$r is required"]);
        exit;
    }
}

// -------------------------
// Sanitize Inputs
// -------------------------
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

// -------------------------
// Duplicate Check
// -------------------------
$chk = $conn->prepare("SELECT id FROM coupons WHERE coupon_code = ?");
$chk->bind_param("s", $coupon_code);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Coupon code already exists"]);
    exit;
}
$chk->close();

// -------------------------
// Insert Query
// -------------------------
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
?>