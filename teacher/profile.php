<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

checkRole(['teacher']);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$teacher_id = (int) $_SESSION['user_id'];

/* ======================================================
   FETCH TEACHER INFORMATION
====================================================== */
$teacher_sql = "
    SELECT 
        u.*,
        d.department_name,
        d.department_code
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.user_id = ?
";

$teacher_stmt = $conn->prepare($teacher_sql);
if (!$teacher_stmt) {
    die("Teacher query failed: " . $conn->error);
}
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();

if (!$teacher) {
    die("Teacher not found.");
}

/* ======================================================
   HANDLE PROFILE UPDATE
====================================================== */
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone'] ?? '');
    
    // Remove address field since it doesn't exist in the database
    // $address = trim($_POST['address'] ?? '');
    
    // Validate inputs
    if (empty($full_name)) {
        $error_message = "Full name is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format";
    } else {
        // Check if email already exists (excluding current user)
        $email_check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $email_stmt = $conn->prepare($email_check_sql);
        if ($email_stmt) {
            $email_stmt->bind_param("si", $email, $teacher_id);
            $email_stmt->execute();
            
            if ($email_stmt->get_result()->num_rows > 0) {
                $error_message = "Email already exists";
            } else {
                // Update profile WITHOUT address field
                $update_sql = "
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?, updated_at = NOW()
                    WHERE user_id = ?
                ";
                
                $update_stmt = $conn->prepare($update_sql);
                if ($update_stmt) {
                    $update_stmt->bind_param("sssi", $full_name, $email, $phone, $teacher_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Profile updated successfully!";
                        // Update session
                        $_SESSION['full_name'] = $full_name;
                        $_SESSION['email'] = $email;
                        // Refresh teacher data
                        $teacher_stmt->execute();
                        $teacher = $teacher_stmt->get_result()->fetch_assoc();
                    } else {
                        $error_message = "Error updating profile: " . $conn->error;
                    }
                } else {
                    $error_message = "Database error: " . $conn->error;
                }
            }
        }
    }
}
/* ======================================================
   HANDLE PASSWORD CHANGE
====================================================== */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($current_password)) {
        $error_message = "Current password is required";
    } elseif (empty($new_password)) {
        $error_message = "New password is required";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match";
    } elseif (strlen($new_password) < 6) {
        $error_message = "Password must be at least 6 characters";
    } else {
        // Verify current password
        $password_sql = "SELECT password FROM users WHERE user_id = ?";
        $password_stmt = $conn->prepare($password_sql);
        if ($password_stmt) {
            $password_stmt->bind_param("i", $teacher_id);
            $password_stmt->execute();
            $password_result = $password_stmt->get_result()->fetch_assoc();
            
            if (password_verify($current_password, $password_result['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_pass_sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?";
                $update_pass_stmt = $conn->prepare($update_pass_sql);
                
                if ($update_pass_stmt) {
                    $update_pass_stmt->bind_param("si", $hashed_password, $teacher_id);
                    
                    if ($update_pass_stmt->execute()) {
                        $success_message = "Password changed successfully!";
                    } else {
                        $error_message = "Error changing password: " . $conn->error;
                    }
                }
            } else {
                $error_message = "Current password is incorrect";
            }
        }
    }
}

/* ======================================================
   HANDLE PROFILE PICTURE UPLOAD
====================================================== */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $file_type = $_FILES['profile_picture']['type'];
    
    if (in_array($file_type, $allowed_types)) {
        $max_size = 2 * 1024 * 1024; // 2MB
        if ($_FILES['profile_picture']['size'] <= $max_size) {
            $file_ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $new_filename = 'profile_' . $teacher_id . '_' . time() . '.' . $file_ext;
            $upload_path = '../uploads/profiles/' . $new_filename;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_path)) {
                // Update database
                $update_pic_sql = "UPDATE users SET profile_image = ? WHERE user_id = ?";
                $update_pic_stmt = $conn->prepare($update_pic_sql);
                
                if ($update_pic_stmt) {
                    $update_pic_stmt->bind_param("si", $new_filename, $teacher_id);
                    if ($update_pic_stmt->execute()) {
                        $_SESSION['profile_image'] = $new_filename;
                        $teacher['profile_image'] = $new_filename;
                        $success_message = "Profile picture updated successfully!";
                    }
                }
            } else {
                $error_message = "Failed to upload profile picture";
            }
        } else {
            $error_message = "File size too large. Maximum size is 2MB.";
        }
    } else {
        $error_message = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
    }
}

