<?php
require('razorpay-php/Razorpay.php');
require('config.php');

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

// -----------------------------------
// Load Environment & Centralized CORS
// -----------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// -----------------------------------
// JWT Verification
// -----------------------------------
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

// -----------------------------------
// Razorpay Payment Verification
// -----------------------------------
$api = new Api($razorpay_config['api_key'], $razorpay_config['api_secret']);
$data = json_decode(file_get_contents("php://input"), true);

try {
    // 🔐 Verify Razorpay signature
    $api->utility->verifyPaymentSignature($data);

    // ✅ Return verified payment details
    echo json_encode([
        "success" => true,
        "payment_id" => $data['razorpay_payment_id'] ?? null,
        "order_id"   => $data['razorpay_order_id'] ?? null
    ]);

} catch (SignatureVerificationError $e) {

    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
