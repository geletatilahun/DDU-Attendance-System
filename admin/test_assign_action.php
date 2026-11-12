<?php
session_start();
require_once '../includes/db_connect.php';

echo "<h2>Test Assignment Action</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h3>POST Data Received:</h3>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    $course_id = (int)$_POST['course_id'];
    $teacher_id = (int)$_POST['teacher_id'];
    $role = $_POST['role'] ?? 'primary';
    $assigned_by = $_SESSION['user_id'] ?? 1;
    
    echo "<h3>Validating Data:</h3>";
    echo "Course ID: $course_id<br>";
    echo "Teacher ID: $teacher_id<br>";
    echo "Role: $role<br>";
    echo "Assigned by: $assigned_by<br>";
    
    // Check if teacher exists
    $teacher_check = $conn->query("SELECT * FROM users WHERE user_id = $teacher_id AND role = 'teacher'");
    if ($teacher_check->num_rows > 0) {
        echo "✅ Teacher exists<br>";
    } else {
        echo "❌ Teacher not found or not a teacher<br>";
    }
    
    // Check if course exists
    $course_check = $conn->query("SELECT * FROM courses WHERE course_id = $course_id");
    if ($course_check->num_rows > 0) {
        echo "✅ Course exists<br>";
    } else {
        echo "❌ Course not found<br>";
    }
    
    // Check if already assigned
    $check_sql = "SELECT id FROM course_teachers WHERE course_id = $course_id AND teacher_id = $teacher_id";
    $check_result = $conn->query($check_sql);
    if ($check_result->num_rows > 0) {
        echo "⚠️ Teacher is already assigned to this course<br>";
    } else {
        echo "✅ Teacher is not assigned to this course yet<br>";
    }
    
    // Try to insert
    echo "<h3>Attempting to Insert:</h3>";
    $sql = "INSERT INTO course_teachers (course_id, teacher_id, role, assigned_by, assigned_date) 
            VALUES (?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo "❌ Prepare failed: " . $conn->error . "<br>";
    } else {
        echo "✅ Prepare succeeded<br>";
        
        $stmt->bind_param("iisi", $course_id, $teacher_id, $role, $assigned_by);
        if ($stmt->execute()) {
            echo "✅ Insert successful! Last insert ID: " . $stmt->insert_id . "<br>";
            
            // Verify it was inserted
            $verify = $conn->query("SELECT * FROM course_teachers WHERE id = " . $stmt->insert_id);
            if ($verify->num_rows > 0) {
                echo "✅ Verified insertion in database<br>";
            }
        } else {
            echo "❌ Execute failed: " . $stmt->error . "<br>";
        }
    }
} else {
    echo "No POST data received";
}

echo "<hr>";
echo '<a href="debug_assign.php">Back to Debug</a>';
?>