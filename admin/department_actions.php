<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$action = $_GET['action'] ?? '';
$department_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($department_id <= 0) {
    die('Invalid department ID');
}

/**
 * DELETE department
 */
if ($action === 'delete') {

    // Check if department is used by users
    $check = $conn->prepare(
        "SELECT COUNT(*) 
         FROM users 
         WHERE department_id = ?"
    );
    $check->bind_param("i", $department_id);
    $check->execute();
    $check->bind_result($used);
    $check->fetch();
    $check->close();

    if ($used > 0) {
        header('Location: manage_departments.php?error=Department in use');
        exit;
    }

    // Delete department
    $stmt = $conn->prepare(
        "DELETE FROM departments WHERE department_id = ?"
    );
    $stmt->bind_param("i", $department_id);

    if ($stmt->execute()) {
        header('Location: manage_departments.php?deleted=1');
        exit;
    } else {
        header('Location: manage_departments.php?error=Delete failed');
        exit;
    }
}

die('Invalid action');
