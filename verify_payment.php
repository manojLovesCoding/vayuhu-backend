<?php
require('razorpay-php/Razorpay.php');
require('config.php');

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login script)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");
header("Access-Control-Allow-Credentials: true");

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// ------------------------------------

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
// ------------------------------------

$api = new Api($razorpay_config['api_key'], $razorpay_config['api_secret']);
$data = json_decode(file_get_contents("php://input"), true);

try {
  $api->utility->verifyPaymentSignature($data);
  echo json_encode(["success" => true]);
} catch (SignatureVerificationError $e) {
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>