<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db.php';

use Firebase\JWT\JWT;

// ✅ Include JWT
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'];


$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON input."]);
    exit;
}

$name = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$password = $input["password"] ?? "";

if (empty($name) || empty($email) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "All fields are required."]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Invalid email format."]);
    exit;
}

// Check if user exists
$check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Email already registered."]);
    exit;
}
$check->close();

// Hash password & insert user
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $name, $email, $hashed_password);

if ($stmt->execute()) {
    $user_id = $stmt->insert_id;

    // ✅ Create JWT payload
    $payload = [
        "iss" => "http://localhost/vayuhu_backend",
        "aud" => "http://localhost:5173",
        "iat" => time(),
        "exp" => time() + (60 * 60 * 24), // 24 hrs expiration
        "data" => [
            "id" => $user_id,
            "name" => $name,
            "email" => $email
        ]
    ];

    // ✅ Encode the token
    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    echo json_encode([
        "status" => "success",
        "message" => "User registered successfully.",
        "user" => [
            "id" => $user_id,
            "name" => $name,
            "email" => $email
        ],
        "token" => $jwt // ✅ Sending the token back to React
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>