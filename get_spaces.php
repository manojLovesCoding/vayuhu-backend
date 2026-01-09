<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads environment variables (JWT secret available if needed)
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Response Type
// ------------------------------------
header("Content-Type: application/json");

// ------------------------------------
// Database
// ------------------------------------
require_once "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

$baseURL = "http://localhost/vayuhuBackend";
$today = date("Y-m-d");

$sql = "SELECT 
            id,
            space_code,
            space,
            per_hour,
            per_day,
            one_week,
            two_weeks,
            three_weeks,
            per_month,
            min_duration,
            min_duration_desc,
            max_duration,
            max_duration_desc,
            image,
            status,
            created_at
        FROM spaces
        ORDER BY id DESC";

$result = $conn->query($sql);
$spaces = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        foreach ($row as $k => $v) {
            $row[$k] = $v ?? "";
        }

        if (!empty($row["image"])) {
            $row["image_url"] = $baseURL . "/" . $row["image"];
        } else {
            $row["image_url"] = "";
        }

        $spaceId = (int)$row["id"];
        $currentSpaceCode = $row["space_code"];

        $checkSql = "
            SELECT start_date, end_date, start_time, end_time, plan_type
            FROM workspace_bookings
            WHERE (space_id = ? OR seat_codes LIKE ?)
              AND status IN ('Confirmed', 'Pending')
              AND (
                    (plan_type = 'hourly'  AND start_date = ?)
                 OR (plan_type = 'daily'   AND start_date >= ?)
                 OR (plan_type = 'monthly' AND end_date >= ?)
              )
            ORDER BY start_date ASC
            LIMIT 1
        ";

        $codeSearch = "%" . $currentSpaceCode . "%";
        $stmt = $conn->prepare($checkSql);

        $bookedRow = null;
        $isAvailable = true;

        if ($stmt) {
            $stmt->bind_param("issss", $spaceId, $codeSearch, $today, $today, $today);
            $stmt->execute();
            $res2 = $stmt->get_result();

            if ($res2 && $res2->num_rows > 0) {
                $bookedRow = $res2->fetch_assoc();

                if ($bookedRow["plan_type"] === "hourly") {
                    $now = new DateTime();
                    $bookingStart = new DateTime($bookedRow["start_date"] . " " . $bookedRow["start_time"]);
                    $bookingEnd   = new DateTime($bookedRow["start_date"] . " " . $bookedRow["end_time"]);

                    if ($now >= $bookingStart && $now <= $bookingEnd) {
                        $isAvailable = false;
                    }
                } else {
                    $endDate = new DateTime($bookedRow["end_date"]);
                    if ($endDate >= new DateTime($today)) {
                        $isAvailable = false;
                    }
                }
            }
            $stmt->close();
        }

        if ($row["status"] !== "Active") {
            $row["is_available"] = false;
            $row["availability_reason"] = "Space inactive";
        } elseif (!$isAvailable) {
            $row["is_available"] = false;
            $row["availability_reason"] = "Booked";
        } else {
            $row["is_available"] = true;
            $row["availability_reason"] = "Available for booking";
        }

        $spaces[] = $row;
    }

    echo json_encode(["success" => true, "spaces" => $spaces]);
} else {
    echo json_encode(["success" => false, "message" => "No spaces found"]);
}

$conn->close();
