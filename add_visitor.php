<?php
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true"); 
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "VAYUHU_SECRET_KEY_CHANGE_THIS";

require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid request data"]);
    exit;
}

// ------------------------------------
// ✅ JWT VERIFICATION LOGIC
// ------------------------------------
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
    $decoded_user_id = $decoded->data->id; 
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid or expired token"]);
    exit;
}

$user_id = $data['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(["success" => false, "message" => "User ID required"]);
    exit;
}

if ((int)$decoded_user_id !== (int)$user_id) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Unauthorized access: Identity mismatch."]);
    exit;
}

// ✅ Fetch company info automatically
$company_id = null;
$company_name = null;

$getCompany = $conn->prepare("SELECT id, company_name FROM company_profile WHERE user_id = ?");
$getCompany->bind_param("i", $user_id);
$getCompany->execute();
$getCompany->bind_result($company_id, $company_name);
$getCompany->fetch();
$getCompany->close();

if (!$company_id) {
    echo json_encode(["success" => false, "message" => "No company profile found for this user"]);
    exit;
}

// ✅ Collect visitor fields
$name          = trim($data['name'] ?? "");
$contact       = trim($data['contact'] ?? "");
$email         = trim($data['email'] ?? "");
$visitingDate  = trim($data['visitingDate'] ?? "");
$visitingTime  = trim($data['visitingTime'] ?? "");
$reason        = trim($data['reason'] ?? "");

// ✅ NEW: Collect Payment fields
$payment_id    = trim($data['payment_id'] ?? ""); // From Razorpay
$amount_paid   = (float)($data['amount_paid'] ?? 0); // Amount paid for the pass

// ✅ Validation
if (empty($name) || empty($contact)) {
    echo json_encode(["success" => false, "message" => "Name and Contact No are required"]);
    exit;
}

// ✅ UPDATED: Insert into database including payment fields
// Added 'payment_id' and 'amount_paid' to the column list and values
$sql = "INSERT INTO visitors (user_id, company_id, name, contact_no, email, company_name, visiting_date, visiting_time, reason, payment_id, amount_paid, added_on)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

$stmt = $conn->prepare($sql);

// ✅ UPDATED: bind_param updated to "iissssssssd" 
// (added 's' for payment_id and 'd' for double/float amount_paid)
$stmt->bind_param("iissssssssd", 
    $user_id, 
    $company_id, 
    $name, 
    $contact, 
    $email, 
    $company_name, 
    $visitingDate, 
    $visitingTime, 
    $reason,
    $payment_id,
    $amount_paid
);

if ($stmt->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Visitor added successfully",
        "visitorId" => $stmt->insert_id
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();
?>