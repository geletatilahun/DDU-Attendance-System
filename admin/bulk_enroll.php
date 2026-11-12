<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = (int)($_POST['course_id'] ?? 0);
    
    if ($course_id === 0) {
        $_SESSION['error'] = "Invalid course ID";
        header("Location: manage_courses.php");
        exit;
    }
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Please upload a CSV file";
        header("Location: course_students.php?id=$course_id");
        exit;
    }
    
    $file = $_FILES['csv_file']['tmp_name'];
    $enrolled_by = $_SESSION['user_id'];
    $success_count = 0;
    $error_count = 0;
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        // Skip header row if exists
        $header = fgetcsv($handle);
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 1) {
                $student_identifier = trim($data[0]); // student_id
                
                // Find student
                $student_sql = "SELECT student_id FROM students WHERE student_id = ? AND is_active = 1";
                $stmt = $conn->prepare($student_sql);
                $stmt->bind_param("s", $student_identifier);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $student = $result->fetch_assoc();
                    $student_id = $student['student_id'];
                    
                    // Check if already enrolled
                    $check_sql = "SELECT id FROM course_students WHERE course_id = ? AND student_id = ?";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $course_id, $student_id);
                    $check_stmt->execute();
                    
                    if ($check_stmt->get_result()->num_rows === 0) {
                        // Enroll student
                        $enroll_sql = "INSERT INTO course_students (course_id, student_id, enrolled_by, enrollment_date, status) 
                                      VALUES (?, ?, ?, NOW(), 'active')";
                        $enroll_stmt = $conn->prepare($enroll_sql);
                        $enroll_stmt->bind_param("iii", $course_id, $student_id, $enrolled_by);
                        
                        if ($enroll_stmt->execute()) {
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } else {
                        $error_count++; // Already enrolled
                    }
                } else {
                    $error_count++; // Student not found
                }
            }
        }
        fclose($handle);
    }
    
    if ($success_count > 0) {
        $_SESSION['success'] = "Successfully enrolled $success_count student(s) from CSV file";
    }
    if ($error_count > 0) {
        $_SESSION['error'] = ($_SESSION['error'] ?? '') . " Failed to enroll $error_count student(s) (not found or already enrolled).";
    }
    
    header("Location: course_students.php?id=$course_id");
    exit;
}

// If accessed via GET with course_id parameter
if (isset($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    header("Location: course_students.php?id=$course_id");
    exit;
}

header("Location: manage_courses.php");
exit;
?>