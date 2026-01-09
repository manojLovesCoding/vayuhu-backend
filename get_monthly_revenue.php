<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

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

try {
    include "db.php";

    // ------------------------------------
    // JWT VERIFICATION LOGIC
    // ------------------------------------
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing.");
    }

    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // ------------------------------------
    // Revenue Query
    // ------------------------------------
    $sql = "
        SELECT 
            DATE_FORMAT(start_date, '%Y-%m') AS month,
            SUM(final_amount) AS total_revenue
        FROM workspace_bookings
        GROUP BY month
        ORDER BY month ASC
    ";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("SQL Error: " . $conn->error);
    }

    $revenue = [];
    while ($row = $result->fetch_assoc()) {
        $revenue[] = $row;
    }

    echo json_encode([
        "success" => true,
        "revenue" => $revenue
    ]);

    $conn->close();

} catch (Exception $e) {
    if (http_response_code() == 200) {
        http_response_code(400);
    }
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