/* ======================================================
   GET TEACHER STATISTICS
====================================================== */
$stats = [
    'total_courses' => 0,
    'total_students' => 0,
    'total_attendance' => 0,
    'attendance_rate' => 0
];

// Get course count
$courses_sql = "
    SELECT COUNT(*) as total_courses
    FROM course_teachers ct
    JOIN courses c ON ct.course_id = c.course_id
    WHERE ct.teacher_id = ?
";

$courses_stmt = $conn->prepare($courses_sql);
if ($courses_stmt) {
    $courses_stmt->bind_param("i", $teacher_id);
    $courses_stmt->execute();
    $courses_result = $courses_stmt->get_result()->fetch_assoc();
    $stats['total_courses'] = $courses_result['total_courses'] ?? 0;
}

// Get student count
$students_sql = "
    SELECT COUNT(DISTINCT cs.student_id) as total_students
    FROM course_teachers ct
    JOIN course_students cs ON ct.course_id = cs.course_id
    WHERE ct.teacher_id = ?
";

$students_stmt = $conn->prepare($students_sql);
if ($students_stmt) {
    $students_stmt->bind_param("i", $teacher_id);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result()->fetch_assoc();
    $stats['total_students'] = $students_result['total_students'] ?? 0;
}

// Get attendance count
$attendance_sql = "
    SELECT COUNT(*) as total_attendance
    FROM attendance
    WHERE teacher_id = ?
";

