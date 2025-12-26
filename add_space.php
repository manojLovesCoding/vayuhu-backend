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
// ✅ JWT VERIFICATION (ADDED)
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login/signup key

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
    // User is authorized
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
// Allow big uploads (fix JSON breaking issues)
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
$required = ["space_code", "space", "status"];
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

$space_code = val("space_code");
$space = val("space");
$per_hour = val("per_hour");
$per_day = val("per_day");
$one_week = val("one_week");
$two_weeks = val("two_weeks");
$three_weeks = val("three_weeks");
$per_month = val("per_month");
$min_duration = val("min_duration");
$min_duration_desc = val("min_duration_desc");
$max_duration = val("max_duration");
$max_duration_desc = val("max_duration_desc");
$status = val("status") ?? "Active";

// -------------------------
// Duplicate Check
// -------------------------
$chk = $conn->prepare("SELECT id FROM spaces WHERE space_code = ?");
$chk->bind_param("s", $space_code);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Space code already exists"]);
    exit;
}
$chk->close();

// -------------------------
// Image Upload
// -------------------------
if (!isset($_FILES["image"]) || $_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "Image is required"]);
    exit;
}

$uploadDir = "uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

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

// -------------------------
// Insert Query
// -------------------------
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