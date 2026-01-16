<?php
// send_booking_email.php

// -----------------------------
// Load Environment & Centralized CORS
// -----------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// -----------------------------
// Include JWT Library
// -----------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// -----------------------------
// JWT Secret
// -----------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// -----------------------------
// Include DB & PHPMailer
// -----------------------------
require_once 'db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    // -----------------------------
    // JWT Verification FROM COOKIE
    // -----------------------------
    $token = $_COOKIE['auth_token'] ?? null;  // <-- read JWT from HttpOnly cookie

    if (!$token) {
        http_response_code(401);
        throw new Exception("No session found. Please log in.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // User authenticated
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token");
    }

    // -----------------------------
    // Read JSON Input
    // -----------------------------
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) throw new Exception("Invalid input data");

    $user_id      = $input["user_id"] ?? null;
    $user_email   = trim($input["user_email"] ?? "");
    $user_name    = trim($input["user_name"] ?? "Customer"); 
    $total_amount = trim($input["total_amount"] ?? "");

    // Normalize bookings array
    $bookings = [];
    if (isset($input["bookings"]) && is_array($input["bookings"])) {
        $bookings = $input["bookings"];
    } elseif (isset($input["workspace_title"])) {
        $bookings[] = $input;
        if (empty($total_amount)) {
            $total_amount = $input['final_amount'] ?? $input['total_amount'] ?? 0;
        }
    }

    if (empty($user_email)) throw new Exception("Missing user email address");
    if (empty($bookings)) throw new Exception("No booking details found");

    // -----------------------------
    // Compose Email Content
    // -----------------------------
    $subject = "Your Booking Confirmation - Vayuhu Workspaces";

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

    foreach ($bookings as $index => $booking) {
        $workspace_title = $booking['workspace_title'] ?? 'Workspace';
        $plan_type       = ucfirst($booking['plan_type'] ?? 'Standard');
        $start_date      = $booking['start_date'] ?? '';
        $end_date        = $booking['end_date'] ?? '';
        $start_time      = isset($booking['start_time']) ? substr($booking['start_time'], 0, 5) : '';
        $end_time        = isset($booking['end_time']) ? substr($booking['end_time'], 0, 5) : '';
        $item_amount     = $booking['final_amount'] ?? $booking['total_amount'] ?? 0;
        $seat_codes_raw  = $booking['selected_codes'] ?? $booking['seat_codes'] ?? '';
        $seat_codes      = is_array($seat_codes_raw) ? implode(", ", $seat_codes_raw) : $seat_codes_raw;
        $item_coupon     = $booking['coupon_code'] ?? '';
        $booking_ref     = $booking['booking_id'] ?? '';

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

    $body .= "
      <div class='total-block'>
          Grand Total Paid: ₹{$total_amount}
      </div>
      <p>We look forward to hosting you.</p>
      <p><strong>— Team Vayuhu</strong></p>
    </body>
    </html>
    ";

    // -----------------------------
    // Send Email using PHPMailer
    // -----------------------------
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com'; 
    $mail->SMTPAuth   = true;
    $mail->Username   = 'k24517165@gmail.com'; 
    $mail->Password   = 'ojnp mnka xorh mdch'; 
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('k24517165@gmail.com', 'Vayuhu Workspaces');
    $mail->addAddress($user_email);             
    $mail->addBCC('admin@vayuhu.com');          

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $body;

    $mail->send();
    echo json_encode(["success" => true, "message" => "Email sent successfully"]);

} catch (Exception $e) {
    error_log("Mailer/JWT Error: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
