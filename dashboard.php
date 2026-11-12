<?php
session_start();
include('includes/db_connect.php');
include('includes/auth.php');

// Redirect to login if not authenticated
if (!isLoggedIn()) {
    header('Location: index.php?error=Please login first');
    exit;
}

// Get user info
$user = getCurrentUser();
$role = $_SESSION['role'];

// Redirect to role-specific dashboard
switch ($role) {
    case 'admin':
        header('Location: admin/dashboard.php');
        break;
    case 'teacher':
        header('Location: teacher/dashboard.php');
        break;
    case 'student':
        header('Location: student/dashboard.php');
        break;
    case 'dean':
        header('Location: dean/dashboard.php');
        break;
    case 'registrar':
        header('Location: registrar/dashboard.php');
        break;
    default:
        header('Location: index.php?error=Invalid user role');
}
exit;
?>