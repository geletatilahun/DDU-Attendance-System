<?php
include('db_connect.php');

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check user role
 */
function checkRole($allowed_roles) {
    if (!isLoggedIn()) {
        header('Location: ../login.php?error=Please login first');
        exit;
    }
    
    if (!in_array($_SESSION['role'], (array)$allowed_roles)) {
        header('Location: ../unauthorized.php');
        exit;
    }
}

/**
 * Login user
 */
function login($username, $password) {
    global $conn;
    
    $sql = "SELECT * FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        $stmt->close();
        
        $stored_password = $user['password'];
        
        // Try hashed password first
        if (password_verify($password, $stored_password)) {
            // Use user_id instead of id
            $_SESSION['user_id'] = $user['user_id'];  // Changed from 'id' to 'user_id'
            $_SESSION['username'] = $user['username'];
            
            // Store other useful user data
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department_id'] = $user['department_id'];
            
            return true;
        }
        else {
            // Password doesn't match
            return false;
        }
    }
    
    $stmt->close();
    return false;
}

/**
 * Get current user info
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    global $conn;
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];
    
    if ($role === 'student') {
        $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    }
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

function is_teacher() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return $_SESSION['user_role'] === 'teacher';
}

function teacher_navigation() {
    return '
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-chalkboard-teacher"></i> DDU Teacher Portal
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#teacherNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="teacherNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-tachometer-alt"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="courses.php">
                            <i class="fas fa-book"></i> My Courses
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="mark_attendance.php">
                            <i class="fas fa-clipboard-check"></i> Attendance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="students.php">
                            <i class="fas fa-users"></i> Students
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">
                            <i class="fas fa-calendar-alt"></i> Schedule
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" 
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle"></i> ' . htmlspecialchars($_SESSION['user_fullname'] ?? 'Teacher') . '
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    ';
}

/**
 * Logout user
 */
function logout() {
    session_destroy();
    header('Location: ../login.php');
    exit;
}
?>