<?php
// ------------------------------------
// Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Database Connection
// ------------------------------------
require_once "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// Get Blog by ID
// ------------------------------------
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(["success" => false, "message" => "Invalid blog ID"]);
    exit;
}

// ------------------------------------
// Prepared Query
// ------------------------------------
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
    echo json_encode([
        "success" => false,
        "message" => "SQL Error: " . $conn->error
    ]);
    exit;
}

if ($result->num_rows === 0) {
    echo json_encode(["success" => false, "message" => "Blog not found"]);
    exit;
}

$row = $result->fetch_assoc();

// ------------------------------------
// Construct Full Image URL
// ------------------------------------
$row["blog_image"] = !empty($row["blog_image"])
    ? "http://localhost/vayuhuBackend/" . $row["blog_image"]
    : null;

// ------------------------------------
// Return JSON Response
// ------------------------------------
echo json_encode(
    ["success" => true, "blog" => $row],
    JSON_UNESCAPED_SLASHES
);

$stmt->close();
$conn->close();
