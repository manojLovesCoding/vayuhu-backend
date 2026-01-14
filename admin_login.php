<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Include Database
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// Include JWT library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// Secret Key from .env
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// Set JSON response header
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// Get JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

// ------------------------------------
// Validate JSON
// ------------------------------------
if (!$input) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
    exit;
}

$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

// ------------------------------------
// Basic Validation
// ------------------------------------
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and password are required."]);
    exit;
}

// ------------------------------------
// Fetch Admin
// ------------------------------------
$sql = "SELECT id, name, email, password, role FROM admins WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

$admin = $result->fetch_assoc();

// ------------------------------------
// Verify password & prevent enumeration
// ------------------------------------
if (!$admin || !password_verify($password, $admin["password"])) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid email or password."
    ]);
    $stmt->close();
    $conn->close();
    exit;
}

// ------------------------------------
// Remove password
// ------------------------------------
unset($admin["password"]);

// ------------------------------------
// Create JWT Payload
// ------------------------------------
$payload = [
    "iss" => "http://localhost/vayuhu_backend",
    "aud" => "http://localhost:5173",
    "iat" => time(),
    "nbf" => time(),
    "exp" => time() + (60 * 60 * 24), // 24 hours
    "data" => [
        "id" => $admin["id"],
        "name" => $admin["name"],
        "email" => $admin["email"],
        "role" => $admin["role"]
    ]
];

// ------------------------------------
// Generate JWT
// ------------------------------------
$jwt = JWT::encode($payload, $secret_key, 'HS256');

// ------------------------------------
// Store JWT in HttpOnly cookie
// ------------------------------------
setcookie(
    "auth_token",
    $jwt,
    [
        "expires"  => time() + (60 * 60 * 24), // 24 hours
        "path"     => "/",
        "secure"   => false,   // ⚠️ Set to TRUE in production with HTTPS
        "httponly" => true,
        "samesite" => "Lax"
    ]
);

// ------------------------------------
// Success Response
// ------------------------------------
echo json_encode([
    "status" => "success",
    "message" => "Admin login successful.",
    "admin" => $admin
]);

$stmt->close();
$conn->close();
?>
