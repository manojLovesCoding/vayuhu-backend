<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");

$upi_id = "8197479547@ybl"; // 👈 your actual UPI ID (replace this)
$payee_name = urlencode("Manoj Kumar");
// 👈 your name or business name
$amount = $_GET['amount'] ?? 0;

if ($amount <= 0) {
  echo json_encode(["success" => false, "message" => "Invalid amount"]);
  exit;
}

$upi_url = "upi://pay?pa={$upi_id}&pn={$payee_name}&am={$amount}&cu=INR";

$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($upi_url);


echo json_encode([
  "success" => true,
  "upi_url" => $upi_url,
  "qr_image" => $qr_url
]);
