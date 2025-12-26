<?php
// -------------------------
// CORS
// -------------------------
$allowed_origin = "http://localhost:5173";
header("Access-Control-Allow-Origin: $allowed_origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
// ✅ Added Authorization to allowed headers
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=UTF-8");

// -------------------------
// Prevent PHP Warnings from breaking JSON
// -------------------------
ini_set("display_errors", 0);
error_reporting(E_ALL);

// -------------------------
// ✅ JWT VERIFICATION (ADDED)
// -------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS"; // Must match your other scripts

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header missing"]);
    exit;
}

$token = str_replace('Bearer ', '', $authHeader);

try {
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    // Successfully verified
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

// -------------------------
// Database
// -------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Read JSON body or POST form (for image)
// ADDED: support FormData (image upload)
$body = json_decode(file_get_contents('php://input'), true);
$group = trim($_POST['group'] ?? $body['group'] ?? '');
$defaults = json_decode($_POST['defaults'] ?? '{}', true);

if (!$group) {
    echo json_encode(['success' => false, 'message' => 'Missing group parameter']);
    exit;
}

// Mapping - keep synced with frontend
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

// Fetch existing codes efficiently
$stmt = $conn->prepare("SELECT space_code FROM spaces WHERE space_code LIKE CONCAT(?, '%')");
$stmt->bind_param("s", $prefix);
$stmt->execute();
$res = $stmt->get_result();
$existing = array_column($res->fetch_all(MYSQLI_ASSOC), 'space_code');
$stmt->close();

// Generate all desired codes
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

// ADDED: handle uploaded image
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

// Insert with prepared statement and transaction
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

// Insert minimal defaults
foreach ($toCreate as $code) {
    $spaceName = $group; // can be "Workspace" or "Workspace - WS01"

    // ADDED: use defaults from frontend
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

    // Bind and execute
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