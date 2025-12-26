<?php
// ------------------------------------
// CORS CONFIG
// ------------------------------------
$allowed_origin = "http://localhost:5173";

header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// ✅ JWT VERIFICATION (ADDED)
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match login/signup scripts

// Get Authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

// Extract token from "Bearer <token>"
$token = str_replace('Bearer ', '', $authHeader);

try {
    // Decode and verify the token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token is valid! Proceeding to fetch data...
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// ------------------------------------
// DATABASE CONNECTION
// ------------------------------------
require_once "db.php";

if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// GET BLOG BY ID
// ------------------------------------
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid blog ID"]);
    exit;
}

// Using Prepared Statement for extra security
$sql = "SELECT 
            id,
            added_by,
            blog_heading,
            blog_description,
            blog_image,
            status,
            created_at,
            updated_at
        FROM blogs
        WHERE id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result) {
    echo json_encode(["success" => false, "message" => "SQL Error: " . $conn->error]);
    exit;
}

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Blog not found"]);
    exit;
}

$row = $result->fetch_assoc();

// Construct full image URL
$row["blog_image"] = !empty($row["blog_image"]) ? "http://localhost/vayuhu_backend/" . $row["blog_image"] : null;

echo json_encode(["success" => true, "blog" => $row], JSON_UNESCAPED_SLASHES);

$stmt->close();
$conn->close();
?>