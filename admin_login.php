<?php
// --- CORS Configuration ---
$allowed_origin = "http://localhost:5173";
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// ✅ Added Authorization to allowed headers for future requests
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- Response Type ---
header("Content-Type: application/json; charset=UTF-8");

// --- Include Database ---
require_once 'db.php';

// --- Include JWT library ---
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// --- Secret Key ---
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// --- Get JSON Input ---
$input = json_decode(file_get_contents("php://input"), true);

// --- Validate JSON ---
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
    exit;
}

$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

// --- Basic Validation ---
if (empty($email) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Email and password are required."]);
    exit;
}

// --- Fetch Admin ---
$sql = "SELECT id, name, email, password, role FROM admins WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "No admin account found with this email."]);
    $stmt->close();
    $conn->close();
    exit;
}

$admin = $result->fetch_assoc();

// --- Verify password ---
if (!password_verify($password, $admin["password"])) {
    echo json_encode(["status" => "error", "message" => "Incorrect password."]);
    $stmt->close();
    $conn->close();
    exit;
}

// --- Remove password ---
unset($admin["password"]);

// --- Create JWT Payload ---

$payload = [
    "iss" => "http://localhost/vayuhu_backend",
    "aud" => "http://localhost:5173",
    "iat" => time(),
    "nbf" => time(), // ✅ Not before (Token is valid immediately)
    "exp" => time() + (60 * 60 * 24), // 24 hours
    "data" => [
        "id" => $admin["id"],
        "name" => $admin["name"],
        "email" => $admin["email"],
        "role" => $admin["role"] // ✅ Crucial for admin-only middleware
    ]
];

// --- Generate JWT ---
$jwt = JWT::encode($payload, $secret_key, 'HS256');

// --- Success Response ---
echo json_encode([
    "status" => "success",
    "message" => "Admin login successful.",
    "admin" => $admin,
    "token" => $jwt
]);

$stmt->close();
$conn->close();
?>