<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Centralized CORS headers & OPTIONS handling

// ------------------------------------
// Prevent PHP warnings from breaking JSON
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
// JWT Verification via HttpOnly Cookie
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ✅ Read JWT from HttpOnly cookie instead of Authorization header
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication token missing"]);
    exit;
}

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
$required = ["space_code", "space", "status"];
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

$space_code      = val("space_code");
$space           = val("space");
$per_hour        = val("per_hour");
$per_day         = val("per_day");
$one_week        = val("one_week");
$two_weeks       = val("two_weeks");
$three_weeks     = val("three_weeks");
$per_month       = val("per_month");
$min_duration    = val("min_duration");
$min_duration_desc = val("min_duration_desc");
$max_duration    = val("max_duration");
$max_duration_desc = val("max_duration_desc");
$status          = val("status") ?? "Active";

// ------------------------------------
// Duplicate Check
// ------------------------------------
$chk = $conn->prepare("SELECT id FROM spaces WHERE space_code = ?");
$chk->bind_param("s", $space_code);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Space code already exists"]);
    exit;
}
$chk->close();

// ------------------------------------
// Image Upload
// ------------------------------------
if (!isset($_FILES["image"]) || $_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "Image is required"]);
    exit;
}

$uploadDir = "uploads/";
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$fileTmp = $_FILES["image"]["tmp_name"];
$fileName = uniqid("space_") . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $_FILES["image"]["name"]);
$filePath = $uploadDir . $fileName;

$allowed = ["image/jpeg", "image/png", "image/webp", "image/jpg"];
$mime = mime_content_type($fileTmp);

if (!in_array($mime, $allowed)) {
    echo json_encode(["success" => false, "message" => "Invalid image format"]);
    exit;
}

if (!move_uploaded_file($fileTmp, $filePath)) {
    echo json_encode(["success" => false, "message" => "Image upload failed"]);
    exit;
}

// ------------------------------------
// Insert Query
// ------------------------------------
$sql = "INSERT INTO spaces 
        (space_code, space, per_hour, per_day, one_week, two_weeks, three_weeks, per_month,
         min_duration, min_duration_desc, max_duration, max_duration_desc, image, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssdddddddsdsss",
    $space_code,
    $space,
    $per_hour,
    $per_day,
    $one_week,
    $two_weeks,
    $three_weeks,
    $per_month,
    $min_duration,
    $min_duration_desc,
    $max_duration,
    $max_duration_desc,
    $filePath,
    $status
);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Space added successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>
