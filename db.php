<?php
$servername = "localhost";
$username = "root"; // default for XAMPP
$password = "";     // leave blank
$dbname = "vayuhu_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
  die(json_encode(["status" => "error", "message" => "Database connection failed."]));
}
?>
