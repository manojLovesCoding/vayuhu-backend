<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Include Database Connection
// ------------------------------------
include "db.php";

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

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

// ------------------------------------
// JWT VERIFICATION LOGIC
// ------------------------------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; you can access $decoded->data if needed
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Query to fetch virtual office prices
// ------------------------------------
$sql = "SELECT 
            id,
            min_duration,
            max_duration,
            price,
            gst,
            status,
            created_at
        FROM virtualoffice_prices
        ORDER BY id DESC";

$result = $conn->query($sql);
$priceList = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Handle NULL values safely
        foreach ($row as $key => $value) {
            $row[$key] = $value ?? "";
        }
        $priceList[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "data" => $priceList
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "No records found."
    ]);
}

// ------------------------------------
// Close connection
// ------------------------------------
$conn->close();