$attendance_stmt = $conn->prepare($attendance_sql);
if ($attendance_stmt) {
    $attendance_stmt->bind_param("i", $teacher_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result()->fetch_assoc();
    $stats['total_attendance'] = $attendance_result['total_attendance'] ?? 0;
}

/* ======================================================
   GET RECENT ACTIVITY
====================================================== */
$activities = [];
$activity_sql = "
    SELECT 
        a.attendance_date,
        c.course_name,
        c.course_code,
        a.total_present,
        a.total_absent,
        a.created_at
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.teacher_id = ?
    ORDER BY a.created_at DESC
    LIMIT 5
";

$activity_stmt = $conn->prepare($activity_sql);
if ($activity_stmt) {
    $activity_stmt->bind_param("i", $teacher_id);
    $activity_stmt->execute();
    $activities_result = $activity_stmt->get_result();
    while ($activity = $activities_result->fetch_assoc()) {
        $activities[] = $activity;
    }
}

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "Profile";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - DDU Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-green: #28a745;
            --ddu-red: #dc3545;
            --ddu-orange: #fd7e14;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            background: var(--ddu-blue);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
            transition: all 0.3s;
            z-index: 1000;
        }
        
        .sidebar .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 15px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--ddu-gold);
        }
        
        .sidebar .nav-link i {
            width: 25px;
            margin-right: 10px;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .navbar-top {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 20px;
            margin: -20px -20px 20px -20px;
        }
        
        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        
        .bg-courses { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-students { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .bg-attendance { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .bg-rate { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--ddu-red);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--ddu-blue) 0%, #4facfe 100%);
            color: white;
            padding: 40px 20px;
            border-radius: 10px;
            margin-bottom: -50px;
            position: relative;
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid white;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: var(--ddu-blue);
            overflow: hidden;
            margin: 0 auto;
            position: relative;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-avatar-edit {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: var(--ddu-gold);
            color: var(--ddu-blue);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .profile-avatar-edit:hover {
            transform: scale(1.1);
        }
        
        .nav-tabs .nav-link {
            color: #666;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 15px 20px;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link:hover {
            color: var(--ddu-blue);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--ddu-blue);
            background: transparent;
            border-bottom: 3px solid var(--ddu-blue);
        }
        
        .tab-content {
            padding: 30px;
            background: white;
            border-radius: 0 0 10px 10px;
        }
        
        .profile-info-item {
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }
        
        .profile-info-item:last-child {
            border-bottom: none;
        }
        
        .profile-info-label {
            font-weight: 600;
            color: #666;
            min-width: 150px;
        }
        
        .activity-item {
            padding: 15px;
            border-radius: 8px;
            background: #f8f9fa;
            margin-bottom: 10px;
            border-left: 4px solid var(--ddu-blue);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                margin-left: -250px;
            }
            .main-content {
                margin-left: 0;
            }
            .sidebar.active {
                margin-left: 0;
            }
            .profile-header {
                margin-bottom: 20px;
            }
            .profile-avatar {
                width: 120px;
                height: 120px;
                font-size: 50px;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h4><i class="fas fa-university"></i> DDU Teacher</h4>
            <small>Attendance System</small>
        </div>
        
        <nav class="nav flex-column mt-3">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="courses.php" class="nav-link">
                <i class="fas fa-book"></i> My Courses
            </a>
            <a href="mark_attendance.php" class="nav-link">
                <i class="fas fa-clipboard-check"></i> Mark Attendance
            </a>
            <a href="attendance_reports.php" class="nav-link">
                <i class="fas fa-chart-bar"></i> Attendance Reports
            </a>
            <a href="students.php" class="nav-link">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <a href="schedule.php" class="nav-link">
                <i class="fas fa-calendar-alt"></i> Schedule
            </a>
            <a href="profile.php" class="nav-link active">
                <i class="fas fa-user"></i> Profile
            </a>
            
            <div class="mt-4 px-3">
                <div class="user-info">
                    <small>Logged in as:</small>
                    <h6><?= htmlspecialchars($teacher['full_name']) ?></h6>
                    <small class="text-muted">Teacher | <?= htmlspecialchars($teacher['department_name'] ?? 'Department') ?></small>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-sm mt-3 w-100">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar-top d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0" style="color: var(--ddu-blue);">
                    <i class="fas fa-user"></i> Profile
                </h4>
                <small class="text-muted">Welcome back, <?= htmlspecialchars($teacher['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-4" style="color: var(--ddu-blue);"></i>
                        <?php if ($stats['total_attendance'] > 0): ?>
                            <span class="notification-badge"><?= $stats['total_attendance'] > 9 ? '9+' : $stats['total_attendance'] ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Profile Notifications</h6>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-user-check text-primary"></i> Profile last updated: 
                            <?= date('M j', strtotime($teacher['updated_at'] ?? $teacher['created_at'])) ?>
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-book text-success"></i> <?= $stats['total_courses'] ?> courses assigned
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-clipboard-check text-info"></i> <?= $stats['total_attendance'] ?> attendance records
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="#">View All</a>
                    </div>
                </div>
                
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <?php if (!empty($teacher['profile_image'])): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($teacher['profile_image']) ?>" 
                                 class="rounded-circle" width="40" height="40">
                        <?php else: ?>
                            <?php 
                            $initials = '';
                            $name_parts = explode(' ', $teacher['full_name']);
                            if (count($name_parts) > 1) {
                                $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
                            } else {
                                $initials = strtoupper(substr($teacher['full_name'], 0, 2));
                            }
                            ?>
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" 
                                 style="width: 40px; height: 40px; font-weight: 600;">
                                <?= $initials ?>
                            </div>
                        <?php endif; ?>
                        <div class="ms-2 d-none d-md-block">
                            <div class="fw-bold"><?= htmlspecialchars($teacher['full_name']) ?></div>
                            <small class="text-muted">Teacher</small>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="profile.php">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a class="dropdown-item" href="settings.php">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="text-center position-relative">
                <div class="profile-avatar mb-3">
                    <?php if (!empty($teacher['profile_image'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($teacher['profile_image']) ?>" 
                             alt="<?= htmlspecialchars($teacher['full_name']) ?>">
                    <?php else: ?>
                        <?php 
                        $initials = '';
                        $name_parts = explode(' ', $teacher['full_name']);
                        if (count($name_parts) > 1) {
                            $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
                        } else {
                            $initials = strtoupper(substr($teacher['full_name'], 0, 2));
                        }
                        ?>
                        <span><?= $initials ?></span>
                    <?php endif; ?>
                    
                    <!-- Edit Profile Picture Button -->
                    <div class="profile-avatar-edit" data-bs-toggle="modal" data-bs-target="#profilePictureModal">
                        <i class="fas fa-camera"></i>
                    </div>
                </div>
                
                <h2 class="mb-2"><?= htmlspecialchars($teacher['full_name']) ?></h2>
                <p class="mb-1">
                    <i class="fas fa-chalkboard-teacher me-1"></i>
                    Teacher | <?= htmlspecialchars($teacher['department_name'] ?? 'Not Assigned') ?>
                </p>
                <p class="mb-0">
                    <i class="fas fa-envelope me-1"></i>
                    <?= htmlspecialchars($teacher['email']) ?>
                </p>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mt-5">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_courses']) ?></h2>
                                <p class="mb-0">Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-students">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_students']) ?></h2>
                                <p class="mb-0">Students</p>
                            </div>
                            <i class="fas fa-user-graduate stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-attendance">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_attendance']) ?></h2>
                                <p class="mb-0">Attendance Records</p>
                            </div>
                            <i class="fas fa-clipboard-check stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-rate">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $stats['total_courses'] > 0 ? 'Active' : 'Inactive' ?></h2>
                                <p class="mb-0">Status</p>
                            </div>
                            <i class="fas fa-user-check stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Profile Content -->
        <div class="chart-container mt-4">
            <!-- Profile Tabs -->
            <ul class="nav nav-tabs" id="profileTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" 
                            data-bs-target="#personal" type="button">
                        <i class="fas fa-user-circle me-2"></i> Personal Info
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="password-tab" data-bs-toggle="tab" 
                            data-bs-target="#password" type="button">
                        <i class="fas fa-key me-2"></i> Change Password
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="activity-tab" data-bs-toggle="tab" 
                            data-bs-target="#activity" type="button">
                        <i class="fas fa-history me-2"></i> Recent Activity
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="settings-tab" data-bs-toggle="tab" 
                            data-bs-target="#settings" type="button">
                        <i class="fas fa-cog me-2"></i> Settings
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="profileTabContent">
                <!-- Personal Info Tab -->
                <div class="tab-pane fade show active" id="personal" role="tabpanel">
                    <form method="post" action="profile.php">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="full_name" class="form-control" 
                                       value="<?= htmlspecialchars($teacher['full_name']) ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" 
                                       value="<?= htmlspecialchars($teacher['email']) ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" 
                                       value="<?= htmlspecialchars($teacher['phone'] ?? '') ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Department</label>
                                <input type="text" class="form-control" 
                                       value="<?= htmlspecialchars($teacher['department_name'] ?? 'Not Assigned') ?>" 
                                       disabled>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Address</label>
                                <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($teacher['address'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employee ID</label>
                                <input type="text" class="form-control" 
                                       value="<?= htmlspecialchars($teacher['employee_id'] ?? 'N/A') ?>" disabled>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Join Date</label>
                                <input type="text" class="form-control" 
                                       value="<?= date('F j, Y', strtotime($teacher['created_at'])) ?>" disabled>
                            </div>
                            
                            <div class="col-12 mt-3">
                                <button type="submit" name="update_profile" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Update Profile
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Change Password Tab -->
                <div class="tab-pane fade" id="password" role="tabpanel">
                    <form method="post" action="profile.php">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" required minlength="6">
                                <small class="text-muted">Minimum 6 characters</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Confirm New Password</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>
                            
                            <div class="col-12 mt-3">
                                <button type="submit" name="change_password" class="btn btn-primary">
                                    <i class="fas fa-key me-2"></i> Change Password
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Activity Tab -->
                <div class="tab-pane fade" id="activity" role="tabpanel">
                    <h5 class="mb-4">Recent Activity</h5>
                    
                    <?php if (!empty($activities)): ?>
                        <div class="list-group">
                            <?php foreach ($activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1">
                                                <i class="fas fa-clipboard-check text-success me-2"></i>
                                                Attendance Marked
                                            </h6>
                                            <p class="mb-1">
                                                <strong><?= htmlspecialchars($activity['course_name']) ?></strong>
                                                (<?= htmlspecialchars($activity['course_code']) ?>)
                                            </p>
                                            <small class="text-muted">
                                                Date: <?= date('M j, Y', strtotime($activity['attendance_date'])) ?> | 
                                                Present: <?= $activity['total_present'] ?> | 
                                                Absent: <?= $activity['total_absent'] ?>
                                            </small>
                                        </div>
                                        <small><?= date('h:i A', strtotime($activity['created_at'])) ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-history fa-3x text-muted mb-3"></i>
                            <h5>No Recent Activity</h5>
                            <p class="text-muted">Your recent activities will appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Settings Tab -->
                <div class="tab-pane fade" id="settings" role="tabpanel">
                    <h5 class="mb-4">Account Settings</h5>
                    
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-bell text-primary me-2"></i>
                                        Notification Settings
                                    </h6>
                                    <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                                        <label class="form-check-label" for="emailNotifications">
                                            Email Notifications
                                        </label>
                                    </div>
                                    <div class="form-check form-switch
                                                                        <div class="form-check form-switch mb-2">
                                        <input class="form-check-input" type="checkbox" id="attendanceReminders" checked>
                                        <label class="form-check-label" for="attendanceReminders">
                                            Attendance Reminders
                                        </label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="courseUpdates">
                                        <label class="form-check-label" for="courseUpdates">
                                            Course Updates
                                        </label>
                                    </div>
                                    <button class="btn btn-sm btn-primary mt-3">
                                        <i class="fas fa-save me-1"></i> Save Preferences
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">
                                        <i class="fas fa-palette text-success me-2"></i>
                                        Theme Preferences
                                    </h6>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="theme" id="lightTheme" checked>
                                        <label class="form-check-label" for="lightTheme">
                                            Light Theme
                                        </label>
                                    </div>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="theme" id="darkTheme">
                                        <label class="form-check-label" for="darkTheme">
                                            Dark Theme
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="theme" id="autoTheme">
                                        <label class="form-check-label" for="autoTheme">
                                            Auto (System)
                                        </label>
                                    </div>
                                    <button class="btn btn-sm btn-success mt-3">
                                        <i class="fas fa-paint-brush me-1"></i> Apply Theme
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="card border-danger">
                                <div class="card-body">
                                    <h6 class="card-title text-danger">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        Danger Zone
                                    </h6>
                                    <p class="text-muted mb-3">
                                        These actions are irreversible. Please proceed with caution.
                                    </p>
                                    
                                    <div class="d-flex flex-wrap gap-2">
                                        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#exportDataModal">
                                            <i class="fas fa-file-export me-1"></i> Export My Data
                                        </button>
                                        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deactivateModal">
                                            <i class="fas fa-user-slash me-1"></i> Deactivate Account
                                        </button>
                                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                                            <i class="fas fa-trash-alt me-1"></i> Delete Account
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Picture Modal -->
    <div class="modal fade" id="profilePictureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-camera me-2"></i> Update Profile Picture
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="text-center mb-4">
                            <?php if (!empty($teacher['profile_image'])): ?>
                                <img src="../uploads/profiles/<?= htmlspecialchars($teacher['profile_image']) ?>" 
                                     class="rounded-circle" width="150" height="150">
                            <?php else: ?>
                                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto" 
                                     style="width: 150px; height: 150px; font-size: 3rem; font-weight: 600;">
                                    <?= $initials ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Choose new profile picture</label>
                            <input type="file" name="profile_picture" class="form-control" accept="image/*" required>
                            <small class="text-muted">Max file size: 2MB. Allowed formats: JPG, PNG, GIF</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Picture</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Export Data Modal -->
    <div class="modal fade" id="exportDataModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-export me-2"></i> Export Your Data
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>You can export all your personal data including:</p>
                    <ul>
                        <li>Profile information</li>
                        <li>Course assignments</li>
                        <li>Attendance records</li>
                        <li>Activity history</li>
                    </ul>
                    <p>The data will be sent to your registered email address in CSV format.</p>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmExport">
                        <label class="form-check-label" for="confirmExport">
                            I confirm I want to export my data
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="exportDataBtn" disabled>
                        <i class="fas fa-download me-1"></i> Export Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Deactivate Account Modal -->
    <div class="modal fade" id="deactivateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-warning">
                        <i class="fas fa-user-slash me-2"></i> Deactivate Account
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action will temporarily disable your account.
                    </div>
                    <p>Your account will be deactivated and:</p>
                    <ul>
                        <li>You won't be able to log in</li>
                        <li>Your courses will be reassigned to other teachers</li>
                        <li>Your data will be preserved for 30 days</li>
                        <li>You can reactivate within 30 days by contacting admin</li>
                    </ul>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="confirmDeactivate">
                        <label class="form-check-label" for="confirmDeactivate">
                            I understand the consequences and want to deactivate my account
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" id="deactivateBtn" disabled>
                        <i class="fas fa-user-slash me-1"></i> Deactivate Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Account Modal -->
    <div class="modal fade" id="deleteAccountModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger">
                        <i class="fas fa-trash-alt me-2"></i> Delete Account Permanently
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Danger:</strong> This action cannot be undone!
                    </div>
                    <p>All your data will be permanently deleted including:</p>
                    <ul>
                        <li>Profile information</li>
                        <li>Course assignments</li>
                        <li>Attendance records</li>
                        <li>All activity history</li>
                    </ul>
                    <p>This action is irreversible. Please proceed with caution.</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Type "DELETE" to confirm</label>
                        <input type="text" class="form-control" id="deleteConfirm" placeholder="Type DELETE here">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="deleteAccountBtn" disabled>
                        <i class="fas fa-trash-alt me-1"></i> Delete Account Permanently
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // Form validation for export data
        document.getElementById('confirmExport').addEventListener('change', function() {
            document.getElementById('exportDataBtn').disabled = !this.checked;
        });
        
        // Form validation for deactivate account
        document.getElementById('confirmDeactivate').addEventListener('change', function() {
            document.getElementById('deactivateBtn').disabled = !this.checked;
        });
        
        // Form validation for delete account
        document.getElementById('deleteConfirm').addEventListener('input', function() {
            const deleteBtn = document.getElementById('deleteAccountBtn');
            deleteBtn.disabled = this.value.toUpperCase() !== 'DELETE';
        });
        
        // Handle export data button click
        document.getElementById('exportDataBtn').addEventListener('click', function() {
            alert('Data export request has been sent to your email.');
            bootstrap.Modal.getInstance(document.getElementById('exportDataModal')).hide();
        });
        
        // Handle deactivate button click
        document.getElementById('deactivateBtn').addEventListener('click', function() {
            if (confirm('Are you absolutely sure you want to deactivate your account?')) {
                // In a real application, you would send an AJAX request here
                alert('Account deactivation request submitted. Please check your email for confirmation.');
                bootstrap.Modal.getInstance(document.getElementById('deactivateModal')).hide();
            }
        });
        
        // Handle delete account button click
        document.getElementById('deleteAccountBtn').addEventListener('click', function() {
            if (confirm('FINAL WARNING: This will permanently delete all your data. This cannot be undone. Proceed?')) {
                // In a real application, you would send an AJAX request here
                alert('Account deletion request submitted. Please check your email for confirmation.');
                bootstrap.Modal.getInstance(document.getElementById('deleteAccountModal')).hide();
            }
        });
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>