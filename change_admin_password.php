<?php
session_start();
require_once 'includes/db_connect.php';

// Only allow from localhost for security
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    die("Access denied!");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_username = 'admin'; // Current username
    $new_username = $_POST['new_username'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate
    if ($new_password !== $confirm_password) {
        die("Passwords don't match!");
    }
    
    if (strlen($new_password) < 8) {
        die("Password must be at least 8 characters!");
    }
    
    // Hash new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Update database
    $sql = "UPDATE users SET username = ?, password = ? WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $new_username, $hashed_password, $current_username);
    
    if ($stmt->execute()) {
        echo "<h3>Credentials Updated Successfully!</h3>";
        echo "New Username: " . htmlspecialchars($new_username) . "<br>";
        echo "New Password: " . htmlspecialchars($new_password) . "<br>";
        echo "<strong>Save these credentials securely!</strong><br>";
        echo "<a href='index.php'>Go to Login</a>";
    } else {
        echo "Error: " . $conn->error;
    }
    
    $stmt->close();
} else {
    // Show form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Change Admin Credentials</title>
        <style>
            body { font-family: Arial; max-width: 500px; margin: 50px auto; padding: 20px; }
            .form-group { margin-bottom: 15px; }
            label { display: block; margin-bottom: 5px; }
            input { width: 100%; padding: 8px; box-sizing: border-box; }
            button { padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; }
        </style>
    </head>
    <body>
        <h2>Change Admin Credentials</h2>
        <form method="POST">
            <div class="form-group">
                <label>New Username:</label>
                <input type="text" name="new_username" value="admin" required>
            </div>
            <div class="form-group">
                <label>New Password:</label>
                <input type="password" name="new_password" required minlength="8">
            </div>
            <div class="form-group">
                <label>Confirm Password:</label>
                <input type="password" name="confirm_password" required minlength="8">
            </div>
            <button type="submit">Change Credentials</button>
        </form>
        
        <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
            <h4>Password Requirements:</h4>
            <ul>
                <li>Minimum 8 characters</li>
                <li>Include uppercase and lowercase letters</li>
                <li>Include numbers</li>
                <li>Include special characters (!@#$%^&*)</li>
            </ul>
        </div>
    </body>
    </html>
    <?php
}
?>