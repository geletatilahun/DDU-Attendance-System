<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = (int)$_POST['course_id'];
    $teacher_id = (int)$_POST['teacher_id'];
    $role = $_POST['role'] ?? 'primary';
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $end_date = $_POST['end_date'] ?? null;
    $assigned_by = $_SESSION['user_id'];
    
    // Check if already assigned
    $check_sql = "SELECT * FROM course_teachers WHERE course_id = ? AND teacher_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $course_id, $teacher_id);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        $_SESSION['error'] = "Teacher is already assigned to this course";
        header("Location: manage_courses.php");
        exit;
    }
    
    // Insert assignment
    $sql = "INSERT INTO course_teachers (course_id, teacher_id, role, start_date, end_date, assigned_by, assigned_date) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisssi", $course_id, $teacher_id, $role, $start_date, $end_date, $assigned_by);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Teacher assigned successfully!";
        
        // Update course's last_updated timestamp
        $update_sql = "UPDATE courses SET updated_at = NOW() WHERE course_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("i", $course_id);
        $update_stmt->execute();
    } else {
        $_SESSION['error'] = "Error assigning teacher: " . $conn->error;
    }
    
    header("Location: manage_courses.php");
    exit;
}