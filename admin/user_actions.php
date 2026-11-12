<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Check database connection
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Get action from POST or GET
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    $_SESSION['error'] = 'No action specified';
    header('Location: manage_users.php');
    exit;
}

// Handle different actions
switch ($action) {
    case 'add':
        addUser();
        break;
        
    case 'edit':
        editUser();
        break;
        
    case 'disable':
        disableUser();
        break;
        
    case 'enable':
        enableUser();
        break;
        
    case 'delete':
        deleteUser();
        break;
        
    case 'reset_password':
        resetPassword();
        break;
        
    default:
        $_SESSION['error'] = 'Invalid action';
        header('Location: manage_users.php');
        exit;
}

function addUser() {
    global $conn;
    
    // Log POST data for debugging
    error_log("=== ADD USER POST DATA ===");
    error_log(print_r($_POST, true));
    
    // Validate required fields
    $required = ['full_name', 'username', 'password', 'role'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['error'] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            header('Location: manage_users.php');
            exit;
        }
    }
    
    // Check if passwords match
    if ($_POST['password'] !== $_POST['confirm_password']) {
        $_SESSION['error'] = 'Passwords do not match';
        header('Location: manage_users.php');
        exit;
    }
    
    // Check if username already exists
    $check_sql = "SELECT user_id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        $_SESSION['error'] = 'Database error (check username): ' . htmlspecialchars($conn->error);
        header('Location: manage_users.php');
        exit;
    }
    
    $check_stmt->bind_param("s", $_POST['username']);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $_SESSION['error'] = 'Username already exists';
        header('Location: manage_users.php');
        exit;
    }
    
    // Check if email exists (if provided)
    if (!empty($_POST['email'])) {
        $check_email_sql = "SELECT user_id FROM users WHERE email = ?";
        $check_email_stmt = $conn->prepare($check_email_sql);
        if ($check_email_stmt) {
            $check_email_stmt->bind_param("s", $_POST['email']);
            $check_email_stmt->execute();
            $check_email_result = $check_email_stmt->get_result();
            
            if ($check_email_result->num_rows > 0) {
                $_SESSION['error'] = 'Email already registered';
                header('Location: manage_users.php');
                exit;
            }
        }
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Hash the password
        $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        
        // Prepare values for user insertion
        $email = !empty($_POST['email']) ? $_POST['email'] : NULL;
        $phone = !empty($_POST['phone']) ? $_POST['phone'] : NULL;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $role = $_POST['role'];
        
        // Debug: Check role value
        error_log("Role value: " . $role);
        
        // Insert into users table - Based on your actual table structure
        $user_sql = "INSERT INTO users (full_name, username, email, phone, password, role, is_active) 
                     VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        error_log("User Insert SQL: " . $user_sql);
        
        $user_stmt = $conn->prepare($user_sql);
        if (!$user_stmt) {
            throw new Exception("Failed to prepare user statement: " . htmlspecialchars($conn->error));
        }
        
        // Bind parameters
        $bind_result = $user_stmt->bind_param("ssssssi", 
            $_POST['full_name'],
            $_POST['username'],
            $email,
            $phone,
            $hashed_password,
            $role,
            $is_active
        );
        
        if (!$bind_result) {
            throw new Exception("Failed to bind user parameters: " . htmlspecialchars($user_stmt->error));
        }
        
        if (!$user_stmt->execute()) {
            throw new Exception("Failed to execute user insert: " . htmlspecialchars($user_stmt->error));
        }
        
        $user_id = $conn->insert_id;
        error_log("User inserted with ID: " . $user_id);
        
        // If user is a student, insert into students table
        if ($role === 'student') {
            // Validate student-specific fields
            $student_required = ['student_id_no', 'department_id'];
            foreach ($student_required as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required for students');
                }
            }
            
            // Check if student ID already exists
            $check_student_sql = "SELECT student_id FROM students WHERE student_id_no = ?";
            $check_student_stmt = $conn->prepare($check_student_sql);
            if ($check_student_stmt) {
                $check_student_stmt->bind_param("s", $_POST['student_id_no']);
                $check_student_stmt->execute();
                $check_student_result = $check_student_stmt->get_result();
                
                if ($check_student_result->num_rows > 0) {
                    throw new Exception('Student ID already exists');
                }
            }
            
            // Prepare student values - Based on your actual students table structure
            $program = !empty($_POST['program']) ? $_POST['program'] : NULL;
            $year_of_study = !empty($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : NULL;
            $semester = !empty($_POST['semester']) ? (int)$_POST['semester'] : NULL;
            $academic_year = !empty($_POST['academic_year']) ? $_POST['academic_year'] : NULL;
            $address = !empty($_POST['address']) ? $_POST['address'] : NULL;
            $emergency_contact = !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : NULL;
            
            // Insert into students table - Based on your actual table structure
            $student_sql = "INSERT INTO students (
                student_id_no, 
                full_name, 
                email, 
                phone, 
                department_id, 
                program, 
                year_of_study, 
                semester, 
                user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            error_log("Student Insert SQL: " . $student_sql);
            
            $student_stmt = $conn->prepare($student_sql);
            if (!$student_stmt) {
                throw new Exception("Failed to prepare student statement: " . htmlspecialchars($conn->error));
            }
            
            // Bind parameters for students table
            $student_bind = $student_stmt->bind_param("ssssisiii", 
                $_POST['student_id_no'],
                $_POST['full_name'],
                $email,
                $phone,
                $_POST['department_id'],
                $program,
                $year_of_study,
                $semester,
                $user_id
            );
            
            if (!$student_bind) {
                throw new Exception("Failed to bind student parameters: " . htmlspecialchars($student_stmt->error));
            }
            
            if (!$student_stmt->execute()) {
                throw new Exception("Failed to execute student insert: " . htmlspecialchars($student_stmt->error));
            }
            
            error_log("Student record inserted successfully");
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = 'User added successfully';
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn) {
            $conn->rollback();
        }
        $_SESSION['error'] = $e->getMessage();
        error_log("Add User Error: " . $e->getMessage());
    }
    
    header('Location: manage_users.php');
    exit;
}

