<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

/* ========= GET STUDENTS FOR A COURSE ========= */
if ($action === 'get_students') {
    $course_id = (int)($_GET['course_id'] ?? 0);
    
    if ($course_id <= 0) {
        echo json_encode(['success'=>false,'message'=>'Invalid course ID']);
        exit;
    }
    
    // Verify teacher has access to this course
    $teacher_id = $_SESSION['user_id'];
    $verify_sql = "SELECT 1 FROM course_teachers WHERE teacher_id = ? AND course_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $teacher_id, $course_id);
    $verify_stmt->execute();
    $verify_stmt->store_result();
    
    if ($verify_stmt->num_rows === 0) {
        echo json_encode(['success'=>false,'message'=>'Access denied']);
        exit;
    }
    
    // Get current Class Representative
    $cr_sql = "SELECT student_id FROM course_students WHERE course_id = ? AND is_class_rep = 1 LIMIT 1";
    $cr_stmt = $conn->prepare($cr_sql);
    $cr_stmt->bind_param("i", $course_id);
    $cr_stmt->execute();
    $cr_result = $cr_stmt->get_result();
    $current_cr = $cr_result->fetch_assoc();
    $current_cr_id = $current_cr['student_id'] ?? null;
    
    // Get all enrolled students
    $students_sql = "
        SELECT s.student_id, s.student_id_no, s.full_name, s.email, s.phone
        FROM course_students cs
        JOIN students s ON cs.student_id = s.student_id
        WHERE cs.course_id = ?
        ORDER BY s.full_name
    ";
    
    $students_stmt = $conn->prepare($students_sql);
    $students_stmt->bind_param("i", $course_id);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    
    $students = [];
    while ($row = $students_result->fetch_assoc()) {
        $students[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'students' => $students,
        'current_cr_id' => $current_cr_id,
        'count' => count($students)
    ]);
    
    exit;
}

// Default response for invalid requests
echo json_encode(['success'=>false,'message'=>'Invalid request']);
exit;