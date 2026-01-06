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

// JWT library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // must match login key

try {
    include "db.php";

    // Get token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing.");
    }

    $token = str_replace('Bearer ', '', $authHeader);

    // Decode token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

    // Only admin can access
    if ($decoded->data->role !== 'admin') {
        http_response_code(403);
        throw new Exception("Unauthorized: Admins only.");
    }

    // Get user_id from query
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) throw new Exception("user_id is required.");

    // Fetch company profile for given user
    $sql = "SELECT * FROM company_profile WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $profile = $result->fetch_assoc();
        // Fix logo URL
        if ($profile['logo']) {
            $profile['logo'] = "http://localhost/vayuhuBackend/" . $profile['logo'];
        }
        echo json_encode(["success" => true, "profile" => $profile]);
    } else {
        echo json_encode(["success" => false, "message" => "No company profile found for this user."]);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    if (http_response_code() == 200) http_response_code(400);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
