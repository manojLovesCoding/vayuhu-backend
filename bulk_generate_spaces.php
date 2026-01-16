<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Handles CORS & OPTIONS requests

// ------------------------------------
// Error Handling
// ------------------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// ------------------------------------
// JWT VERIFICATION
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ----------------------------
// JWT VERIFICATION via Cookie
// ----------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// Get token from HttpOnly cookie
$token = $_COOKIE['auth_token'] ?? null;

if (!$token) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authentication token missing"]);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Token verified. User info in $decoded->data
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}


// ------------------------------------
// Database Connection
// ------------------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// ------------------------------------
// Read Input (supports JSON body or FormData for image upload)
// ------------------------------------
$body = json_decode(file_get_contents('php://input'), true);
$group = trim($_POST['group'] ?? $body['group'] ?? '');
$defaults = json_decode($_POST['defaults'] ?? '{}', true);

if (!$group) {
    echo json_encode(['success' => false, 'message' => 'Missing group parameter']);
    exit;
}

// ------------------------------------
// Mapping groups
// ------------------------------------
$groups = [
    "Workspace" => ['prefix' => 'WS', 'max' => 45],
    "Team Leads Cubicle" => ['prefix' => 'TLC', 'max' => 4],
    "Manager Cubicle" => ['prefix' => 'MC', 'max' => 2],
    "Video Conferencing" => ['prefix' => 'VC', 'max' => 1],
    "Executive Cabin" => ['prefix' => 'EC', 'max' => 2],
    "CEO Cabin" => ['prefix' => 'CD', 'max' => 1],
];

if (!isset($groups[$group])) {
    echo json_encode(['success' => false, 'message' => 'Invalid group']);
    exit;
}

$prefix = $groups[$group]['prefix'];
$max = (int)$groups[$group]['max'];

// Fetch existing codes
$stmt = $conn->prepare("SELECT space_code FROM spaces WHERE space_code LIKE CONCAT(?, '%')");
$stmt->bind_param("s", $prefix);
$stmt->execute();
$res = $stmt->get_result();
$existing = array_column($res->fetch_all(MYSQLI_ASSOC), 'space_code');
$stmt->close();

// Generate desired codes
$allDesired = [];
for ($i = 1; $i <= $max; $i++) {
    $allDesired[] = $prefix . str_pad($i, 2, "0", STR_PAD_LEFT);
}

$toCreate = array_values(array_diff($allDesired, $existing));
$skipped = array_values(array_intersect($allDesired, $existing));

if (empty($toCreate)) {
    echo json_encode([
        'success' => true,
        'message' => 'All codes already exist',
        'created_count' => 0,
        'skipped_count' => count($skipped),
        'created_codes' => [],
        'skipped_codes' => $skipped
    ]);
    exit;
}

// Handle uploaded image (optional)
$imagePath = '';
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $tmp = $_FILES['image']['tmp_name'];
    $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
    $imageName = $prefix . '_' . time() . '.' . $ext;
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    move_uploaded_file($tmp, $uploadDir . $imageName);
    $imagePath = 'uploads/' . $imageName;
}

// ------------------------------------
// Insert codes with transaction
// ------------------------------------
$conn->begin_transaction();
$created = [];
$error = null;

$insertSql = "INSERT INTO spaces
    (space_code, space, per_hour, per_day, one_week, two_weeks, three_weeks, per_month, min_duration, min_duration_desc, max_duration, max_duration_desc, image, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmtInsert = $conn->prepare($insertSql);

if (!$stmtInsert) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}

foreach ($toCreate as $code) {
    $spaceName = $group; // can be "Workspace" or "Workspace - WS01"
    $values = [
        $code,
        $spaceName,
        $defaults['per_hour'] ?? "",
        $defaults['per_day'] ?? "",
        $defaults['one_week'] ?? "",
        $defaults['two_weeks'] ?? "",
        $defaults['three_weeks'] ?? "",
        $defaults['per_month'] ?? "",
        $defaults['min_duration'] ?? "",
        $defaults['min_duration_desc'] ?? "",
        $defaults['max_duration'] ?? "",
        $defaults['max_duration_desc'] ?? "",
        $imagePath,
        "Active"
    ];

    if (!$stmtInsert->bind_param(str_repeat("s", count($values)), ...$values)) {
        $error = "Bind failed: " . $stmtInsert->error;
        break;
    }

    if (!$stmtInsert->execute()) {
        $error = "Insert failed for {$code}: " . $stmtInsert->error;
        break;
    }

    $created[] = $code;
}

if ($error) {
    $conn->rollback();
    $stmtInsert->close();
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
} else {
    $conn->commit();
    $stmtInsert->close();
    echo json_encode([
        'success' => true,
        'message' => 'Bulk generation completed',
        'created_count' => count($created),
        'skipped_count' => count($skipped),
        'created_codes' => $created,
        'skipped_codes' => $skipped
    ]);
    exit;
}
?>
