<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Include Database Connection
// ------------------------------------
require_once 'db.php';
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// JWT Verification
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// Get JSON input
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid request data"]);
    exit;
}

// ---------------- JWT VERIFICATION ----------------
// Read JWT exclusively from HttpOnly cookie
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

$user_id = $data['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access: Identity mismatch."]);
    exit;
}

// ---------------- FETCH COMPANY INFO ----------------
$company_id = null;
$company_name = null;

$getCompany = $conn->prepare("SELECT id, company_name FROM company_profile WHERE user_id = ?");
$getCompany->bind_param("i", $user_id);
$getCompany->execute();
$getCompany->bind_result($company_id, $company_name);
$getCompany->fetch();
$getCompany->close();

if (!$company_id) {
    echo json_encode(["success" => false, "message" => "No company profile found for this user"]);
    exit;
}

// ---------------- COLLECT VISITOR & PAYMENT DATA ----------------
$booking_id    = $data['booking_id'] ?? null;
$name          = trim($data['name'] ?? "");
$contact       = trim($data['contact'] ?? "");
$email         = trim($data['email'] ?? "");
$visitingDate  = trim($data['visitingDate'] ?? "");
$checkInTime   = trim($data['checkInTime'] ?? "");
$checkOutTime  = trim($data['checkOutTime'] ?? "");
$reason        = trim($data['reason'] ?? "");
$attendees     = (int)($data['attendees'] ?? 1);
$payment_id    = trim($data['payment_id'] ?? "");
$amount_paid   = (float)($data['amount_paid'] ?? 0);

// 🧠 Debug log
error_log("DEBUG: Received payment_id = " . $payment_id);

// ---------------- BASIC VALIDATION ----------------
if (empty($name) || empty($contact)) {
    echo json_encode(["success" => false, "message" => "Name and Contact No are required"]);
    exit;
}

// ---------------- INSERT VISITOR INTO DATABASE ----------------
$sql = "INSERT INTO visitors (
    user_id, company_id, booking_id, name, contact_no, email, company_name, 
    visiting_date, check_in_time, check_out_time, reason, attendees, 
    payment_id, amount_paid, added_on
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);

// 🟢 Correct bind_param type string
$stmt->bind_param(
    "iissssssssssid",
    $user_id,        // i
    $company_id,     // i
    $booking_id,     // i
    $name,           // s
    $contact,        // s
    $email,          // s
    $company_name,   // s
    $visitingDate,   // s
    $checkInTime,    // s
    $checkOutTime,   // s
    $reason,         // s
    $attendees,      // i
    $payment_id,     // s
    $amount_paid     // d
);

// 🧠 Debug log
error_log("DEBUG: Received payment_id = " . $payment_id);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Visitor added successfully",
        "visitorId" => $stmt->insert_id
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
