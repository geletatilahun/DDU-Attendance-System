<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if admin
if (!is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost();
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}

function handleGet() {
    global $conn;
    
    if (isset($_GET['id'])) {
        // Get single teacher
        $id = (int)$_GET['id'];
        $sql = "SELECT u.*, d.name as department_name, d.code as department_code
                FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.id = ? AND u.role = 'teacher'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $teacher = $result->fetch_assoc();
            // Remove sensitive data
            unset($teacher['password']);
            echo json_encode(['success' => true, 'teacher' => $teacher]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        }
    } elseif (isset($_GET['action']) && $_GET['action'] == 'get_courses') {
        // Get teacher's courses
        $teacher_id = (int)$_GET['teacher_id'];
        $sql = "SELECT c.*, d.name as department_name
                FROM courses c
                JOIN course_teachers ct ON c.id = ct.course_id
                JOIN departments d ON c.department_id = d.id
                WHERE ct.teacher_id = ?
                ORDER BY d.name, c.semester, c.code";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
        
        echo json_encode(['success' => true, 'courses' => $courses]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
    }
}

function handlePost() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data && isset($data['action'])) {
        switch ($data['action']) {
            case 'assign_courses':
                assignCourses($data);
                break;
            case 'remove_course':
                removeCourse($data);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } else {
        // Add new teacher
        addTeacher();
    }
}

function addTeacher() {
    global $conn;
    
    $full_name = $_POST['full_name'] ?? '';
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $department_id = $_POST['department_id'] ?? null;
    $designation = $_POST['designation'] ?? '';
    $qualification = $_POST['qualification'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $employee_id = $_POST['employee_id'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? null;
    $date_of_joining = $_POST['date_of_joining'] ?? null;
    $gender = $_POST['gender'] ?? '';
    $status = $_POST['status'] ?? 'active';
    $is_hod = isset($_POST['is_hod']) ? 1 : 0;
    $address = $_POST['address'] ?? '';
    
    // Validation
    if (empty($full_name) || empty($username) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        return;
    }
    
    if ($password !== $confirm_password) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        return;
    }
    
    // Check if username or email exists
    $check_sql = "SELECT id FROM users WHERE username = ? OR email = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ss", $username, $email);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
        return;
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert into users table
    $sql = "INSERT INTO users (username, email, password, full_name, role, department_id, status, is_hod, created_at)
            VALUES (?, ?, ?, ?, 'teacher', ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    
    if ($department_id === '' || $department_id === '0') {
        $department_id = null;
    }
    
    $stmt->bind_param("sssssii", $username, $email, $hashed_password, $full_name, $department_id, $status, $is_hod);
    
    if ($stmt->execute()) {
        $teacher_id = $stmt->insert_id;
        
        // Insert teacher details if any
        if (!empty($employee_id) || !empty($designation) || !empty($qualification) || !empty($phone) || 
            !empty($date_of_birth) || !empty($date_of_joining) || !empty($gender) || !empty($address)) {
            
            $details_sql = "INSERT INTO teacher_details (user_id, employee_id, designation, qualification, phone, 
                           date_of_birth, date_of_joining, gender, address)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $details_stmt = $conn->prepare($details_sql);
            $details_stmt->bind_param("issssssss", $teacher_id, $employee_id, $designation, $qualification, 
                                     $phone, $date_of_birth, $date_of_joining, $gender, $address);
            $details_stmt->execute();
        }
        
        echo json_encode(['success' => true, 'message' => 'Teacher added successfully', 'teacher_id' => $teacher_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add teacher: ' . $conn->error]);
    }
}

function assignCourses($data) {
    global $conn;
    
    $teacher_id = $data['teacher_id'] ?? 0;
    $course_ids = $data['course_ids'] ?? [];
    
    if ($teacher_id == 0 || empty($course_ids)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        return;
    }
    
    $success = true;
    $message = '';
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        foreach ($course_ids as $course_id) {
            // Check if already assigned
            $check_sql = "SELECT id FROM course_teachers WHERE teacher_id = ? AND course_id = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ii", $teacher_id, $course_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                // Assign course
                $assign_sql = "INSERT INTO course_teachers (teacher_id, course_id, assigned_at) VALUES (?, ?, NOW())";
                $assign_stmt = $conn->prepare($assign_sql);
                $assign_stmt->bind_param("ii", $teacher_id, $course_id);
                $assign_stmt->execute();
            }
        }
        
        $conn->commit();
        $message = 'Courses assigned successfully';
    } catch (Exception $e) {
        $conn->rollback();
        $success = false;
        $message = 'Failed to assign courses: ' . $e->getMessage();
    }
    
    echo json_encode(['success' => $success, 'message' => $message]);
}

function removeCourse($data) {
    global $conn;
    
    $teacher_id = $data['teacher_id'] ?? 0;
    $course_id = $data['course_id'] ?? 0;
    
    if ($teacher_id == 0 || $course_id == 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        return;
    }
    
    $sql = "DELETE FROM course_teachers WHERE teacher_id = ? AND course_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $teacher_id, $course_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Course removed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove course']);
    }
}

function handleDelete() {
    global $conn;
    
    if (!isset($_GET['id'])) {
        echo json_encode(['success' => false, 'message' => 'Teacher ID required']);
        return;
    }
    
    $id = (int)$_GET['id'];
    
    // Check if teacher has assigned courses
    $check_sql = "SELECT COUNT(*) as count FROM course_teachers WHERE teacher_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete teacher with assigned courses. Please remove courses first.']);
        return;
    }
    
    // Begin transaction
    $conn->begin_transaction();
    
    try {
        // Delete teacher details
        $details_sql = "DELETE FROM teacher_details WHERE user_id = ?";
        $details_stmt = $conn->prepare($details_sql);
        $details_stmt->bind_param("i", $id);
        $details_stmt->execute();
        
        // Delete user
        $user_sql = "DELETE FROM users WHERE id = ? AND role = 'teacher'";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("i", $id);
        $user_stmt->execute();
        
        if ($user_stmt->affected_rows > 0) {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Teacher deleted successfully']);
        } else {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to delete teacher: ' . $e->getMessage()]);
    }
}
?>