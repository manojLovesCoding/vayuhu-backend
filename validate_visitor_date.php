<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid request"]);
    exit;
}

// ---------------- JWT CHECK ----------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization missing"]);
    exit;
}

$token = str_replace("Bearer ", "", $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ---------------- INPUT ----------------
$user_id       = $data['user_id'] ?? null;
$booking_id    = $data['booking_id'] ?? null;
$visitingDate  = $data['visitingDate'] ?? null;

if (!$user_id || !$booking_id || !$visitingDate) {
    echo json_encode([
        "success" => false,
        "message" => "user_id, booking_id and visitingDate are required"
    ]);
    exit;
}

if ((int)$user_id !== (int)$decoded_user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Identity mismatch"]);
    exit;
}

// ---------------- BOOKING VALIDATION ----------------
$stmt = $conn->prepare("
    SELECT start_date, end_date 
    FROM workspace_bookings 
    WHERE booking_id = ? AND user_id = ?
");
$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();
$stmt->bind_result($start_date, $end_date);
$stmt->fetch();
$stmt->close();

if (!$start_date) {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found or unauthorized"
    ]);
    exit;
}

if ($visitingDate < $start_date || $visitingDate > $end_date) {
    echo json_encode([
        "success" => false,
        "message" => "Visiting date must be within booking duration",
        "allowed_from" => $start_date,
        "allowed_to" => $end_date
    ]);
    exit;
}

// ---------------- SUCCESS ----------------
echo json_encode([
    "success" => true,
    "message" => "Visiting date is valid"
]);

$conn->close();
