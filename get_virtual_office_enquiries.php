<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // centralized CORS headers & OPTIONS handling

// ------------------------------------
// Include Database Connection
// ------------------------------------
require_once "db.php"; // must define $conn (mysqli)

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
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
// JWT VERIFICATION LOGIC (FROM COOKIE)
// ------------------------------------
if (!isset($_COOKIE['auth_token'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "JWT cookie missing"]);
    exit;
}

$token = $_COOKIE['auth_token']; // Read JWT from HttpOnly cookie

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Successfully verified. User info is in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
    exit;
}

// ====================================
// UPDATE ENQUIRY STATUS (POST)
// ====================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid input data."
        ]);
        exit;
    }

    $id     = intval($input["id"] ?? 0);
    $status = trim($input["status"] ?? "");

    if ($id <= 0 || empty($status)) {
        echo json_encode([
            "status" => "error",
            "message" => "Enquiry ID and status are required."
        ]);
        exit;
    }

    $allowedStatuses = ["New", "Follow-Up", "Ongoing", "Closed", "Pending"];

    if (!in_array($status, $allowedStatuses)) {
        echo json_encode([
            "status" => "error",
            "message" => "Invalid status value: " . $status
        ]);
        exit;
    }

    $updateSql = "UPDATE virtual_office_enquiries SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("si", $status, $id);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "Enquiry status updated successfully."
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Database error: " . $stmt->error
        ]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// ====================================
// FETCH ENQUIRIES (GET)
// ====================================
$sql = "
    SELECT 
        id,
        name,
        email,
        phone,
        referral_source,
        message,
        status,
        created_at
    FROM virtual_office_enquiries
    ORDER BY created_at DESC
";

$result = $conn->query($sql);

$enquiries = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $enquiries[] = [
            "id" => $row["id"],
            "name" => $row["name"],
            "email" => $row["email"],
            "phone" => $row["phone"],
            "referral_source" => $row["referral_source"],
            "message" => $row["message"],
            "status" => $row["status"],
            "created_at" => $row["created_at"]
        ];
    }
}

echo json_encode($enquiries);

$conn->close();
