<?php
// ------------------------------------
// Load Environment & Centralized CORS
// ------------------------------------
require_once __DIR__ . '/config/env.php';   // Loads $_ENV['JWT_SECRET']
require_once __DIR__ . '/config/cors.php';  // Sets CORS headers & handles OPTIONS preflight

// ------------------------------------
// Database connection
// ------------------------------------
include "db.php";
if (!$conn) {
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

// ------------------------------------
// ✅ Include JWT Library
// ------------------------------------
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ------------------------------------
// ✅ JWT SECRET FROM ENV
// ------------------------------------
$secret_key = $_ENV['JWT_SECRET'] ?? die("JWT_SECRET not set in .env");

// ------------------------------------
// ✅ JWT Verification (from HttpOnly cookie)
// ------------------------------------
try {
    $token = $_COOKIE['auth_token'] ?? null;
    if (!$token) {
        http_response_code(401);
        throw new Exception("Authorization token missing in cookies.");
    }

    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    $userData = (array)$decoded->data; // Access user info if needed
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
    exit;
}

// ------------------------------------
// Handle multipart/form-data (file upload)
if (isset($_POST['id'])) {
    $id       = $_POST['id'];
    $name     = $_POST['name'] ?? '';
    $email    = $_POST['email'] ?? '';
    $phone    = $_POST['phone'] ?? '';
    $dob      = $_POST['dob'] ?? '';
    $address  = $_POST['address'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($id) || empty($name) || empty($phone)) {
        echo json_encode(["success" => false, "message" => "Missing required fields"]);
        exit;
    }

    // Handle profile picture upload
    $profile_pic_path = null;
    if (isset($_FILES['profilePic']) && $_FILES['profilePic']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . "/uploads/profile_pics/";
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

        $file_tmp  = $_FILES['profilePic']['tmp_name'];
        $file_name = uniqid("user_") . "_" . basename($_FILES['profilePic']['name']);
        $target_path = $upload_dir . $file_name;

        if (move_uploaded_file($file_tmp, $target_path)) {
            $profile_pic_path = "uploads/profile_pics/" . $file_name;
        }
    }

    // Build dynamic SQL
    if (!empty($password)) {
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        if ($profile_pic_path) {
            $sql = "UPDATE users 
                    SET name=?, email=?, phone=?, dob=?, address=?, password=?, profile_pic=? 
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssssi", $name, $email, $phone, $dob, $address, $hashedPassword, $profile_pic_path, $id);
        } else {
            $sql = "UPDATE users 
                    SET name=?, email=?, phone=?, dob=?, address=?, password=? 
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssi", $name, $email, $phone, $dob, $address, $hashedPassword, $id);
        }
    } else {
        if ($profile_pic_path) {
            $sql = "UPDATE users 
                    SET name=?, email=?, phone=?, dob=?, address=?, profile_pic=? 
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssi", $name, $email, $phone, $dob, $address, $profile_pic_path, $id);
        } else {
            $sql = "UPDATE users 
                    SET name=?, email=?, phone=?, dob=?, address=? 
                    WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssi", $name, $email, $phone, $dob, $address, $id);
        }
    }

    if ($stmt->execute()) {
        echo json_encode(["success" => true, "message" => "User updated successfully"]);
    } else {
        echo json_encode(["success" => false, "message" => "Database update failed"]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// ------------------------------------
// Handle JSON input (no file upload)
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    echo json_encode(["success" => false, "message" => "Invalid input data"]);
    exit;
}

$id       = $data['id'] ?? null;
$name     = $data['name'] ?? '';
$email    = $data['email'] ?? '';
$phone    = $data['phone'] ?? '';
$dob      = $data['dob'] ?? '';
$address  = $data['address'] ?? '';
$password = $data['password'] ?? '';

if (!$id || !$name || !$email || !$phone) {
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
}

if (!empty($password)) {
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $sql = "UPDATE users 
            SET name=?, email=?, phone=?, dob=?, address=?, password=? 
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssi", $name, $email, $phone, $dob, $address, $hashedPassword, $id);
} else {
    $sql = "UPDATE users 
            SET name=?, email=?, phone=?, dob=?, address=? 
            WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssi", $name, $email, $phone, $dob, $address, $id);
}

if ($stmt->execute()) {
    echo json_encode(["success" => true, "message" => "User updated successfully"]);
} else {
    echo json_encode(["success" => false, "message" => "Failed to update user"]);
}

$stmt->close();
$conn->close();
