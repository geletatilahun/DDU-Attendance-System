<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Check if user_id is provided
if (!isset($_GET['user_id']) || !is_numeric($_GET['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
    exit;
}

$user_id = (int)$_GET['user_id'];

try {
    // First, check if user exists and is a student
    $check_sql = "SELECT role FROM users WHERE user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    $user = $check_result->fetch_assoc();
    
    if ($user['role'] !== 'student') {
        echo json_encode(['success' => false, 'message' => 'User is not a student']);
        exit;
    }
    
    // Get student_id from students table
    $sql = "SELECT student_id FROM students WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
        echo json_encode([
            'success' => true, 
            'student_id' => $student['student_id']
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Student record not found in database'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>