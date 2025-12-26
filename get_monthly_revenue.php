<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------
// CORS HEADERS
// ------------------
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ NEW: Define secret key
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

try {
    include "db.php";

    // ------------------------------------
    // ✅ NEW: JWT VERIFICATION LOGIC
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
        // The token is valid. You can access user data via $decoded->data if needed.
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

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
    // If status code hasn't been set by auth logic, default to 400
    if (http_response_code() == 200) {
        http_response_code(400);
    }
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>