function editUser() {
    global $conn;
    
    if (!isset($_POST['user_id']) || !is_numeric($_POST['user_id'])) {
        $_SESSION['error'] = 'Invalid user ID';
        header('Location: manage_users.php');
        exit;
    }
    
    $user_id = (int)$_POST['user_id'];
    
    // Start transaction for data consistency
    $conn->begin_transaction();
    
    try {
        // Build update query dynamically for users table
        $update_fields = [];
        $params = [];
        $types = '';
        
        // Check which fields are being updated
        if (!empty($_POST['full_name'])) {
            $update_fields[] = "full_name = ?";
            $params[] = $_POST['full_name'];
            $types .= 's';
        }
        
        // Always handle email, even if empty
        $email = !empty($_POST['email']) ? $_POST['email'] : NULL;
        // Check if email is unique (excluding current user)
        if ($email !== NULL) {
            $check_email_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
            $check_email_stmt = $conn->prepare($check_email_sql);
            if ($check_email_stmt) {
                $check_email_stmt->bind_param("si", $email, $user_id);
                $check_email_stmt->execute();
                $check_email_result = $check_email_stmt->get_result();
                
                if ($check_email_result->num_rows > 0) {
                    throw new Exception('Email already registered to another user');
                }
            }
        }
        
        $update_fields[] = "email = ?";
        $params[] = $email;
        $types .= 's';
        
        // Always handle phone
        $phone = !empty($_POST['phone']) ? $_POST['phone'] : NULL;
        $update_fields[] = "phone = ?";
        $params[] = $phone;
        $types .= 's';
        
        // Handle is_active - check if checkbox was checked
        $is_active = isset($_POST['is_active']) && ($_POST['is_active'] == '1' || $_POST['is_active'] === 'on') ? 1 : 0;
        $update_fields[] = "is_active = ?";
        $params[] = $is_active;
        $types .= 'i';
        
        // If password is provided, update it
        if (!empty($_POST['password'])) {
            if ($_POST['password'] !== $_POST['confirm_password']) {
                throw new Exception('Passwords do not match');
            }
            
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $update_fields[] = "password = ?";
            $params[] = $hashed_password;
            $types .= 's';
        }
        
        // Always handle role
        if (!empty($_POST['role'])) {
            $update_fields[] = "role = ?";
            $params[] = $_POST['role'];
            $types .= 's';
        }
        
        // Update user record
        $update_sql = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE user_id = ?";
        $params[] = $user_id;
        $types .= 'i';
        
        error_log("Update User SQL: " . $update_sql);
        
        $stmt = $conn->prepare($update_sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare update statement: ' . htmlspecialchars($conn->error));
        }
        
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to update user: ' . htmlspecialchars($stmt->error));
        }
        
        // Handle student-specific updates if role is student
        if (isset($_POST['role']) && $_POST['role'] === 'student') {
            updateStudentDetails($user_id, $conn);
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = 'User updated successfully';
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        error_log("Edit User Error: " . $e->getMessage());
        header('Location: edit_user.php?id=' . $user_id);
        exit;
    }
    
    header('Location: manage_users.php');
    exit;
}

