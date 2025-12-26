<?php
// ------------------------------------
// CORS Configuration
// ------------------------------------
$allowed_origin = "http://localhost:5173"; // Update when deployed
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

// -------------------------
// ✅ Include JWT Library
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the same secret key used in login/signup
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// -------------------------
// ✅ Verify JWT Token
// -------------------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Missing Authorization token"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data; // successfully verified user info
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Include Database
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// Read JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode(["success" => false, "message" => "Invalid input data."]);
    exit;
}

// ------------------------------------
// Extract Input Fields
// ------------------------------------
$contact_id = intval($input["contact_id"] ?? 0);
$status = trim($input["status"] ?? "");
$comment = trim($input["comment"] ?? "");

// ------------------------------------
// Validation
// ------------------------------------
if (empty($contact_id) || empty($status) || empty($comment)) {
    echo json_encode(["success" => false, "message" => "All fields are required."]);
    exit;
}

// ------------------------------------
// Verify Contact Exists
// ------------------------------------
$checkSql = "SELECT id FROM contact_requests WHERE id = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("i", $contact_id);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Contact not found."]);
    $checkStmt->close();
    $conn->close();
    exit;
}
$checkStmt->close();

// ------------------------------------
// Insert Comment
// ------------------------------------
$insertSql = "INSERT INTO contact_comments (contact_id, status, comment, created_at) VALUES (?, ?, ?, NOW())";
$insertStmt = $conn->prepare($insertSql);
$insertStmt->bind_param("iss", $contact_id, $status, $comment);

if (!$insertStmt->execute()) {
    echo json_encode(["success" => false, "message" => "Database error: " . $insertStmt->error]);
    $insertStmt->close();
    $conn->close();
    exit;
}
$insertStmt->close();

// ------------------------------------
// Update Main Contact Status
// ------------------------------------
$updateSql = "UPDATE contact_requests SET status = ? WHERE id = ?";
$updateStmt = $conn->prepare($updateSql);
$updateStmt->bind_param("si", $status, $contact_id);
$updateStmt->execute();
$updateStmt->close();

$conn->close();

// ------------------------------------
// Success Response
// ------------------------------------
echo json_encode([
    "success" => true,
    "message" => "Comment added successfully and contact status updated."
]);
?>