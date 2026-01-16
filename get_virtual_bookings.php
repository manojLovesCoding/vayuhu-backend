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

// ------------------------------------
// ✅ JWT VERIFICATION LOGIC (HttpOnly Cookie)
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication required"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; user data is available in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Fetch Bookings with User Details
// ------------------------------------
// LEFT JOIN ensures booking is retrieved even if the user was deleted
$sql = "SELECT 
            b.id,
            b.user_id,
            u.name AS user_name,
            u.email AS user_email,
            b.start_date,
            b.end_date,
            b.total_amount,
            b.payment_id,
            b.payment_status,
            b.status,
            b.created_at
        FROM virtualoffice_bookings b
        LEFT JOIN users u ON b.user_id = u.id
        ORDER BY b.created_at DESC";

$result = $conn->query($sql);

if ($result) {
    $bookings = [];
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    
    echo json_encode([
        "success" => true, 
        "count" => count($bookings), 
        "bookings" => $bookings
    ]);
} else {
    echo json_encode([
        "success" => false, 
        "message" => "Error executing query: " . $conn->error
    ]);
}

$conn->close();
