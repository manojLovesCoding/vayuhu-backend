<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

include "db.php";

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT Secret
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

$baseURL = "http://localhost/vayuhuBackend"; // adjust if folder name differs

// ------------------------------------
// JWT VERIFICATION LOGIC
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

// ------------------------------------
// Fetch all users
// ------------------------------------
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
