<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

try {
    // ------------------------------------
    // JWT VERIFICATION FROM COOKIE
    // ------------------------------------
    $token = $_COOKIE['auth_token'] ?? null; // ✅ read from HttpOnly cookie

    if (!$token) {
        http_response_code(401);
        throw new Exception("Authentication token missing.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // User is authorized, data is in $decoded->data
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // ------------------------------------
    // CHECK DATABASE CONNECTION
    // ------------------------------------
    if (!file_exists('db.php')) {
        throw new Exception("db.php file not found!");
    }
    require_once 'db.php';

    // ------------------------------------
    // GET INPUT
    // ------------------------------------
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception("No JSON data received.");
    }

    if (empty($data['name']) || empty($data['contact'])) {
        throw new Exception("Name and Contact are required.");
    }

    // ------------------------------------
    // PREPARE VARIABLES
    // ------------------------------------
    $name = $data['name'];
    $contact = $data['contact'];
    $user_id = !empty($data['user_id']) ? $data['user_id'] : null;
    $admin_id = !empty($data['admin_id']) ? $data['admin_id'] : null;
    $email = $data['email'] ?? null;
    $company_name = $data['company_name'] ?? null;
    $visiting_date = $data['visiting_date'] ?? null;
    $check_in_time = $data['check_in_time'] ?? null;
    $check_out_time = $data['check_out_time'] ?? null;
    $reason = $data['reason'] ?? null;
    $amount_paid = !empty($data['amount_paid']) ? (float)$data['amount_paid'] : 0.00;
    $attendees = !empty($data['attendees']) ? (int)$data['attendees'] : 1;

    // ------------------------------------
    // INSERT QUERY
    // ------------------------------------
    $sql = "INSERT INTO visitors (
                user_id, admin_id, name, contact_no, email, 
                company_name, visiting_date, check_in_time, 
                check_out_time, reason, amount_paid, attendees
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        "iissssssssdi", 
        $user_id, $admin_id, $name, $contact, $email, 
        $company_name, $visiting_date, $check_in_time, 
        $check_out_time, $reason, $amount_paid, $attendees
    );

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "Visitor added successfully"]);
    } else {
        throw new Exception("Database Error: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    if (http_response_code() === 200) http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Server Error: " . $e->getMessage()
    ]);
}
?>
