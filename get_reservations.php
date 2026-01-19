<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

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

try {
    include "db.php";

    // ---------------- JWT VERIFICATION ----------------
    $token = $_COOKIE['auth_token'] ?? null;

    if (!$token) {
        http_response_code(401);
        throw new Exception("Authorization token missing.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));

        // Admin-only access
        if ($decoded->data->role !== 'admin') {
            throw new Exception("Unauthorized");
        }
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token.");
    }

    // ---------------- FETCH RESERVATIONS ----------------
    $sql = "
    SELECT 
        wb.booking_id AS id,
        u.name AS name,
        u.phone AS mobile_no,
        wb.workspace_title AS space,
        s.space_code AS space_code,
        wb.seat_codes AS seat_codes, 
        wb.plan_type AS pack,
        wb.start_date AS date,
        wb.end_date AS end_date,          -- ✅ Added end_date
        CONCAT(wb.start_time, ' - ', wb.end_time) AS timings,
        wb.final_amount AS amount,
        wb.discount_amount AS discount,
        wb.final_amount AS final_total,
        wb.created_at AS booked_on
    FROM workspace_bookings wb
    JOIN users u ON u.id = wb.user_id
    JOIN spaces s ON s.id = wb.space_id
    ORDER BY wb.created_at DESC
";

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("SQL Error: " . $conn->error);
    }

    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = $row;
    }

    echo json_encode([
        "success" => true,
        "reservations" => $reservations
    ]);

    $conn->close();

} catch (Exception $e) {
    if (http_response_code() == 200) {
        http_response_code(400);
    }
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>
