<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// JWT Secret
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// Database connection
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// Get JSON input
// ------------------------------------
$data = json_decode(file_get_contents("php://input"), true);

// ------------------------------------
// Get JWT from HttpOnly cookie
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

// ------------------------------------
// JWT verification
// ------------------------------------
try {
    $decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid token"]);
    exit;
}

$user_id = $data['user_id'] ?? null;
if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Identity mismatch"]);
    exit;
}

// ------------------------------------
// Fetch workspace bookings with hourly guest rate
// ------------------------------------
$sql = "
    SELECT 
        wb.booking_id,
        wb.workspace_title,
        wb.start_date,
        wb.end_date,
        s.per_hour as hourly_guest_rate
    FROM workspace_bookings wb
    JOIN spaces s ON wb.space_id = s.id
    WHERE wb.user_id = ? AND wb.end_date >= CURDATE()
    ORDER BY wb.start_date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$bookings = [];
while ($row = $result->fetch_assoc()) {
    $row['price_per_unit'] = $row['hourly_guest_rate']; 
    $row['start_date_raw'] = $row['start_date']; 
    $row['start_date_display'] = date("M d, Y", strtotime($row['start_date']));
    $bookings[] = $row;
}

echo json_encode(["success" => true, "bookings" => $bookings]);

$stmt->close();
$conn->close();
