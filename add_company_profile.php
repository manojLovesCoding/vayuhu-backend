<?php
// -------------------------
// Load Environment & CORS
// -------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Centralized CORS headers & OPTIONS handling

// -------------------------
// Prevent PHP warnings from breaking JSON
// -------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

// -------------------------
// Database
// -------------------------
require_once 'db.php';

// -------------------------
// JWT Library
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// -------------------------
// Use JWT secret from .env
// -------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// -------------------------
// JWT Verification from HttpOnly Cookie
// -------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id ?? null;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// -------------------------
// Ensure user_id matches token
// -------------------------
$user_id = $_POST['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized: Identity mismatch."]);
    exit;
}

// -------------------------
// Collect & validate form data
// -------------------------
$companyName = trim($_POST['companyName'] ?? "");
$gstNo       = trim($_POST['gstNo'] ?? "");
$email       = trim($_POST['email'] ?? "");
$contact     = trim($_POST['contact'] ?? "");
$address     = trim($_POST['address'] ?? "");

if (empty($companyName) || empty($email) || empty($contact)) {
    echo json_encode(["success" => false, "message" => "Company Name, Email, and Contact are required"]);
    exit;
}

// -------------------------
// Check for existing profile
// -------------------------
$checkSql = "SELECT id FROM company_profile WHERE user_id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $user_id);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows > 0) {
    echo json_encode(["success" => false, "message" => "Company profile already exists"]);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// -------------------------
// Handle logo upload
// -------------------------
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

// -------------------------
// Insert new profile
// -------------------------
$sql = "INSERT INTO company_profile (user_id, company_name, gst_no, email, contact, address, logo, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
$stmt = $conn->prepare($sql);
$stmt->bind_param("issssss", $user_id, $companyName, $gstNo, $email, $contact, $address, $logoPath);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Company profile created successfully",
        "profileId" => $stmt->insert_id
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>
