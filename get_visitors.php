<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Include Database Connection
// ------------------------------------
require_once 'db.php';

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT Secret
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// Read Input JSON
// ------------------------------------
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid JSON input."]);
    exit;
}

// ------------------------------------
// JWT VERIFICATION FROM COOKIE
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization token missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $decoded_user_id = $decoded->data->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Validate User Identity
// ------------------------------------
$user_id = $data['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access: Identity mismatch."]);
    exit;
}

// ------------------------------------
// Fetch Visitors
// ------------------------------------
$sql = "
    SELECT DISTINCT
        v.id,
        v.name,
        v.contact_no,
        v.email,
        v.visiting_date,
        v.check_in_time,
        v.check_out_time,
        v.reason,
        v.attendees,
        v.payment_id,
        v.amount_paid,
        v.added_on,
        c.company_name,
        wb.workspace_title as host_workspace
    FROM visitors v
    LEFT JOIN company_profile c ON v.company_id = c.id
    LEFT JOIN workspace_bookings wb ON v.booking_id = wb.booking_id
    WHERE v.user_id = ?
    ORDER BY v.id DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$visitors = [];
while ($row = $result->fetch_assoc()) {
    $visitors[] = [
        "id"            => $row["id"],
        "name"          => $row["name"],
        "contact"       => $row["contact_no"],
        "email"         => $row["email"],
        "company_name"  => $row["company_name"] ?: "—",
        "workspace"     => $row["host_workspace"] ?: "Manual Entry",
        "visiting_date" => $row["visiting_date"],
        "check_in_time" => $row["check_in_time"],
        "check_out_time"=> $row["check_out_time"],
        "reason"        => $row["reason"],
        "attendees"     => $row["attendees"],
        "payment_id"    => $row["payment_id"],
        "amount_paid"   => $row["amount_paid"],
        "added_on"      => $row["added_on"]
    ];
}

// ------------------------------------
// Return Response
// ------------------------------------
if (count($visitors) > 0) {
    echo json_encode(["success" => true, "visitors" => $visitors]);
} else {
    echo json_encode(["success" => false, "message" => "No visitors found"]);
}

$stmt->close();
$conn->close();
