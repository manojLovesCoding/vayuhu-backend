<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// Set JSON response header
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// Get JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
    exit;
}

$name = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

// ------------------------------------
// Basic validation
// ------------------------------------
if (empty($name) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid email format."]);
    exit;
}

// ------------------------------------
// Check if user already exists
// ------------------------------------
$check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    http_response_code(409); // Conflict
    echo json_encode(["status" => "error", "message" => "Email already registered."]);
    $check->close();
    exit;
}
$check->close();

// ------------------------------------
// Hash password & insert user
// ------------------------------------
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $name, $email, $hashed_password);

if (!$stmt->execute()) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
    $stmt->close();
    $conn->close();
    exit;
}

$user_id = $stmt->insert_id;

// ------------------------------------
// Create JWT payload
// ------------------------------------
$payload = [
    "iss" => "http://localhost/vayuhu_backend",
    "aud" => "http://localhost:5173",
    "iat" => time(),
    "nbf" => time(),
    "exp" => time() + (60 * 60 * 24), // 24 hrs expiration
    "data" => [
        "id" => $user_id,
        "name" => $name,
        "email" => $email
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
        "secure"   => false, // ⚠️ TRUE in production with HTTPS
        "httponly" => true,
        "samesite" => "Lax"
    ]
);

// ------------------------------------
// Send success response (no token in JSON)
// ------------------------------------
echo json_encode([
    "status" => "success",
    "message" => "User registered successfully.",
    "user" => [
        "id" => $user_id,
        "name" => $name,
        "email" => $email
    ]
]);

$stmt->close();
$conn->close();
?>
