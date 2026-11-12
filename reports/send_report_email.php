<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

// Check appropriate roles
checkRole(['admin', 'teacher', 'dean', 'registrar']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_email = filter_var($_POST['recipient_email'], FILTER_SANITIZE_EMAIL);
    $subject = $_POST['subject'];
    $message = $_POST['message'];
    
    // Generate report data
    // ... (similar to reports.php)
    
    // Send email with attachment
    require_once('../includes/PHPMailer/PHPMailerAutoload.php');
    
    $mail = new PHPMailer;
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'your_email@gmail.com';
    $mail->Password = 'your_password';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    
    $mail->setFrom('noreply@ddu.edu.et', 'DDU Attendance System');
    $mail->addAddress($recipient_email);
    $mail->Subject = $subject;
    $mail->Body = $message;
    
    // Generate PDF attachment
    // ... (code to generate PDF)
    
    if ($mail->send()) {
        $_SESSION['success'] = 'Report sent successfully!';
    } else {
        $_SESSION['error'] = 'Failed to send email: ' . $mail->ErrorInfo;
    }
    
    header('Location: reports.php');
    exit;
}
?>