<?php
// 1. SILENCE HTML ERRORS (Fixes the "<" syntax error)
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// --- CORS Configuration ---
$allowed_origin = "http://localhost:5173"; 
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login script)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

try {
    // ------------------------------------
    // ✅ JWT VERIFICATION (ADDED)
    // ------------------------------------
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing.");
    }

    // Extract token from "Bearer <token>"
    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // User is authorized, data is in $decoded->data
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // 2. CHECK DATABASE CONNECTION
    if (!file_exists('db.php')) {
        throw new Exception("db.php file not found!");
    }
    require_once 'db.php';

    // 3. GET INPUT
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception("No JSON data received.");
    }

    if (empty($data['name']) || empty($data['contact'])) {
        throw new Exception("Name and Contact are required.");
    }

    // 4. PREPARE VARIABLES
    $name = $data['name'];
    $contact = $data['contact'];
    
    // Handle NULLs for IDs
    $user_id = !empty($data['user_id']) ? $data['user_id'] : null;
    $admin_id = !empty($data['admin_id']) ? $data['admin_id'] : null;

    $email = $data['email'] ?? null;
    $company_name = $data['company_name'] ?? null;
    $visiting_date = $data['visiting_date'] ?? null;
    $check_in_time  = $data['check_in_time'] ?? null;
$check_out_time = $data['check_out_time'] ?? null;

    $reason = $data['reason'] ?? null;

    // 5. INSERT QUERY
    $sql = "INSERT INTO visitors (
            user_id, 
            admin_id, 
            name, 
            contact_no, 
            email, 
            company_name, 
            visiting_date, 
            check_in_time, 
            check_out_time, 
            reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";


    $stmt = $conn->prepare($sql);
    
    if(!$stmt) {
        throw new Exception("SQL Prepare Failed: " . $conn->error);
    }

    $stmt->bind_param(
    "iissssssss",
    $user_id,
    $admin_id,
    $name,
    $contact,
    $email,
    $company_name,
    $visiting_date,
    $check_in_time,
    $check_out_time,
    $reason
);

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Visitor added successfully"]);
    } else {
        throw new Exception("Database Error: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    // 6. RETURN ERROR AS JSON (Not HTML)
    // Ensure we send 401 if it was an auth failure, otherwise keep 400 or default
    if (http_response_code() === 200) http_response_code(400);

    echo json_encode([
        "success" => false, 
        "message" => "Server Error: " . $e->getMessage()
    ]);
}
?>