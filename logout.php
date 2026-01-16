<?php
require_once __DIR__ . '/config/cors.php';

// ------------------------------------
// Set JSON response header
// ------------------------------------
header("Content-Type: application/json; charset=UTF-8");

// ------------------------------------
// Remove auth cookie
// ------------------------------------
if (isset($_COOKIE['auth_token'])) {
    setcookie(
        "auth_token",
        "",
        [
            "expires"  => time() - 3600, // Expire in the past
            "path"     => "/",
            "secure"   => false, // TRUE in HTTPS
            "httponly" => true,
            "samesite" => "Lax"
        ]
    );
}

// ------------------------------------
// Send success response
// ------------------------------------
echo json_encode([
    "status" => "success",
    "message" => "Logged out successfully."
]);
