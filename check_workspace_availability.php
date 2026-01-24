<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/cors.php';

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

    // Standardize time formats for comparison
    if ($start_time && strlen($start_time) === 5) $start_time .= ":00";
    if ($end_time && strlen($end_time) === 5) $end_time .= ":00";

    // --- HOURLY INCREMENT VALIDATION (BACKEND SAFETY) ---
    if ($plan_type === 'hourly' && $start_time && $end_time) {
        $t1 = new DateTime($start_time);
        $t2 = new DateTime($end_time);
        if ($t1->format('i') !== $t2->format('i')) {
            throw new Exception("Bookings must be in full-hour increments.");
        }
    }

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
    $stmt->bind_param("issssss", $space_id, $start_date, $end_time, $start_time, $start_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $hasFullDayBlock = false;
        $blockedEndDate = null;
        
        while ($row = $result->fetch_assoc()) {
            if (in_array($row['plan_type'], ['daily', 'monthly'])) {
                if ($start_date >= $row['start_date'] && $start_date <= $row['end_date']) {
                    $hasFullDayBlock = true;
                    $blockedEndDate = $row['end_date'];
                    break;
                }
            }
        }

        if ($hasFullDayBlock) {
            $nextAvailableDate = date('Y-m-d', strtotime($blockedEndDate . ' +1 day'));
            echo json_encode([
                "success" => false,
                "message" => "This workspace is fully booked for the selected date.",
                "available_dates" => ["from" => $nextAvailableDate]
            ]);
            exit;
        }

        // If Hourly Conflict: Suggest 1-hour slots matching 15-min intervals
        if ($plan_type === 'hourly') {
            $bookStmt = $conn->prepare("
                SELECT start_time, end_time
                FROM workspace_bookings
                WHERE space_id = ? AND start_date = ? AND status IN ('confirmed', 'pending')
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

            $availableSlots = [];
            // Office Hours: 08:00 to 20:00 (8 PM)
            // We check every 15-minute start possibility
            for ($h = 8; $h < 20; $h++) {
                for ($m = 0; $m < 60; $m += 15) {
                    $slotStart = sprintf("%02d:%02d:00", $h, $m);
                    // End time is exactly 1 hour later per your requirement
                    $slotEnd = sprintf("%02d:%02d:00", $h + 1, $m);
                    
                    if ($h + 1 > 20 || ($h + 1 == 20 && $m > 0)) continue;

                    $isAvailable = true;
                    foreach ($bookedRanges as $b) {
                        // Overlap logic
                        if (!($slotEnd <= $b['start'] || $slotStart >= $b['end'])) {
                            $isAvailable = false;
                            break;
                        }
                    }

                    if ($isAvailable) {
                        $availableSlots[] = date("g:i A", strtotime($slotStart)) . " - " . date("g:i A", strtotime($slotEnd));
                    }
                }
            }

            echo json_encode([
                "success" => false,
                "message" => "The selected time slot is already booked.",
                "available_slots" => array_values(array_unique($availableSlots))
            ]);
            exit;
        }

        echo json_encode(["success" => false, "message" => "Workspace unavailable for selected dates."]);
        exit;
    }

    echo json_encode(["success" => true, "message" => "Workspace available!"]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>