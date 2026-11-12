<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = (int)($_POST['course_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? 'dropped';
    
    if ($course_id === 0) {
        $_SESSION['error'] = "Invalid course ID";
        header("Location: manage_courses.php");
        exit;
    }
    
    // Update all active students to new status
    $sql = "UPDATE course_students SET status = ? WHERE course_id = ? AND status = 'active'";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("si", $new_status, $course_id);
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $status_labels = [
                'active' => 'activated',
                'dropped' => 'dropped',
                'completed' => 'marked as completed'
            ];
            $_SESSION['success'] = "Successfully $status_labels[$new_status] $affected student(s)";
        } else {
            $_SESSION['error'] = "Error updating student status: " . $stmt->error;
        }
    } else {
        $_SESSION['error'] = "Database error: " . $conn->error;
    }
    
    header("Location: course_students.php?id=$course_id");
    exit;
}

header("Location: manage_courses.php");
exit;
?>