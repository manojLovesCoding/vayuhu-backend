<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Handles CORS & OPTIONS requests

// ------------------------------------
// Include Database
// ------------------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// Secret Key from .env
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// JWT Verification from HttpOnly Cookie
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null; // <-- Changed to read from cookie

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "No session found. Please log in."]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Successfully verified. User ID from token can be accessed via $decoded->data->id
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired session. Please log in again."]);
    exit;
}

// ------------------------------------
// Prevent PHP warnings from breaking JSON
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// Validate Request Method
// ------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

// ------------------------------------
// Read Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["success" => false, "message" => "Invalid or missing JSON body"]);
    exit;
}

// ------------------------------------
// Required Fields
// ------------------------------------
$required = ["coupon_code", "workspace_title", "plan_type", "total_amount"];
foreach ($required as $r) {
    if (empty($input[$r])) {
        echo json_encode(["success" => false, "message" => "$r is required"]);
        exit;
    }
}

// ------------------------------------
// Sanitize Inputs
// ------------------------------------
function val($arr, $key) {
    return isset($arr[$key]) && $arr[$key] !== "" ? trim($arr[$key]) : null;
}

$coupon_code     = val($input, "coupon_code");
$user_id         = val($input, "user_id");
$workspace_title = val($input, "workspace_title");
$plan_type       = val($input, "plan_type");
$total_amount    = floatval(val($input, "total_amount"));

// ------------------------------------
// Fetch Coupon
// ------------------------------------
$stmt = $conn->prepare("SELECT * FROM coupons WHERE coupon_code = ?");
$stmt->bind_param("s", $coupon_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Invalid coupon code"]);
    exit;
}

$coupon = $result->fetch_assoc();
$stmt->close();

// ------------------------------------
// Validity Period Check
// ------------------------------------
$today = date("Y-m-d");
if ($today < $coupon["valid_from"] || $today > $coupon["valid_to"]) {
    echo json_encode(["success" => false, "message" => "Coupon is expired or not active today"]);
    exit;
}

// ------------------------------------
// Price Range Check
// ------------------------------------
$min_price = floatval($coupon["min_price"] ?? 0);
$max_price = floatval($coupon["max_price"] ?? 0);

if ($min_price > 0 && $total_amount < $min_price) {
    echo json_encode(["success" => false, "message" => "Booking amount below coupon limit"]);
    exit;
}
if ($max_price > 0 && $total_amount > $max_price) {
    echo json_encode(["success" => false, "message" => "Booking amount exceeds coupon limit"]);
    exit;
}

// ------------------------------------
// Space Type Check
// ------------------------------------
$space_type = trim($coupon["space_type"]);
if ($space_type !== "ALL Spaces" && stripos($workspace_title, $space_type) === false) {
    echo json_encode(["success" => false, "message" => "Coupon not valid for this workspace type"]);
    exit;
}

// ------------------------------------
// Normalize Plan/Pack Types
// ------------------------------------
function normalize_plan($value) {
    $v = strtolower(trim($value));
    $map = [
        "per hour" => "hourly",
        "hour" => "hourly",
        "hourly" => "hourly",
        "per day" => "daily",
        "day" => "daily",
        "daily" => "daily",
        "per month" => "monthly",
        "month" => "monthly",
        "monthly" => "monthly",
        "all spaces" => "all"
    ];
    return $map[$v] ?? $v;
}

$normalized_pack = normalize_plan($coupon["pack_type"]);
$normalized_plan = normalize_plan($plan_type);

// ------------------------------------
// Pack Type Check
// ------------------------------------
if ($normalized_pack !== "all" && $normalized_pack !== $normalized_plan) {
    echo json_encode(["success" => false, "message" => "Coupon not valid for this plan"]);
    exit;
}

// ------------------------------------
// User Type Validation (optional)
# Particular User (Email or Mobile)
if (trim($coupon["user_type"]) === "Particular User (Email)" && !empty($coupon["email"])) {
    $u_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    $user_email = $u_res->fetch_assoc()["email"] ?? "";
    $u_stmt->close();

    if (strcasecmp($user_email, $coupon["email"]) !== 0) {
        echo json_encode(["success" => false, "message" => "Coupon not valid for this email"]);
        exit;
    }
}

if (trim($coupon["user_type"]) === "Particular User (Mobile)" && !empty($coupon["mobile"])) {
    $u_stmt = $conn->prepare("SELECT mobile FROM users WHERE id = ?");
    $u_stmt->bind_param("i", $user_id);
    $u_stmt->execute();
    $u_res = $u_stmt->get_result();
    $user_mobile = $u_res->fetch_assoc()["mobile"] ?? "";
    $u_stmt->close();

    if (strcasecmp($user_mobile, $coupon["mobile"]) !== 0) {
        echo json_encode(["success" => false, "message" => "Coupon not valid for this mobile"]);
        exit;
    }
}

// ------------------------------------
// Discount Calculation
// ------------------------------------
$discount_percent = floatval($coupon["discount"]);
$discount_amount = round(($total_amount * $discount_percent) / 100, 2);
$new_total = round($total_amount - $discount_amount, 2);

// ------------------------------------
// Success Response
// ------------------------------------
echo json_encode([
    "success" => true,
    "message" => "Coupon applied successfully! You got {$discount_percent}% off.",
    "discount_percent" => $discount_percent,
    "discount_amount" => $discount_amount,
    "new_total" => $new_total
]);
exit;
?>
