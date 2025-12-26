<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true"); // ✅ Added for secure sessions
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

// ✅ NEW: Include JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ NEW: JWT Secret Key
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// ✅ NEW: Get & Verify Token
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id; // Extract user ID from token
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// Ensure user_id is provided
$user_id = $_POST['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

// ✅ NEW: Cross-check token ID with requested User ID
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

// Handle logo upload
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

// Build SQL dynamically
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

// Execute update
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
?>