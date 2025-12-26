<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Credentials: true");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your login script

try {
    // ------------------------------------
    // ✅ NEW: JWT VERIFICATION LOGIC
    // ------------------------------------
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

    if (!$authHeader) {
        http_response_code(401);
        throw new Exception("Authorization header missing. Please log in.");
    }

    // Extract token from "Bearer <token>"
    $token = str_replace('Bearer ', '', $authHeader);

    try {
        $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
        // User is authenticated
    } catch (Exception $e) {
        http_response_code(401);
        throw new Exception("Invalid or expired token. Please log in again.");
    }
    // ------------------------------------

    include 'db.php';

    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Invalid JSON payload.");

    $space_id   = (int)($data['space_id'] ?? 0);
    $plan_type  = strtolower(trim($data['plan_type'] ?? ''));
    $start_date = trim($data['start_date'] ?? '');
    $end_date   = trim($data['end_date'] ?? '');
    $start_time = trim($data['start_time'] ?? '');
    $end_time   = trim($data['end_time'] ?? '');

    if ($space_id <= 0 || !$plan_type || !$start_date || !$end_date) {
        throw new Exception("Missing required parameters.");
    }

    // Basic validation
    if (!in_array($plan_type, ['hourly', 'daily', 'monthly'])) {
        throw new Exception("Invalid plan_type value.");
    }

    // Normalize time format
    if ($start_time && strlen($start_time) === 5) $start_time .= ":00";
    if ($end_time && strlen($end_time) === 5) $end_time .= ":00";

    // --- Check existing bookings for this space ---
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
        LIMIT 1
    ");
    $stmt->bind_param("issssss", $space_id, $start_date, $end_time, $start_time, $start_date, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    // --- If conflict found ---
    if ($result && $result->num_rows > 0) {
        $conflict = $result->fetch_assoc();
        $stmt->close();

        // 🟠 If the conflict is hourly, compute and return available hourly slots
        if ($plan_type === 'hourly') {
            // Get all hourly bookings for this date
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
                $bookedRanges[] = [
                    'start' => $row['start_time'],
                    'end'   => $row['end_time']
                ];
            }
            $bookStmt->close();

            // Workspace working hours
            $openingHour = 8;  // 08:00
            $closingHour = 19; // 19:59

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
                    // Convert to readable 12-hour format
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

        // For daily/monthly conflict, respond normally
        echo json_encode([
            "success" => false,
            "message" => "This workspace is already booked for the selected time/date."
        ]);
        exit;
    }

    $stmt->close();

    // ✅ If no conflict found
    echo json_encode([
        "success" => true,
        "message" => "Workspace available for booking."
    ]);

} catch (Exception $e) {
    // Determine status code: 401 for auth, 400 for general
    $statusCode = ($e->getCode() === 401 || strpos($e->getMessage(), "token") !== false) ? 401 : 400;
    http_response_code($statusCode);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>