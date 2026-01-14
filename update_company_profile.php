<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// ------------------------------------
// DATABASE CONNECTION
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// ✅ INCLUDE JWT LIBRARY
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// ✅ JWT SECRET FROM ENV
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// ✅ GET & VERIFY JWT FROM COOKIE
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized: No token found."]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized: Invalid or expired token."]);
    exit;
}

// ------------------------------------
// VALIDATE INPUT
// ------------------------------------
$user_id = $_POST['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

// ✅ SECURITY CHECK: Token user ID vs Request user ID
if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized: User ID mismatch."]);
    exit;
}

// Collect updated fields
$companyName = trim($_POST['companyName'] ?? "");
$gstNo       = trim($_POST['gstNo'] ?? "");
$contact     = trim($_POST['contact'] ?? "");
$address     = trim($_POST['address'] ?? "");

// Optional: email should not be updated
$email       = trim($_POST['email'] ?? "");

// ------------------------------------
// HANDLE LOGO UPLOAD
// ------------------------------------
$logoPath = null;
if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . "/uploads/company_logos/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileTmp  = $_FILES['logo']['tmp_name'];
    $fileName = uniqid("logo_") . "_" . basename($_FILES['logo']['name']);
    $targetPath = $uploadDir . $fileName;

    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    if (in_array($_FILES['logo']['type'], $allowedTypes) && move_uploaded_file($fileTmp, $targetPath)) {
        $logoPath = "uploads/company_logos/" . $fileName;
    }
}

// ------------------------------------
// BUILD SQL
// ------------------------------------
$sql = "UPDATE company_profile SET company_name=?, gst_no=?, contact=?, address=?";
$params = [$companyName, $gstNo, $contact, $address];
$types = "ssss";

if ($logoPath) {
    $sql .= ", logo=?";
    $params[] = $logoPath;
    $types .= "s";
}

$sql .= " WHERE user_id=?";
$params[] = $user_id;
$types .= "i";

// ------------------------------------
// EXECUTE UPDATE
// ------------------------------------
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Company profile updated successfully"
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
