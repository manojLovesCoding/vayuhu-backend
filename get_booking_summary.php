<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

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
// Load JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    include "db.php";

    // ------------------------------------
    // JWT VERIFICATION from HttpOnly cookie
    // ------------------------------------
    if (!isset($_COOKIE['auth_token'])) {
        throw new Exception("Authentication token missing.");
    }

    $token = $_COOKIE['auth_token'];

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        $decoded_user_id = $decoded->data->id; // ID from the secure token
    } catch (Exception $e) {
        http_response_code(401); // Unauthorized
        throw new Exception("Invalid or expired token.");
    }

    // Read request body
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload.");
    }

    $user_id = (int)($data['user_id'] ?? 0);
    if ($user_id <= 0) {
        throw new Exception("Invalid user_id.");
    }

    // SECURITY CHECK: Ensure token ID matches requested user_id
    if ($decoded_user_id !== $user_id) {
        http_response_code(403); // Forbidden
        throw new Exception("Unauthorized access to this dashboard.");
    }

    // ------------------------------------
    // Fetch all bookings for the user
    // ------------------------------------
    $stmt = $conn->prepare("
        SELECT 
            wb.booking_id,
            wb.space_id,
            s.space_code,
            wb.seat_codes, 
            wb.workspace_title,
            wb.plan_type,
            wb.start_date,
            wb.end_date,
            wb.start_time,
            wb.end_time,
            wb.total_days,
            wb.total_hours,
            wb.num_attendees,
            wb.price_per_unit,
            wb.base_amount,
            wb.gst_amount,
            wb.discount_amount,
            wb.final_amount,
            wb.status,
            wb.created_at
        FROM workspace_bookings wb
        JOIN spaces s ON wb.space_id = s.id
        WHERE wb.user_id = ?
        ORDER BY wb.created_at DESC
    ");
    
    if (!$stmt) {
        throw new Exception("Database prepare() failed.");
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $bookings = [];
    $today = date("Y-m-d");
    $summary = [
        "total" => 0,
        "ongoing" => 0,
        "completed" => 0,
        "upcoming" => 0
    ];

    while ($row = $result->fetch_assoc()) {
        // Format times
        if (!empty($row['start_time'])) {
            $row['start_time'] = date("h:i A", strtotime($row['start_time']));
        }
        if (!empty($row['end_time'])) {
            $row['end_time'] = date("h:i A", strtotime($row['end_time']));
        }

        // Format dates
        $row['start_date'] = date("Y-m-d", strtotime($row['start_date']));
        $row['end_date']   = date("Y-m-d", strtotime($row['end_date']));

        $bookings[] = $row;
        $summary["total"]++;

        // Categorize bookings
        if ($row['start_date'] <= $today && $row['end_date'] >= $today) {
            $summary["ongoing"]++;
        } elseif ($row['end_date'] < $today) {
            $summary["completed"]++;
        } elseif ($row['start_date'] > $today) {
            $summary["upcoming"]++;
        }
    }

    echo json_encode([
        "success" => true,
        "summary" => $summary,
        "bookings" => $bookings
    ]);

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    if (http_response_code() == 200) http_response_code(400); 
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
