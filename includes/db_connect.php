<?php
// Database configuration for DDU Attendance System
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'ddu_attendance_system');
}

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// Timezone for Ethiopia
date_default_timezone_set('Africa/Addis_Ababa');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>