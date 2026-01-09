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
    $api->utility->verifyPaymentSignature($data);
    echo json_encode(["success" => true]);
} catch (SignatureVerificationError $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
