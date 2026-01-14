<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Handles CORS & OPTIONS requests

// ------------------------------------
// Error Handling
// ------------------------------------
ini_set('display_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// JWT VERIFICATION
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

try {
    // -------------------------------
    // Get token from HttpOnly cookie
    // -------------------------------
    $token = $_COOKIE['auth_token'] ?? null;

    if (!$token) {
        http_response_code(401);
        throw new Exception("Authorization token missing. Please log in.");
    }

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token. Please log in again.");
    }
    // Token verified. User info in $decoded->data

    // ------------------------------------
    // Database
    // ------------------------------------
    include 'db.php';

    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON payload.");
    }

    $space_id   = (int)($data['space_id'] ?? 0);
    $plan_type  = strtolower(trim($data['plan_type'] ?? ''));
    $start_date = trim($data['start_date'] ?? '');
    $end_date   = trim($data['end_date'] ?? '');
    $start_time = trim($data['start_time'] ?? '');
    $end_time   = trim($data['end_time'] ?? '');

    if ($space_id <= 0 || !$plan_type || !$start_date || !$end_date) {
        throw new Exception("Missing required parameters.");
    }

    if (!in_array($plan_type, ['hourly', 'daily', 'monthly'])) {
        throw new Exception("Invalid plan_type value.");
    }

    if ($start_time && strlen($start_time) === 5) $start_time .= ":00";
    if ($end_time && strlen($end_time) === 5) $end_time .= ":00";

    // ------------------------------------
    // EXISTING BOOKINGS CHECK
    // ------------------------------------
    $stmt = $conn->prepare("
        SELECT plan_type, start_date, end_date, start_time, end_time
        FROM workspace_bookings
        WHERE space_id = ?
          AND status IN ('confirmed', 'pending')
          AND (
                (plan_type = 'hourly' AND start_date = ? AND NOT (? <= start_time OR ? >= end_time))
             OR (plan_type = 'daily' AND start_date = ?)
             OR (plan_type = 'monthly' AND NOT (? < start_date OR ? > end_date))
              )
    ");
    $stmt->bind_param(
        "issssss",
        $space_id,
        $start_date,
        $end_time,
        $start_time,
        $start_date,
        $start_date,
        $end_date
    );
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $hasFullDayBlock = false;
        $blockedEndDate = null;
        $conflicts = [];

        while ($row = $result->fetch_assoc()) {
            $conflicts[] = $row;
            if (in_array($row['plan_type'], ['daily', 'monthly'])) {
                if ($start_date >= $row['start_date'] && $start_date <= $row['end_date']) {
                    $hasFullDayBlock = true;
                    $blockedEndDate = $row['end_date'];
                    break;
                }
            }
        }
        $stmt->close();

        if ($hasFullDayBlock) {
            $nextAvailableDate = date('Y-m-d', strtotime($blockedEndDate . ' +1 day'));
            echo json_encode([
                "success" => false,
                "message" => "This workspace is fully booked for the selected date.",
                "available_dates" => [
                    "from" => $nextAvailableDate
                ]
            ]);
            exit;
        }

        if ($plan_type === 'hourly') {
            $bookStmt = $conn->prepare("
                SELECT start_time, end_time
                FROM workspace_bookings
                WHERE space_id = ? AND start_date = ? AND plan_type = 'hourly'
                  AND status IN ('confirmed', 'pending')
                ORDER BY start_time ASC
            ");
            $bookStmt->bind_param("is", $space_id, $start_date);
            $bookStmt->execute();
            $bookings = $bookStmt->get_result();

            $bookedRanges = [];
            while ($row = $bookings->fetch_assoc()) {
                $bookedRanges[] = ['start' => $row['start_time'], 'end' => $row['end_time']];
            }
            $bookStmt->close();

            $openingHour = 8;
            $closingHour = 19;
            $availableSlots = [];

            for ($hour = $openingHour; $hour <= $closingHour; $hour++) {
                $slotStart = sprintf("%02d:00:00", $hour);
                $slotEnd   = sprintf("%02d:59:00", $hour);
                $isAvailable = true;

                foreach ($bookedRanges as $b) {
                    if (!($slotEnd <= $b['start'] || $slotStart >= $b['end'])) {
                        $isAvailable = false;
                        break;
                    }
                }

                if ($isAvailable) {
                    $displayStart = date("g:i A", strtotime($slotStart));
                    $displayEnd   = date("g:i A", strtotime($slotEnd));
                    $availableSlots[] = "$displayStart - $displayEnd";
                }
            }

            echo json_encode([
                "success" => false,
                "message" => "This workspace is already booked for the selected time/date.",
                "available_slots" => $availableSlots
            ]);
            exit;
        }

        // DAILY / MONTHLY CONFLICT
        echo json_encode([
            "success" => false,
            "message" => "This workspace is already booked for the selected time/date."
        ]);
        exit;
    }
    $stmt->close();

    // ------------------------------------
    // NO CONFLICT
    // ------------------------------------
    echo json_encode([
        "success" => true,
        "message" => "Workspace available for booking."
    ]);

} catch (Exception $e) {
    $statusCode = (
        $e->getCode() === 401 ||
        stripos($e->getMessage(), 'token') !== false
    ) ? 401 : 400;

    http_response_code($statusCode);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>
