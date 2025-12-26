<?php
// send_booking_email.php

// --- CORS Configuration ---
$allowed_origin = "http://localhost:5173";
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- Response Type ---
header("Content-Type: application/json; charset=UTF-8");

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login script

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

$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // User is authenticated, proceed to send email
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}
// ------------------------------------

// --- Dependencies ---
require_once 'db.php';
// require 'vendor/autoload.php'; // Already included above for JWT

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- Read JSON ---
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    echo json_encode(["success" => false, "message" => "Invalid input data"]);
    exit;
}

// --- Extract Global User Data ---
$user_id      = $input["user_id"] ?? null;
$user_email   = trim($input["user_email"] ?? "");
// Use the name sent from React, or fallback to "Customer"
$user_name    = trim($input["user_name"] ?? "Customer"); 
$total_amount = trim($input["total_amount"] ?? ""); // Grand Total

// 🟢 CRITICAL LOGIC: Normalize Input for Single vs Cart
// We normalize everything into a $bookings array so the rest of the script works for both.
$bookings = [];

if (isset($input["bookings"]) && is_array($input["bookings"])) {
    // Case 1: Cart Checkout (Already an array of items)
    $bookings = $input["bookings"];
} elseif (isset($input["workspace_title"])) {
    // Case 2: Single Booking (Direct Pay & Book)
    // We wrap the entire $input as the first item in the $bookings array
    $bookings[] = $input;
    
    // If total_amount wasn't passed globally in single booking, use the item amount
    if (empty($total_amount)) {
        $total_amount = $input['final_amount'] ?? $input['total_amount'] ?? 0;
    }
}

// --- Validation ---
if (empty($user_email)) {
    echo json_encode(["success" => false, "message" => "Missing user email address."]);
    exit;
}

if (empty($bookings)) {
    echo json_encode(["success" => false, "message" => "No booking details found. Input must contain 'bookings' array or valid single booking data."]);
    exit;
}

// --- Compose Email Content ---
$subject = "Your Booking Confirmation - Vayuhu Workspaces";

// Start HTML body
$body = "
<html>
<head><style>
body { font-family: Arial, sans-serif; color: #333; }
.table { border-collapse: collapse; width: 100%; margin-top: 10px; margin-bottom: 20px; }
.table td, .table th { border: 1px solid #ddd; padding: 8px; }
.table th { background-color: #f97316; color: white; text-align: left; width: 35%; }
.total-block { background: #eee; padding: 10px; text-align: right; font-weight: bold; font-size: 1.1em; }
</style></head>
<body>
  <h2>Booking Confirmation</h2>
  <p>Dear $user_name,</p>
  <p>Thank you for booking with <strong>Vayuhu Workspaces</strong>. Below are your booking details:</p>
";

// --- Loop through all bookings ---
foreach ($bookings as $index => $booking) {
    $workspace_title = $booking['workspace_title'] ?? 'Workspace';
    $plan_type       = ucfirst($booking['plan_type'] ?? 'Standard');
    
    $start_date      = $booking['start_date'] ?? '';
    $end_date        = $booking['end_date'] ?? '';
    
    // Format times if they exist (remove seconds)
    $start_time_raw  = $booking['start_time'] ?? '';
    $end_time_raw    = $booking['end_time'] ?? '';
    $start_time      = ($start_time_raw) ? substr($start_time_raw, 0, 5) : '';
    $end_time        = ($end_time_raw) ? substr($end_time_raw, 0, 5) : '';
    
    // Amount logic
    $item_amount     = $booking['final_amount'] ?? $booking['total_amount'] ?? 0;
    
    // Capture Seat Codes (Handle array or string)
    $seat_codes_raw  = $booking['selected_codes'] ?? $booking['seat_codes'] ?? '';
    $seat_codes      = is_array($seat_codes_raw) ? implode(", ", $seat_codes_raw) : $seat_codes_raw;

    // Optional fields
    $item_coupon     = $booking['coupon_code'] ?? ''; 
    $booking_ref     = $booking['booking_id'] ?? ''; // Generated ID if passed

    $body .= "
    <table class='table'>
      <tr><th colspan='2'>Item #" . ($index + 1) . ": $workspace_title</th></tr>
      " . (!empty($booking_ref) ? "<tr><th>Booking ID</th><td>{$booking_ref}</td></tr>" : "") . "
      <tr><th>Plan Type</th><td>{$plan_type}</td></tr>
      
      " . (!empty($seat_codes) ? "<tr><th>Seat Numbers</th><td><strong>{$seat_codes}</strong></td></tr>" : "") . "
      
      <tr><th>Start Date</th><td>{$start_date}</td></tr>
      <tr><th>End Date</th><td>{$end_date}</td></tr>
      
      " . (!empty($start_time) ? "<tr><th>Time</th><td>{$start_time} - {$end_time}</td></tr>" : "") . "
      
      <tr><th>Amount</th><td>₹{$item_amount}</td></tr>
      
      " . (!empty($item_coupon) ? "<tr><th>Coupon Applied</th><td>{$item_coupon}</td></tr>" : "") . "
    </table>";
}

// Grand Total
$body .= "
  <div class='total-block'>
      Grand Total Paid: ₹{$total_amount}
  </div>
";

// End HTML body
$body .= "
  <p>We look forward to hosting you.</p>
  <p><strong>— Team Vayuhu</strong></p>
</body>
</html>
";

// --- Setup PHPMailer ---
$mail = new PHPMailer(true);

try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; 
    $mail->SMTPAuth   = true;
    $mail->Username   = 'k24517165@gmail.com'; 
    $mail->Password   = 'ojnp mnka xorh mdch'; 
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('k24517165@gmail.com', 'Vayuhu Workspaces');
    $mail->addAddress($user_email);             
    $mail->addBCC('admin@vayuhu.com');          

    // Content
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $body;

    $mail->send();
    echo json_encode(["success" => true, "message" => "Email sent successfully"]);
} catch (Exception $e) {
    // Log error to server logs
    error_log("Mailer Error: " . $mail->ErrorInfo);
    echo json_encode(["success" => false, "message" => "Mailer Error: " . $mail->ErrorInfo]);
}
?>