<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'db.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set");

// ------------------------------------
// JWT VERIFICATION FROM COOKIE
// ------------------------------------
$token = $_COOKIE['auth_token'] ?? null; // ✅ read from HttpOnly cookie
if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// GET INPUT
// ------------------------------------
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id'])) {
    echo json_encode(["success" => false, "message" => "Visitor ID required"]);
    exit;
}

$id = (int)$data['id'];
$new_check_in = $conn->real_escape_string($data['check_in_time']);
$new_check_out = $conn->real_escape_string($data['check_out_time']);
$new_amount = $conn->real_escape_string($data['amount_paid']);

// ------------------------------------
// SQL: Append the new slot/payment
// ------------------------------------
$sql = "UPDATE visitors SET 
        check_in_time = CONCAT(check_in_time, ' | ', '$new_check_in'),
        check_out_time = CONCAT(check_out_time, ' | ', '$new_check_out'),
        amount_paid = CONCAT(amount_paid, ' | ', '$new_amount')
        WHERE id = $id";

if ($conn->query($sql)) {
    echo json_encode(["success" => true, "message" => "Slot and payment appended successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Update failed: " . $conn->error]);
}

$conn->close();
?>
