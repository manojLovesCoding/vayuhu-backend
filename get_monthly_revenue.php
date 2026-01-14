<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

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

    // ---------------- JWT VERIFICATION ----------------
    $token = $_COOKIE['auth_token'] ?? null;

    if (!$token) {
        http_response_code(401);
        throw new Exception("Authorization token missing.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

        // Admin-only access
        if ($decoded->data->role !== 'admin') {
            throw new Exception("Unauthorized");
        }
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // ---------------- FETCH MONTHLY REVENUE ----------------
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
?>
