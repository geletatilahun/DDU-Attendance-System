<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

/* ========= GET STUDENT ENROLLMENTS ========= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];
    
    $stmt = $conn->prepare("
        SELECT e.enrollment_id, e.student_id, e.course_id, e.enrollment_date, 
               e.academic_year, e.semester, e.is_active,
               c.course_code, c.course_name
        FROM enrollments e
        JOIN courses c ON e.course_id = c.course_id
        WHERE e.student_id = ?
        ORDER BY e.enrollment_date DESC
    ");
    
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $enrollments = [];
    while ($row = $result->fetch_assoc()) {
        $enrollments[] = $row;
    }
    
    echo json_encode(['success'=>true, 'enrollments'=>$enrollments]);
    exit;
}

/* ========= ENROLL STUDENT IN COURSES ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validate required fields
    if (!isset($_POST['student_id']) || !isset($_POST['course_ids'])) {
        echo json_encode(['success'=>false,'message'=>'Missing required fields']);
        exit;
    }
    
    $student_id = (int)$_POST['student_id'];
    $course_ids = $_POST['course_ids'];
    $academic_year = $_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
    $semester = $_POST['semester'] ?? 1;
    
    if (!is_array($course_ids) || empty($course_ids)) {
        echo json_encode(['success'=>false,'message'=>'No courses selected']);
        exit;
    }
    
    $success_count = 0;
    $error_messages = [];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        foreach ($course_ids as $course_id) {
            $course_id = (int)$course_id;
            
            // Check if already enrolled for this academic year and semester
            $check = $conn->prepare("
                SELECT enrollment_id FROM enrollments 
                WHERE student_id = ? AND course_id = ? AND academic_year = ? AND semester = ?
            ");
            $check->bind_param("iisi", $student_id, $course_id, $academic_year, $semester);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows === 0) {
                // Insert new enrollment
                $stmt = $conn->prepare("
                    INSERT INTO enrollments 
                    (student_id, course_id, enrollment_date, academic_year, semester, is_active)
                    VALUES (?, ?, NOW(), ?, ?, 1)
                ");
                $stmt->bind_param("iisi", $student_id, $course_id, $academic_year, $semester);
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_messages[] = "Failed to enroll in course ID: $course_id";
                }
            } else {
                $error_messages[] = "Already enrolled in this course for selected academic year/semester";
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        if ($success_count > 0) {
            echo json_encode([
                'success' => true, 
                'message' => "Successfully enrolled in $success_count course(s)",
                'count' => $success_count
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => implode(', ', $error_messages)
            ]);
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>'Transaction failed: ' . $e->getMessage()]);
    }
    
    exit;
}

/* ========= REMOVE ENROLLMENT ========= */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    
    parse_str($_SERVER['QUERY_STRING'], $q);
    $enrollment_id = (int)($q['enrollment_id'] ?? 0);
    
    if ($enrollment_id <= 0) {
        echo json_encode(['success'=>false,'message'=>'Invalid enrollment ID']);
        exit;
    }
    
    $stmt = $conn->prepare("DELETE FROM enrollments WHERE enrollment_id = ?");
    $stmt->bind_param("i", $enrollment_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success'=>true, 'message'=>'Enrollment removed successfully']);
    } else {
        echo json_encode(['success'=>false,'message'=>'Failed to remove enrollment']);
    }
    
    exit;
}

// Default response for invalid requests
echo json_encode(['success'=>false,'message'=>'Invalid request']);
exit;