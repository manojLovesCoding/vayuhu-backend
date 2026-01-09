<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads env vars (JWT_SECRET)
require_once __DIR__ . '/config/cors.php';  // centralized CORS + OPTIONS handling

// ------------------------------------
// Response type
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// Database connection
// ------------------------------------
include "db.php";

// ------------------------------------
// JWT library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ JWT secret from environment
$secret_key = $_ENV['JWT_SECRET'];

try {
    // ------------------------------------
    // Get token from headers
    // ------------------------------------
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing.");
    }

    $token = str_replace('Bearer ', '', $authHeader);

    // ------------------------------------
    // Decode JWT
    // ------------------------------------
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

    // Only admin can access
    if ($decoded->data->role !== 'admin') {
        http_response_code(403);
        throw new Exception("Unauthorized: Admins only.");
    }

    // ------------------------------------
    // Get user_id from query
    // ------------------------------------
    $user_id = $_GET['user_id'] ?? null;
    if (!$user_id) throw new Exception("user_id is required.");

    // ------------------------------------
    // Fetch company profile
    // ------------------------------------
    $sql = "SELECT * FROM company_profile WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $profile = $result->fetch_assoc();
        // Fix logo URL for frontend
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
