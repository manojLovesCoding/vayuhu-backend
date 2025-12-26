<?php
require('razorpay-php/Razorpay.php');
require('config.php');

use Razorpay\Api\Api;

// ✅ NEW: Include JWT Library (Adjust path if necessary)
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login script)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// CORS for React
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