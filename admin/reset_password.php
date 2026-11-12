<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$new_password = password_hash('123456', PASSWORD_DEFAULT);

$conn->query("UPDATE users SET password='$new_password' WHERE user_id=$id");

$_SESSION['flash_message'] = "Password reset to default: 123456";
header("Location: manage_users.php");
exit;
