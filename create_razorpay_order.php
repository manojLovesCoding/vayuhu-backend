<?php
require('razorpay-php/Razorpay.php');
require('config.php');

// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Handles CORS & OPTIONS requests

// ------------------------------------
// JWT VERIFICATION
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ---------------- JWT CHECK ----------------
// Read JWT from HttpOnly cookie
$token = $_COOKIE['auth_token'] ?? null;

// Fallback (optional): check Authorization header for backward compatibility
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
if (!$token && $authHeader) {
    $token = str_replace('Bearer ', '', $authHeader);
}

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Successfully verified. User info is in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Razorpay Order Creation
// ------------------------------------
use Razorpay\Api\Api;

$api = new Api($razorpay_config['api_key'], $razorpay_config['api_secret']);

// Read amount from frontend (in INR)
$data = json_decode(file_get_contents("php://input"), true);
$amount = isset($data['amount']) ? (int)$data['amount'] : 0;

if ($amount <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid amount"]);
    exit;
}

// Create order in Razorpay (amount in paise)
$order = $api->order->create([
    'amount' => $amount * 100,
    'currency' => 'INR',
    'receipt' => 'order_' . time(),
]);

echo json_encode([
    "success" => true,
    "order_id" => $order['id'],
    "key" => $razorpay_config['api_key']
]);
?>
