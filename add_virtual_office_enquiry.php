<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Include Database Connection
// ------------------------------------
require_once "db.php"; // must return $conn (mysqli)
if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// JWT Verification (Optional for Enquiries via HttpOnly cookie)
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ✅ Read token from HttpOnly cookie instead of Authorization header
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "You must be logged in to submit an enquiry."
    ]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid or expired session. Please log in again"
    ]);
    exit;
}


// ------------------------------------
// Get JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid input data."
    ]);
    exit;
}

// ------------------------------------
// Extract Form Data
// ------------------------------------
$name     = trim($input["name"] ?? "");
$email    = trim($input["email"] ?? "");
$phone    = trim($input["phone"] ?? "");
$referral = trim($input["referral"] ?? "");
$message  = trim($input["message"] ?? "");

// ------------------------------------
// Validation
// ------------------------------------
if (empty($name) || empty($phone) || empty($message)) {
    echo json_encode([
        "status" => "error",
        "message" => "Name, phone number, and message are required."
    ]);
    exit;
}

if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid email address."
    ]);
    exit;
}

// ------------------------------------
// Check for Duplicate Entry (Phone)
// ------------------------------------
$checkSql = "SELECT id FROM virtual_office_enquiries WHERE phone = ?";
$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("s", $phone);
$checkStmt->execute();
$checkStmt->store_result();

if ($checkStmt->num_rows > 0) {
    echo json_encode([
        "status" => "error",
        "message" => "An enquiry with this phone number already exists."
    ]);
    $checkStmt->close();
    $conn->close();
    exit;
}

$checkStmt->close();

// ------------------------------------
// Insert Virtual Office Enquiry
// ------------------------------------
$status = "New"; // Default enquiry status

$sql = "
    INSERT INTO virtual_office_enquiries
    (name, email, phone, referral_source, message, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "ssssss",
    $name,
    $email,
    $phone,
    $referral,
    $message,
    $status
);

if ($stmt->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Virtual office enquiry submitted successfully!"
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>