function updateStudentDetails($user_id, $conn) {
    // Check if student record exists
    $check_sql = "SELECT student_id FROM students WHERE user_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $user_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    // Prepare student data
    $student_id_no = !empty($_POST['student_id_no']) ? $_POST['student_id_no'] : NULL;
    $department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : NULL;
    $program = !empty($_POST['program']) ? $_POST['program'] : NULL;
    $year_of_study = !empty($_POST['year_of_study']) ? (int)$_POST['year_of_study'] : NULL;
    $semester = !empty($_POST['semester']) ? (int)$_POST['semester'] : NULL;
    $academic_year = !empty($_POST['academic_year']) ? $_POST['academic_year'] : NULL;
    $address = !empty($_POST['address']) ? $_POST['address'] : NULL;
    $emergency_contact = !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : NULL;
    $gender = !empty($_POST['gender']) ? $_POST['gender'] : NULL;
    $date_of_birth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : NULL;
    
    // Validate required student fields
    if (empty($student_id_no)) {
        throw new Exception('Student ID Number is required');
    }
    
    if (empty($department_id)) {
        throw new Exception('Department is required for students');
    }
    
    // Check if student ID is unique (excluding current student)
    if ($check_result->num_rows > 0) {
        $existing_student = $check_result->fetch_assoc();
        $check_id_sql = "SELECT student_id FROM students WHERE student_id_no = ? AND student_id != ?";
        $check_id_stmt = $conn->prepare($check_id_sql);
        $check_id_stmt->bind_param("si", $student_id_no, $existing_student['student_id']);
        $check_id_stmt->execute();
        $check_id_result = $check_id_stmt->get_result();
        
        if ($check_id_result->num_rows > 0) {
            throw new Exception('Student ID already exists');
        }
    } else {
        // Check if new student ID already exists
        $check_new_sql = "SELECT student_id FROM students WHERE student_id_no = ?";
        $check_new_stmt = $conn->prepare($check_new_sql);
        $check_new_stmt->bind_param("s", $student_id_no);
        $check_new_stmt->execute();
        $check_new_result = $check_new_stmt->get_result();
        
        if ($check_new_result->num_rows > 0) {
            throw new Exception('Student ID already exists');
        }
    }
    
    if ($check_result->num_rows > 0) {
        // Update existing student record
        $update_sql = "UPDATE students SET 
            student_id_no = ?,
            full_name = ?,
            email = ?,
            phone = ?,
            department_id = ?,
            program = ?,
            year_of_study = ?,
            semester = ?,
            academic_year = ?,
            address = ?,
            emergency_contact = ?,
            gender = ?,
            date_of_birth = ?
            WHERE user_id = ?";
        
        $stmt = $conn->prepare($update_sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare student update: ' . htmlspecialchars($conn->error));
        }
        
        // Get user details for full_name, email, phone
        $user_sql = "SELECT full_name, email, phone FROM users WHERE user_id = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
        
        $stmt->bind_param("ssssissssssssi",
            $student_id_no,
            $user['full_name'],
            $user['email'],
            $user['phone'],
            $department_id,
            $program,
            $year_of_study,
            $semester,
            $academic_year,
            $address,
            $emergency_contact,
            $gender,
            $date_of_birth,
            $user_id
        );
    } else {
        // Create new student record
        $insert_sql = "INSERT INTO students (
            user_id, student_id_no, full_name, email, phone, department_id, 
            program, year_of_study, semester, academic_year, address, 
            emergency_contact, gender, date_of_birth
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insert_sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare student insert: ' . htmlspecialchars($conn->error));
        }
        
        // Get user details for full_name, email, phone
        $user_sql = "SELECT full_name, email, phone FROM users WHERE user_id = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        $user = $user_result->fetch_assoc();
        
        $stmt->bind_param("issssissssssss",
            $user_id,
            $student_id_no,
            $user['full_name'],
            $user['email'],
            $user['phone'],
            $department_id,
            $program,
            $year_of_study,
            $semester,
            $academic_year,
            $address,
            $emergency_contact,
            $gender,
            $date_of_birth
        );
    }
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update student details: ' . htmlspecialchars($stmt->error));
    }
}

