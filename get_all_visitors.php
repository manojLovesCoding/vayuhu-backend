<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// JWT Secret
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// JWT Verification (HttpOnly Cookie)
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Auth cookie missing"]);
    exit;
}

$token = $_COOKIE['auth_token'];

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// Database connection
// ------------------------------------
require_once 'db.php';

// ------------------------------------
// Query Visitors
// ------------------------------------
$sql = "
    SELECT 
        v.id,
        v.user_id,
        v.admin_id,
        v.company_id,
        v.booking_id,
        s.space_code,
        v.name,
        v.contact_no,
        v.email,
        v.company_name,
        v.visiting_date,
        v.check_in_time,
        v.check_out_time,
        v.amount_paid,
        v.attendees,
        v.reason,
        v.added_on,
        u.name AS user_name,
        a.name AS admin_name
    FROM visitors v
    LEFT JOIN users u ON v.user_id = u.id
    LEFT JOIN admins a ON v.admin_id = a.id
    LEFT JOIN workspace_bookings wb ON v.booking_id = wb.booking_id
    LEFT JOIN spaces s ON wb.space_id = s.id
    ORDER BY v.added_on DESC
";

$result = $conn->query($sql);

if (!$result) {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $conn->error
    ]);
    exit;
}

$visitors = [];
while ($row = $result->fetch_assoc()) {
    $first_adder = "Unknown";
    if (!empty($row['user_name'])) {
        $first_adder = $row['user_name'];
    } elseif (!empty($row['admin_name'])) {
        $first_adder = $row['admin_name'] . " (Admin)";
    }

    $check_in_str = (string)$row['check_in_time'];

    if (strpos($check_in_str, '|') !== false) {
        $slots = explode('|', $check_in_str);
        $name_parts = [$first_adder];
        for ($i = 1; $i < count($slots); $i++) {
            $name_parts[] = "Admin";
        }
        $final_added_by = implode(' | ', $name_parts);
    } else {
        $final_added_by = $first_adder;
    }

    $visitors[] = [
        "id" => (int)$row['id'],
        "user_id" => $row['user_id'] ? (int)$row['user_id'] : null,
        "company_id" => $row['company_id'] ? (int)$row['company_id'] : null,
        "space_code" => $row['space_code'] ?? null,
        "name" => $row['name'],
        "contact" => $row['contact_no'],
        "email" => $row['email'],
        "company_name" => $row['company_name'],
        "visiting_date" => $row['visiting_date'],
        "check_in_time" => $row['check_in_time'],
        "check_out_time" => $row['check_out_time'],
        "amount_paid" => $row['amount_paid'],
        "attendees" => (int)($row['attendees'] ?? 1),
        "reason" => $row['reason'],
        "added_on" => $row['added_on'],
        "user_name" => $final_added_by
    ];
}

echo json_encode([
    "success" => true,
    "visitors" => $visitors
]);

$conn->close();
