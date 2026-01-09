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
// Get JSON Input
// ------------------------------------
$input = json_decode(file_get_contents("php://input"), true);

// ------------------------------------
// Validate JSON
// ------------------------------------
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
    exit;
}

$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

// ------------------------------------
// Basic Validation
// ------------------------------------
if (empty($email) || empty($password)) {
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

if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "No admin account found with this email."]);
    $stmt->close();
    $conn->close();
    exit;
}

$admin = $result->fetch_assoc();

// ------------------------------------
// Verify password
// ------------------------------------
if (!password_verify($password, $admin["password"])) {
    echo json_encode(["status" => "error", "message" => "Incorrect password."]);
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
// Success Response
// ------------------------------------
echo json_encode([
    "status" => "success",
    "message" => "Admin login successful.",
    "admin" => $admin,
    "token" => $jwt
]);

$stmt->close();
$conn->close();
?>