function disableUser() {
    global $conn;
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        $_SESSION['error'] = 'Invalid user ID';
        header('Location: manage_users.php');
        exit;
    }
    
    $user_id = (int)$_GET['id'];
    
    // Prevent disabling yourself
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error'] = 'You cannot disable your own account';
        header('Location: manage_users.php');
        exit;
    }
    
    $sql = "UPDATE users SET is_active = 0 WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'User disabled successfully';
        } else {
            $_SESSION['error'] = 'Failed to disable user';
        }
    } else {
        $_SESSION['error'] = 'Database error';
    }
    
    header('Location: manage_users.php');
    exit;
}

function enableUser() {
    global $conn;
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        $_SESSION['error'] = 'Invalid user ID';
        header('Location: manage_users.php');
        exit;
    }
    
    $user_id = (int)$_GET['id'];
    
    $sql = "UPDATE users SET is_active = 1 WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = 'User enabled successfully';
        } else {
            $_SESSION['error'] = 'Failed to enable user';
        }
    } else {
        $_SESSION['error'] = 'Database error';
    }
    
    header('Location: manage_users.php');
    exit;
}

function deleteUser() {
    global $conn;
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        $_SESSION['error'] = 'Invalid user ID';
        header('Location: manage_users.php');
        exit;
    }
    
    $user_id = (int)$_GET['id'];
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error'] = 'You cannot delete your own account';
        header('Location: manage_users.php');
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First delete student record if exists
        $delete_student_sql = "DELETE FROM students WHERE user_id = ?";
        $delete_student_stmt = $conn->prepare($delete_student_sql);
        if ($delete_student_stmt) {
            $delete_student_stmt->bind_param("i", $user_id);
            $delete_student_stmt->execute();
        }
        
        // Delete user record
        $delete_user_sql = "DELETE FROM users WHERE user_id = ?";
        $delete_user_stmt = $conn->prepare($delete_user_sql);
        if (!$delete_user_stmt) {
            throw new Exception("Failed to prepare delete statement: " . htmlspecialchars($conn->error));
        }
        
        $delete_user_stmt->bind_param("i", $user_id);
        
        if (!$delete_user_stmt->execute()) {
            throw new Exception("Failed to delete user: " . htmlspecialchars($delete_user_stmt->error));
        }
        
        $conn->commit();
        $_SESSION['success'] = 'User deleted successfully';
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
    }
    
    header('Location: manage_users.php');
    exit;
}

function resetPassword() {
    global $conn;
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        $_SESSION['error'] = 'Invalid user ID';
        header('Location: manage_users.php');
        exit;
    }
    
    $user_id = (int)$_GET['id'];
    
    // Generate random password
    $new_password = bin2hex(random_bytes(4)); // 8 character password
    
    // Hash the password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $sql = "UPDATE users SET password = ? WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("si", $hashed_password, $user_id);
        
        if ($stmt->execute()) {
            // Get user email to send notification
            $email_sql = "SELECT email, full_name FROM users WHERE user_id = ?";
            $email_stmt = $conn->prepare($email_sql);
            if ($email_stmt) {
                $email_stmt->bind_param("i", $user_id);
                $email_stmt->execute();
                $email_result = $email_stmt->get_result();
                
                if ($email_result->num_rows > 0) {
                    $user = $email_result->fetch_assoc();
                    $_SESSION['success'] = "Password reset successfully. New password: <strong>$new_password</strong>. Please inform the user.";
                } else {
                    $_SESSION['success'] = 'Password reset successfully';
                }
            }
        } else {
            $_SESSION['error'] = 'Failed to reset password';
        }
    } else {
        $_SESSION['error'] = 'Database error';
    }
    
    header('Location: manage_users.php');
    exit;
}

// Close database connection
$conn->close();
?>