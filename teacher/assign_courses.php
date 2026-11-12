<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

checkRole(['department_head']);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$department_head_id = (int) $_SESSION['user_id'];

// Handle course assignment to teachers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'assign_course') {
        $course_id = (int) $_POST['course_id'];
        $teacher_id = (int) $_POST['teacher_id'];
        
        $check_sql = "SELECT * FROM course_teachers WHERE course_id = ? AND teacher_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $course_id, $teacher_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            $insert_sql = "
                INSERT INTO course_teachers (course_id, teacher_id, assigned_by, assigned_at) 
                VALUES (?, ?, ?, NOW())
            ";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("iii", $course_id, $teacher_id, $department_head_id);
            $insert_stmt->execute();
        }
    }
}

// Fetch unassigned courses and teachers
// ... (implementation)