<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smtp_host = $_POST['smtp_host'];
    $smtp_port = $_POST['smtp_port'];
    $smtp_user = $_POST['smtp_user'];
    $smtp_pass = $_POST['smtp_pass'];
    $from_email = $_POST['from_email'];
    $from_name = $_POST['from_name'];
    
    // Update settings in database
    $settings = [
        'smtp_host' => $smtp_host,
        'smtp_port' => $smtp_port,
        'smtp_user' => $smtp_user,
        'smtp_pass' => $smtp_pass,
        'from_email' => $from_email,
        'from_name' => $from_name,
        'email_notifications' => isset($_POST['email_notifications']) ? 1 : 0
    ];
    
    foreach ($settings as $key => $value) {
        $conn->query("INSERT INTO system_settings (setting_key, setting_value) 
                     VALUES ('$key', '$value')
                     ON DUPLICATE KEY UPDATE setting_value = '$value'");
    }
    
    $_SESSION['success'] = "Email settings updated!";
    
    // Test email
    if (isset($_POST['test_email'])) {
        require_once('../includes/phpmailer/PHPMailerAutoload.php');
        
        $mail = new PHPMailer;
        $mail->isSMTP();
        $mail->Host = $smtp_host;
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_user;
        $mail->Password = $smtp_pass;
        $mail->SMTPSecure = 'tls';
        $mail->Port = $smtp_port;
        
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($smtp_user); // Send test to admin
        
        $mail->Subject = 'DDU Attendance System - Test Email';
        $mail->Body = 'This is a test email from DDU Attendance System. If you received this, email configuration is working correctly.';
        
        if ($mail->send()) {
            $_SESSION['success'] .= " Test email sent successfully!";
        } else {
            $_SESSION['error'] = "Test email failed: " . $mail->ErrorInfo;
        }
    }
    
    header('Location: email_settings.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Email Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include('../includes/admin_header.php'); ?>
    
    <div class="container mt-4">
        <h2>Email Configuration</h2>
        
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); endif; ?>
        
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host" class="form-control" value="smtp.gmail.com" required>
                            <small class="text-muted">For Gmail: smtp.gmail.com</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port" class="form-control" value="587" required>
                            <small class="text-muted">For Gmail TLS: 587, SSL: 465</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>SMTP Username</label>
                            <input type="email" name="smtp_user" class="form-control" placeholder="your_email@gmail.com" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>SMTP Password</label>
                            <input type="password" name="smtp_pass" class="form-control" placeholder="App Password for Gmail" required>
                            <small class="text-muted">For Gmail, use App Password not regular password</small>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>From Email</label>
                            <input type="email" name="from_email" class="form-control" placeholder="noreply@ddu.edu.et" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>From Name</label>
                            <input type="text" name="from_name" class="form-control" value="DDU Attendance System" required>
                        </div>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="email_notifications" class="form-check-input" id="notifications" checked>
                        <label class="form-check-label" for="notifications">Enable Email Notifications</label>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="test_email" class="form-check-input" id="test_email" checked>
                        <label class="form-check-label" for="test_email">Send Test Email after saving</label>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div>
        </div>
        
        <div class="card mt-4">
            <div class="card-header">Gmail Configuration Help</div>
            <div class="card-body">
                <h5>For Gmail Users:</h5>
                <ol>
                    <li>Enable 2-Factor Authentication on your Google Account</li>
                    <li>Generate an App Password: <a href="https://myaccount.google.com/apppasswords" target="_blank">https://myaccount.google.com/apppasswords</a></li>
                    <li>Select "Mail" as app and "Other" as device</li>
                    <li>Copy the 16-character password and use it above</li>
                    <li>Use: smtp.gmail.com, Port: 587, Security: TLS</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>