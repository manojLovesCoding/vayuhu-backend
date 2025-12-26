<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login script)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// ------------------------------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; user details are in $decoded->data if needed
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

include "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

$baseURL = "http://localhost/vayuhuBackend"; // adjust if folder name differs

// Remove 'comments' column from SQL
$sql = "SELECT 
            id, 
            name, 
            email, 
            phone, 
            dob, 
            address, 
            profile_pic,
            status, 
            details, 
            company, 
            created_at
        FROM users
        ORDER BY id DESC";

$result = $conn->query($sql);
$users = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Handle NULLs
        foreach ($row as $key => $value) {
            $row[$key] = $value ?? "";
        }

        // Convert relative image path → full URL
        if (!empty($row['profile_pic'])) {
            $row['profile_pic'] = $baseURL . '/' . $row['profile_pic'];
        }

        $users[] = $row;
    }

    echo json_encode(["success" => true, "users" => $users]);
} else {
    echo json_encode(["success" => false, "message" => "No users found."]);
}

$conn->close();
?>