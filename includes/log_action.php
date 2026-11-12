<?php
// ../includes/log_action.php

function log_action($action, $details = '', $user_id = null) {
    require_once('db_connect.php');
    
    global $pdo;
    
    $username = null;
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        $username = $user['username'] ?? null;
    } elseif (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'] ?? null;
    }
    
    // Get IP address
    $ip_address = $_SERVER['HTTP_CLIENT_IP'] ?? 
                 $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
                 $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    // Get user agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Insert log
    $stmt = $pdo->prepare("
        INSERT INTO system_logs (user_id, username, action, details, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    return $stmt->execute([$user_id, $username, $action, $details, $ip_address, $user_agent]);
}
?>