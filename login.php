<?php
session_start();
include('includes/db_connect.php');
include('includes/auth.php');

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;
    
    if (login($username, $password)) {
        // Set remember me cookie if requested
        if ($remember) {
            $cookie_value = base64_encode($username . ':' . $password);
            setcookie('ddu_remember', $cookie_value, time() + (30 * 24 * 60 * 60), '/');
        }
        
        // Redirect to dashboard
        header('Location: dashboard.php');
        exit;
    } else {
        header('Location: index.php?error=Invalid username or password');
        exit;
    }
} else {
    header('Location: index.php');
    exit;
}
?>