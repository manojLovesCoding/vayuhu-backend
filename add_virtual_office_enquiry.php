<?php
// ------------------------------------
// CORS Configuration
// ------------------------------------
$allowed_origin = "http://localhost:5173"; // Update for live domain
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

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login script)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC (Optional for Enquiries)
// ------------------------------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if ($authHeader) {
    // Extract token from "Bearer <token>"
    $token = str_replace('Bearer ', '', $authHeader);
    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // Optional: Link $decoded->data->id to the enquiry if needed
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid or expired session. Please log in again."]);
        exit;
    }
}

// ------------------------------------
// Include Database Connection
// ------------------------------------
require_once "db.php"; // must return $conn (mysqli)

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