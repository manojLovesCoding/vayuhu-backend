<?php
// -----------------------------------
// CORS + Headers
// -----------------------------------
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

// -----------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// -----------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login script
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

// -----------------------------------
// DB Connection
// -----------------------------------
include "db.php";

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// -----------------------------------
// Query to fetch virtual office prices
// -----------------------------------
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

// -----------------------------------
// Close connection
// -----------------------------------
$conn->close();
?>