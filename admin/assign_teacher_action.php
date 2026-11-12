<?php
session_start();
require_once '../includes/db_connect.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "Unauthorized access";
    header("Location: manage_courses.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log the POST data for debugging
    error_log("=== ASSIGN TEACHER POST DATA ===");
    foreach ($_POST as $key => $value) {
        error_log("$key: $value");
    }
    
    $course_id = (int)($_POST['course_id'] ?? 0);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $role = $_POST['role'] ?? 'primary';
    $start_date = $_POST['start_date'] ?? date('Y-m-d');
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $assigned_by = $_SESSION['user_id'];
    
    // Validate
    if ($course_id === 0) {
        $_SESSION['error'] = "Please select a course";
        header("Location: manage_courses.php");
        exit;
    }
    
    if ($teacher_id === 0) {
        $_SESSION['error'] = "Please select a teacher";
        header("Location: manage_courses.php");
        exit;
    }
    
    // Debug: Check if data is valid
    error_log("Course ID: $course_id, Teacher ID: $teacher_id, Role: $role");
    
    // Check if assignment already exists (simple query first)
    $check_simple = $conn->query("
        SELECT id FROM course_teachers 
        WHERE course_id = $course_id AND teacher_id = $teacher_id
    ");
    
    if ($check_simple->num_rows > 0) {
        $_SESSION['error'] = "This teacher is already assigned to this course";
        header("Location: manage_courses.php");
        exit;
    }
    
    // Insert the assignment
    if ($end_date) {
        $sql = "INSERT INTO course_teachers (course_id, teacher_id, role, start_date, end_date, assigned_by, assigned_date) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iisssi", $course_id, $teacher_id, $role, $start_date, $end_date, $assigned_by);
        }
    } else {
        $sql = "INSERT INTO course_teachers (course_id, teacher_id, role, start_date, assigned_by, assigned_date) 
                VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iissi", $course_id, $teacher_id, $role, $start_date, $assigned_by);
        }
    }
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error'] = "Database error: " . $conn->error;
        header("Location: manage_courses.php");
        exit;
    }
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Teacher assigned successfully!";
        error_log("Assignment successful for course $course_id, teacher $teacher_id");
    } else {
        error_log("Execute failed: " . $stmt->error);
        $_SESSION['error'] = "Failed to assign teacher: " . $stmt->error;
    }
    
    $stmt->close();
} else {
    $_SESSION['error'] = "Invalid request method";
}

header("Location: manage_courses.php");
exit;
?>