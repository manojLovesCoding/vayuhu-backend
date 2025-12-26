<?php
// -----------------------------
// CORS + Headers
// -----------------------------
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");

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
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid; user data is available in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// -----------------------------
// DB Connection
// -----------------------------
include "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// -----------------------------
// Fetch Bookings with User Details
// -----------------------------
// We use LEFT JOIN to ensure we get the booking record even if the user was deleted
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
?>