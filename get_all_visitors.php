<?php
// --- CORS Configuration ---
$allowed_origin = "http://localhost:5173"; // your React app runs on this port (Vite)
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ✅ NEW: Include JWT Library
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ✅ Define the secret key (must match your login/signup key)
$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

// ------------------------------------
// ✅ NEW: JWT VERIFICATION LOGIC
// ------------------------------------
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Successfully verified. User info is in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

require_once 'db.php'; 

// --- Query Visitors ---
// UPDATED SQL: Added LEFT JOIN for 'admins' and logic to select the correct name
$sql = "
   SELECT 
    v.id,
    v.user_id,
    v.admin_id,
    v.company_id,
    v.name,
    v.contact_no,
    v.email,
    v.company_name,
    v.visiting_date,
    v.check_in_time,
     v.check_out_time,  -- Add this line
    v.reason,
    v.added_on,
    u.name AS user_name,
    a.name AS admin_name
FROM visitors v
LEFT JOIN users u ON v.user_id = u.id
LEFT JOIN admins a ON v.admin_id = a.id
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
    
    // LOGIC: Determine who added the visitor
    $added_by_name = "Unknown";
    
    if (!empty($row['user_name'])) {
        $added_by_name = $row['user_name']; // Added by Staff
    } elseif (!empty($row['admin_name'])) {
        $added_by_name = $row['admin_name'] . " (Admin)"; // Added by Admin
    }

    $visitors[] = [
    "id" => (int)$row['id'],
    "user_id" => $row['user_id'] ? (int)$row['user_id'] : null,
    "company_id" => $row['company_id'] ? (int)$row['company_id'] : null,
    "name" => $row['name'], // ✅ MATCHES REACT
    "contact" => $row['contact_no'], // ✅ MATCHES REACT
    "email" => $row['email'],
    "company_name" => $row['company_name'],
    "visiting_date" => $row['visiting_date'],
   "check_in_time" => $row['check_in_time'],       // Use check_in_time here
    "check_out_time" => $row['check_out_time'],     // Add check_out_time here
    "reason" => $row['reason'],
    "added_on" => $row['added_on'],
    "user_name" => $added_by_name
];

}

echo json_encode([
    "success" => true,
    "visitors" => $visitors
]);

$conn->close();
?>