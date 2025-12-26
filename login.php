<?php
// --- CORS Configuration ---
$allowed_origin = "http://localhost:5173";
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
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

// ✅ NEW: Include JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ NEW: Define secret key
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // change to a secure random string

// --- Get JSON Input ---
$input = json_decode(file_get_contents("php://input"), true);

// --- Validate JSON Input ---
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

// --- Fetch user by email ---
$sql = "SELECT id, name, email, password FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "No account found with this email."]);
    $stmt->close();
    $conn->close();
    exit;
}

$user = $result->fetch_assoc();

// --- Verify password ---
if (!password_verify($password, $user["password"])) {
    echo json_encode(["status" => "error", "message" => "Incorrect password."]);
    $stmt->close();
    $conn->close();
    exit;
}

// --- Login successful ---
unset($user["password"]); // remove password from response

// ✅ NEW: Create JWT payload
$payload = [
    "iss" => "http://localhost/vayuhu_backend",
    "aud" => "http://localhost:5173",
    "iat" => time(),
    "exp" => time() + (60 * 60 * 24), // expires in 24 hours
    "data" => [
        "id" => $user["id"],
        "name" => $user["name"],
        "email" => $user["email"]
    ]
];

// ✅ NEW: Generate JWT
$jwt = JWT::encode($payload, $secret_key, 'HS256');

// ✅ NEW: Return token with response
echo json_encode([
    "status" => "success",
    "message" => "Login successful.",
    "user" => $user,
    "token" => $jwt // send token to frontend
]);

$stmt->close();
$conn->close();
?>
