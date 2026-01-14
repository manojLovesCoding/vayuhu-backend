<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

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
// JWT VERIFICATION LOGIC (HttpOnly Cookie)
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Authentication required"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
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

$conn->close();
