<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Database connection
// ------------------------------------
require_once "db.php";
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
// ✅ JWT Verification via HttpOnly Cookie
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Access user/admin info: $adminId = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Prevent warnings
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// Validate POST
// ------------------------------------
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

if (!isset($_POST["id"]) || $_POST["id"] == "") {
    echo json_encode(["success" => false, "message" => "ID is missing"]);
    exit;
}

$id = intval($_POST["id"]);

// ------------------------------------
// Utility
// ------------------------------------
function val($key) {
    return isset($_POST[$key]) && $_POST[$key] !== "" ? trim($_POST[$key]) : null;
}

// ------------------------------------
// Sanitize Inputs
// ------------------------------------
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

// ------------------------------------
// Required fields
// ------------------------------------
$required = ["space_code", "space", "status"];
foreach ($required as $r) {
    if (empty($_POST[$r])) {
        echo json_encode(["success" => false, "message" => "$r is required"]);
        exit;
    }
}

// ------------------------------------
// Duplicate check (excluding this ID)
// ------------------------------------
$chk = $conn->prepare("SELECT id FROM spaces WHERE space_code = ? AND id != ?");
$chk->bind_param("si", $space_code, $id);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Space code already exists"]);
    exit;
}
$chk->close();

// ------------------------------------
// Fetch existing image
// ------------------------------------
$old = $conn->prepare("SELECT image FROM spaces WHERE id = ?");
$old->bind_param("i", $id);
$old->execute();
$old->bind_result($old_image);
$old->fetch();
$old->close();

$new_image_path = $old_image;

// ------------------------------------
// Optional Image Upload
// ------------------------------------
if (isset($_FILES["image"]) && $_FILES["image"]["error"] === UPLOAD_ERR_OK) {

    $uploadDir = "uploads/spaces/";
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

    // Remove old image
    if ($old_image && file_exists($old_image)) {
        unlink($old_image);
    }

    $new_image_path = $filePath;
}

// ------------------------------------
// Update Query
// ------------------------------------
$sql = "UPDATE spaces SET 
        space_code = ?, space = ?, per_hour = ?, per_day = ?, one_week = ?, two_weeks = ?, 
        three_weeks = ?, per_month = ?, min_duration = ?, min_duration_desc = ?, 
        max_duration = ?, max_duration_desc = ?, image = ?, status = ?
        WHERE id = ?";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ssdddddddsdsssi",
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
    $new_image_path,
    $status,
    $id
);

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "Space updated successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "DB Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
