<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db.php';

use Firebase\JWT\JWT;

// Set JSON response header
header("Content-Type: application/json; charset=UTF-8");

$secret_key = $_ENV['JWT_SECRET'];

// --- Get JSON Input ---
$input = json_decode(file_get_contents("php://input"), true);

// --- Validate JSON Input ---
if (!$input) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
    exit;
}

$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

// --- Basic Validation ---
if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Email and password are required."]);
    exit;
}

// --- Fetch user by email ---
$sql = "SELECT id, name, email, password FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

// --- Prevent user enumeration ---
if ($result->num_rows === 0) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid email or password."
    ]);
    exit;
}

$user = $result->fetch_assoc();

// --- Verify password ---
if (!password_verify($password, $user["password"])) {
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid email or password."
    ]);
    exit;
}

// --- Login successful ---
unset($user["password"]); // remove password from response

// --- Create JWT payload ---
$payload = [
    "iss" => "http://localhost/vayuhu_backend",
    "aud" => "http://localhost:5173",
    "iat" => time(),
    "nbf" => time(),
    "exp" => time() + (60 * 60 * 24), // 24 hours
    "data" => [
        "id" => $user["id"],
        "name" => $user["name"],
        "email" => $user["email"]
    ]
];

// --- Generate JWT ---
$jwt = JWT::encode($payload, $secret_key, 'HS256');

// --- Store JWT in HttpOnly cookie ---
setcookie(
    "auth_token",
    $jwt,
    [
        "expires"  => time() + (60 * 60 * 24),
        "path"     => "/",
        "secure"   => false, // ⚠️ set to TRUE in HTTPS
        "httponly" => true,
        "samesite" => "Lax"
    ]
);

// --- Send response (NO TOKEN) ---
echo json_encode([
    "status" => "success",
    "message" => "Login successful.",
    "user" => $user
]);

$stmt->close();
$conn->close();
