<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

checkRole(['student']);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$student_id = (int) $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';
$password_error = '';
$profile_error = '';

/* ======================================================
   FETCH STUDENT INFORMATION
====================================================== */
$student_sql = "
    SELECT 
        u.*,
        d.department_name,
        d.department_code,
        sd.semester,
        sd.academic_year,
        sd.enrollment_date,
        sd.graduation_date,
        sd.current_cgpa
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    LEFT JOIN student_details sd ON u.user_id = sd.user_id
    WHERE u.user_id = ? AND u.role = 'student'
";

$student_stmt = $conn->prepare($student_sql);
if (!$student_stmt) {
    die("Student query failed: " . htmlspecialchars($conn->error));
}
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student_result = $student_stmt->get_result();
$student = $student_result->fetch_assoc();

if (!$student) {
    die("Student not found.");
}

$dept_color = $student['department_color'] ?? '#003366';

/* ======================================================
   FETCH ACADEMIC HISTORY
====================================================== */
$academic_sql = "
    SELECT 
        c.course_code,
        c.course_name,
        c.credits,
        c.semester,
        cs.enrollment_date,
        cs.status,
        COALESCE(AVG(a.marks), 0) as avg_grade
    FROM course_students cs
    JOIN courses c ON cs.course_id = c.course_id
    LEFT JOIN assignments a ON c.course_id = a.course_id AND a.student_id = cs.student_id
    WHERE cs.student_id = ?
    GROUP BY c.course_id
    ORDER BY c.semester, c.course_code
";

$academic_stmt = $conn->prepare($academic_sql);
if ($academic_stmt) {
    $academic_stmt->bind_param("i", $student_id);
    $academic_stmt->execute();
    $academic_result = $academic_stmt->get_result();
}

/* ======================================================
   FETCH ATTENDANCE STATISTICS
====================================================== */
$attendance_sql = "
    SELECT 
        c.course_code,
        c.course_name,
        COUNT(*) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as percentage
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
    GROUP BY c.course_id
    ORDER BY percentage DESC
    LIMIT 5
";

$attendance_stmt = $conn->prepare($attendance_sql);
if ($attendance_stmt) {
    $attendance_stmt->bind_param("i", $student_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
}

/* ======================================================
   FETACH RECENT ACTIVITIES
====================================================== */
$activities_sql = "
    SELECT 
        'attendance' as type,
        a.attendance_date as date,
        c.course_code,
        CONCAT('Marked ', a.status, ' in ', c.course_name) as description,
        a.status as extra_info
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
    
    UNION ALL
    
    SELECT 
        'assignment' as type,
        a.submission_date as date,
        c.course_code,
        CONCAT('Submitted ', a.title) as description,
        CONCAT('Grade: ', COALESCE(a.marks, 'Pending')) as extra_info
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
    
    UNION ALL
    
    SELECT 
        'leave' as type,
        l.created_at as date,
        c.course_code,
        CONCAT('Leave request ', l.status) as description,
        l.leave_type as extra_info
    FROM leave_requests l
    LEFT JOIN courses c ON l.course_id = c.course_id
    WHERE l.student_id = ?
    
    ORDER BY date DESC
    LIMIT 10
";

$activities_stmt = $conn->prepare($activities_sql);
if ($activities_stmt) {
    $activities_stmt->bind_param("iii", $student_id, $student_id, $student_id);
    $activities_stmt->execute();
    $activities_result = $activities_stmt->get_result();
}

/* ======================================================
   PROCESS PROFILE UPDATE
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = $_POST['full_name'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $emergency_contact = $_POST['emergency_contact'] ?? '';
    $blood_group = $_POST['blood_group'] ?? '';
    $current_address = $_POST['current_address'] ?? '';
    $permanent_address = $_POST['permanent_address'] ?? '';
    
    // Validate
    if (empty($full_name) || empty($phone)) {
        $error_msg = "Full name and phone are required.";
    } else {
        // Update user table
        $update_user_sql = "
            UPDATE users 
            SET full_name = ?, phone = ?, updated_at = NOW()
            WHERE id = ?
        ";
        
        $update_stmt = $conn->prepare($update_user_sql);
        if ($update_stmt) {
            $update_stmt->bind_param("ssi", $full_name, $phone, $student_id);
            
            if ($update_stmt->execute()) {
                // Update student details
                $check_details_sql = "SELECT user_id FROM student_details WHERE user_id = ?";
                $check_stmt = $conn->prepare($check_details_sql);
                $check_stmt->bind_param("s", $student['user_id']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    // Update existing
                    $update_details_sql = "
                        UPDATE student_details 
                        SET emergency_contact = ?, blood_group = ?, 
                            current_address = ?, permanent_address = ?
                        WHERE user_id = ?
                    ";
                    $update_details_stmt = $conn->prepare($update_details_sql);
                    $update_details_stmt->bind_param(
                        "sssss", 
                        $emergency_contact, $blood_group, 
                        $current_address, $permanent_address, 
                        $student['user_id']
                    );
                    $update_details_stmt->execute();
                } else {
                    // Insert new
                    $insert_details_sql = "
                        INSERT INTO student_details 
                        (user_id, emergency_contact, blood_group, current_address, permanent_address)
                        VALUES (?, ?, ?, ?, ?)
                    ";
                    $insert_details_stmt = $conn->prepare($insert_details_sql);
                    $insert_details_stmt->bind_param(
                        "sssss", 
                        $student['user_id'], $emergency_contact, $blood_group, 
                        $current_address, $permanent_address
                    );
                    $insert_details_stmt->execute();
                }
                
                $success_msg = "Profile updated successfully!";
                
                // Refresh student data
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                $student = $student_result->fetch_assoc();
            } else {
                $error_msg = "Failed to update profile: " . $conn->error;
            }
        }
    }
}

/* ======================================================
   PROCESS PASSWORD CHANGE
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $password_error = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $password_error = "New passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $password_error = "New password must be at least 8 characters long.";
    } else {
        // Verify current password
        $check_sql = "SELECT password FROM users WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $user = $check_result->fetch_assoc();
        
        if (password_verify($current_password, $user['password'])) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $hashed_password, $student_id);
            
            if ($update_stmt->execute()) {
                $success_msg = "Password changed successfully!";
            } else {
                $password_error = "Failed to change password.";
            }
        } else {
            $password_error = "Current password is incorrect.";
        }
    }
}

/* ======================================================
   PROCESS PROFILE PICTURE UPLOAD
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture'])) {
    $file = $_FILES['profile_picture'];
    
    if ($file['error'] == UPLOAD_ERR_OK) {
        // Validate file
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowed_types)) {
            $profile_error = "Only JPG, PNG, and GIF files are allowed.";
        } elseif ($file['size'] > $max_size) {
            $profile_error = "File size must be less than 5MB.";
        } else {
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $student_id . '_' . time() . '.' . $ext;
            $upload_dir = '../uploads/profiles/';
            
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                // Update database
                $update_sql = "UPDATE users SET profile_image = ?, updated_at = NOW() WHERE id = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("si", $filename, $student_id);
                
                if ($update_stmt->execute()) {
                    $success_msg = "Profile picture updated successfully!";
                    
                    // Update student data
                    $student['profile_image'] = $filename;
                } else {
                    $profile_error = "Failed to update profile picture.";
                }
            } else {
                $profile_error = "Failed to upload file.";
            }
        }
    } elseif ($file['error'] != UPLOAD_ERR_NO_FILE) {
        $profile_error = "File upload error: " . $file['error'];
    }
}

$page_title = "Student Profile";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profile - DDU Student Portal</title>
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
            --dept-color: <?= $dept_color ?>;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            background: linear-gradient(180deg, var(--ddu-blue) 0%, #002244 100%);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 280px;
            transition: all 0.3s;
            z-index: 1000;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
            transition: all 0.3s;
            min-height: 100vh;
        }
        
        .navbar-top {
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 20px 30px;
            margin: -30px -30px 30px -30px;
            border-radius: 0 0 20px 20px;
            position: sticky;
            top: 0;
            z-index: 999;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--dept-color), #0055aa);
            color: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,51,102,0.2);
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.3;
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: all 0.3s;
        }
        
        .profile-avatar:hover {
            transform: scale(1.05);
        }
        
        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border: 1px solid #f0f0f0;
        }
        
        .profile-card h5 {
            color: var(--ddu-blue);
            border-bottom: 3px solid var(--dept-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .info-item {
            padding: 15px 0;
            border-bottom: 1px solid #f8f9fa;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: var(--ddu-blue);
            min-width: 200px;
        }
        
        .info-value {
            color: #495057;
        }
        
        .edit-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .edit-btn:hover {
            background: white;
            color: var(--dept-color);
            transform: rotate(15deg);
        }
        
        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 25px;
            overflow: hidden;
            position: relative;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--dept-color), var(--ddu-gold));
        }
        
        .activity-item {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-left: 4px solid var(--dept-color);
            transition: all 0.3s;
        }
        
        .activity-item:hover {
            background: white;
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 15px;
        }
        
        .attendance-icon { background: var(--ddu-green); }
        .assignment-icon { background: var(--ddu-blue); }
        .leave-icon { background: var(--ddu-orange); }
        
        .nav-tabs .nav-link {
            color: var(--ddu-blue);
            font-weight: 500;
            border: none;
            padding: 12px 25px;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--dept-color);
            border-bottom: 3px solid var(--dept-color);
            background: transparent;
        }
        
        .tab-content {
            padding: 30px 0;
        }
        
        .file-upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        
        .file-upload-area:hover {
            border-color: var(--dept-color);
            background: rgba(0,51,102,0.05);
        }
        
        .badge-custom {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .progress-grade {
            height: 10px;
            border-radius: 5px;
            background: #e9ecef;
        }
        
        .progress-grade-bar {
            height: 100%;
            border-radius: 5px;
            transition: width 1s ease-in-out;
        }
        
        .course-badge {
            background: var(--dept-color);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 5px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            transition: width 0.3s, background 0.3s;
        }
        
        .strength-weak { background: var(--ddu-red); }
        .strength-fair { background: var(--ddu-orange); }
        .strength-good { background: #ffc107; }
        .strength-strong { background: var(--ddu-green); }
        
        @media (max-width: 992px) {
            .sidebar {
                margin-left: -280px;
            }
            .main-content {
                margin-left: 0;
            }
            .sidebar.active {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h4><i class="fas fa-graduation-cap me-2"></i> DDU Student Portal</h4>
            <small class="text-white-50">Digital Attendance System</small>
        </div>
        
        <nav class="nav flex-column mt-4">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="my_attendance.php" class="nav-link">
                <i class="fas fa-calendar-check"></i> Attendance
            </a>
            <a href="courses.php" class="nav-link">
                <i class="fas fa-book"></i> Courses
            </a>
            <a href="schedule.php" class="nav-link">
                <i class="fas fa-calendar-alt"></i> Schedule
            </a>
            <a href="grades.php" class="nav-link">
                <i class="fas fa-chart-line"></i> Grades
            </a>
            <a href="request_leave.php" class="nav-link">
                <i class="fas fa-calendar-times"></i> Leave
            </a>
            <a href="resources.php" class="nav-link">
                <i class="fas fa-folder"></i> Resources
            </a>
            <a href="profile.php" class="nav-link active">
                <i class="fas fa-user"></i> Profile
            </a>
            
            <div class="user-info-card mt-4">
                <div class="text-center mb-3">
                    <?php if (!empty($student['profile_image'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_image']) ?>" 
                             class="profile-avatar mb-2" style="width: 80px; height: 80px;">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($student['full_name']) ?>&background=003366&color=fff&size=150&bold=true" 
                             class="profile-avatar mb-2" style="width: 80px; height: 80px;">
                    <?php endif; ?>
                    <h6 class="mb-1"><?= htmlspecialchars($student['full_name']) ?></h6>
                    <small class="text-white-50">Student • <?= htmlspecialchars($student['department_name'] ?? 'Not assigned') ?></small>
                </div>
                <div class="text-center">
                    <a href="../logout.php" class="btn btn-outline-light btn-sm w-100">
                        <i class="fas fa-sign-out-alt me-2"></i> Logout
                    </a>
                </div>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar-top d-flex justify-content-between align-items-center">
            <div>
                <h3 class="mb-1 fw-bold" style="color: var(--ddu-blue);">
                    <i class="fas fa-user me-2"></i> My Profile
                </h3>
                <p class="text-muted mb-0">
                    Manage your personal information and account settings
                </p>
            </div>
            
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                <button class="btn btn-primary" onclick="printProfile()">
                    <i class="fas fa-print me-1"></i> Print Profile
                </button>
            </div>
        </div>
        
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <div class="position-relative d-inline-block">
                        <?php if (!empty($student['profile_image'])): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_image']) ?>" 
                                 class="profile-avatar">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($student['full_name']) ?>&background=fff&color=003366&size=150&bold=true" 
                                 class="profile-avatar">
                        <?php endif; ?>
                        <button class="edit-btn" data-bs-toggle="modal" data-bs-target="#profilePictureModal">
                            <i class="fas fa-camera"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-9">
                    <h1 class="fw-bold mb-2"><?= htmlspecialchars($student['full_name']) ?></h1>
                    <p class="mb-3">
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-id-card me-1"></i> <?= htmlspecialchars($student['user_id']) ?>
                        </span>
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-graduation-cap me-1"></i> <?= htmlspecialchars($student['department_name'] ?? 'Not assigned') ?>
                        </span>
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-calendar-alt me-1"></i> Sem <?= $student['semester'] ?? 'N/A' ?>
                        </span>
                    </p>
                    <div class="row">
                        <div class="col-md-4">
                            <p class="mb-1">
                                <i class="fas fa-envelope me-2"></i>
                                <?= htmlspecialchars($student['email']) ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1">
                                <i class="fas fa-phone me-2"></i>
                                <?= htmlspecialchars($student['phone'] ?? 'Not provided') ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1">
                                <i class="fas fa-calendar me-2"></i>
                                Member since <?= date('M Y', strtotime($student['created_at'])) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-attendance">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0">
                                    <?= $student['current_cgpa'] ? round($student['current_cgpa'], 2) : 'N/A' ?>
                                </h2>
                                <p class="mb-0">Current CGPA</p>
                            </div>
                            <i class="fas fa-chart-line stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            <?= $student['current_cgpa'] >= 3.5 ? 'Excellent' : ($student['current_cgpa'] >= 3.0 ? 'Good' : 'Needs Improvement') ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0">
                                    <?= $academic_result ? $academic_result->num_rows : 0 ?>
                                </h2>
                                <p class="mb-0">Enrolled Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            Current semester
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-present">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php
                                $avg_attendance = 0;
                                if ($attendance_result && $attendance_result->num_rows > 0) {
                                    $attendance_result->data_seek(0);
                                    $total_percentage = 0;
                                    $count = 0;
                                    while ($att = $attendance_result->fetch_assoc()) {
                                        $total_percentage += $att['percentage'];
                                        $count++;
                                    }
                                    $avg_attendance = $count > 0 ? round($total_percentage / $count, 1) : 0;
                                }
                                ?>
                                <h2 class="mb-0"><?= $avg_attendance ?>%</h2>
                                <p class="mb-0">Avg. Attendance</p>
                            </div>
                            <i class="fas fa-calendar-check stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            Overall attendance rate
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-percentage">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0">
                                    <?= $activities_result ? $activities_result->num_rows : 0 ?>
                                </h2>
                                <p class="mb-0">Recent Activities</p>
                            </div>
                            <i class="fas fa-history stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            Last 30 days
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Tabs -->
        <ul class="nav nav-tabs mb-4" id="profileTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab">
                    <i class="fas fa-user-circle me-2"></i> Personal Information
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic" type="button" role="tab">
                    <i class="fas fa-graduation-cap me-2"></i> Academic Details
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                    <i class="fas fa-lock me-2"></i> Security
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">
                    <i class="fas fa-history me-2"></i> Recent Activity
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="profileTabContent">
            <!-- Personal Information Tab -->
            <div class="tab-pane fade show active" id="personal" role="tabpanel">
                <div class="row">
                    <!-- Personal Details -->
                    <div class="col-lg-8">
                        <div class="profile-card">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="mb-0">
                                    <i class="fas fa-id-card me-2"></i>
                                    Personal Details
                                </h5>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                                    <i class="fas fa-edit me-1"></i> Edit Profile
                                </button>
                            </div>
                            
                            <?php if ($success_msg): ?>
                                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                                    <i class="fas fa-check-circle me-2"></i> <?= $success_msg ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($error_msg): ?>
                                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i> <?= $error_msg ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Full Name</div>
                                        <div class="info-value"><?= htmlspecialchars($student['full_name']) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Student ID</div>
                                        <div class="info-value"><?= htmlspecialchars($student['user_id']) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Email Address</div>
                                        <div class="info-value"><?= htmlspecialchars($student['email']) ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Phone Number</div>
                                        <div class="info-value"><?= htmlspecialchars($student['phone'] ?? 'Not provided') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Blood Group</div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($student['blood_group'] ?? 'Not provided') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Department</div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($student['department_name'] ?? 'Not assigned') ?>
                                            (<?= htmlspecialchars($student['department_code'] ?? 'N/A') ?>)
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Semester</div>
                                        <div class="info-value"><?= $student['semester'] ?? 'N/A' ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Academic Year</div>
                                        <div class="info-value"><?= htmlspecialchars($student['academic_year'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Emergency Contact</div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($student['emergency_contact'] ?? 'Not provided') ?>
                                        </div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Account Status</div>
                                        <div class="info-value">
                                            <span class="badge bg-<?= $student['is_active'] ? 'success' : 'danger' ?>">
                                                <?= $student['is_active'] ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Address Information -->
                            <h6 class="mt-4 mb-3">
                                <i class="fas fa-map-marker-alt me-2"></i>
                                Address Information
                            </h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Current Address</div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($student['current_address'] ?? 'Not provided') ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-item">
                                        <div class="info-label">Permanent Address</div>
                                        <div class="info-value">
                                            <?= htmlspecialchars($student['permanent_address'] ?? 'Not provided') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Account Summary -->
                    <div class="col-lg-4">
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-chart-pie me-2"></i>
                                Account Summary
                            </h5>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Account Created</small>
                                <div class="fw-medium">
                                    <?= date('F j, Y', strtotime($student['created_at'])) ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Last Updated</small>
                                <div class="fw-medium">
                                    <?= date('F j, Y', strtotime($student['updated_at'] ?? $student['created_at'])) ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Enrollment Date</small>
                                <div class="fw-medium">
                                    <?= !empty($student['enrollment_date']) ? date('F j, Y', strtotime($student['enrollment_date'])) : 'N/A' ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Expected Graduation</small>
                                <div class="fw-medium">
                                    <?= !empty($student['graduation_date']) ? date('F j, Y', strtotime($student['graduation_date'])) : 'N/A' ?>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <a href="id_card.php" class="btn btn-outline-primary btn-sm me-2">
                                    <i class="fas fa-id-card me-1"></i> ID Card
                                </a>
                                <a href="transcript.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-file-alt me-1"></i> Transcript
                                </a>
                            </div>
                        </div>
                        
                        <!-- Quick Links -->
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-link me-2"></i>
                                Quick Links
                            </h5>
                            
                            <div class="d-grid gap-2">
                                <a href="my_attendance.php" class="btn btn-outline-primary text-start">
                                    <i class="fas fa-calendar-check me-2"></i> Attendance Records
                                </a>
                                <a href="courses.php" class="btn btn-outline-primary text-start">
                                    <i class="fas fa-book me-2"></i> Course Enrollments
                                </a>
                                <a href="grades.php" class="btn btn-outline-primary text-start">
                                    <i class="fas fa-chart-line me-2"></i> Grade Reports
                                </a>
                                <a href="request_leave.php" class="btn btn-outline-primary text-start">
                                    <i class="fas fa-calendar-times me-2"></i> Leave Requests
                                </a>
                                <a href="settings.php" class="btn btn-outline-primary text-start">
                                    <i class="fas fa-cog me-2"></i> Account Settings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Academic Details Tab -->
            <div class="tab-pane fade" id="academic" role="tabpanel">
                <div class="row">
                    <!-- Course Performance -->
                    <div class="col-lg-8">
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-book-open me-2"></i>
                                Course Performance
                            </h5>
                            
                            <?php if ($academic_result && $academic_result->num_rows > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Course</th>
                                                <th>Semester</th>
                                                <th>Credits</th>
                                                <th>Average Grade</th>
                                                <th>Status</th>
                                                <th>Enrolled</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($course = $academic_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-medium"><?= htmlspecialchars($course['course_code']) ?></div>
                                                        <small class="text-muted"><?= htmlspecialchars($course['course_name']) ?></small>
                                                    </td>
                                                    <td><?= $course['semester'] ?></td>
                                                    <td><?= $course['credits'] ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div class="progress-grade me-3" style="width: 60px;">
                                                                <div class="progress-grade-bar" 
                                                                     style="width: <?= ($course['avg_grade'] / 100) * 100 ?>%;
                                                                            background: <?= $course['avg_grade'] >= 70 ? 'var(--ddu-green)' : 
                                                                                        ($course['avg_grade'] >= 50 ? 'var(--ddu-orange)' : 'var(--ddu-red)') ?>;">
                                                                </div>
                                                            </div>
                                                            <span class="fw-medium"><?= round($course['avg_grade'], 1) ?>%</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?= $course['status'] == 'active' ? 'success' : 'secondary' ?>">
                                                            <?= ucfirst($course['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= date('M Y', strtotime($course['enrollment_date'])) ?></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-book fa-3x text-muted mb-3"></i>
                                    <h5>No Course Information</h5>
                                    <p class="text-muted">You are not enrolled in any courses yet.</p>
                                    <a href="courses.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i> Enroll in Courses
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Attendance Summary -->
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-calendar-check me-2"></i>
                                Attendance Summary
                            </h5>
                            
                            <?php if ($attendance_result && $attendance_result->num_rows > 0): ?>
                                <?php 
                                $attendance_result->data_seek(0);
                                while ($att = $attendance_result->fetch_assoc()): 
                                ?>
                                    <div class="mb-4">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <h6 class="mb-0"><?= htmlspecialchars($att['course_name']) ?></h6>
                                                <small class="text-muted"><?= htmlspecialchars($att['course_code']) ?></small>
                                            </div>
                                            <span class="badge-custom 
                                                <?= $att['percentage'] >= 75 ? 'bg-success' : 
                                                   ($att['percentage'] >= 50 ? 'bg-warning' : 'bg-danger') ?>">
                                                <?= $att['percentage'] ?>%
                                            </span>
                                        </div>
                                        <div class="progress-grade">
                                            <div class="progress-grade-bar" 
                                                 style="width: <?= $att['percentage'] ?>%;
                                                        background: <?= $att['percentage'] >= 75 ? 'var(--ddu-green)' : 
                                                                    ($att['percentage'] >= 50 ? 'var(--ddu-orange)' : 'var(--ddu-red)') ?>;">
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between mt-2">
                                            <small class="text-muted">
                                                Present: <?= $att['present_count'] ?>
                                            </small>
                                            <small class="text-muted">
                                                Total: <?= $att['total_classes'] ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                                
                                <div class="text-center mt-4">
                                    <a href="my_attendance.php" class="btn btn-outline-primary">
                                        <i class="fas fa-chart-bar me-1"></i> View Detailed Attendance
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                    <h5>No Attendance Records</h5>
                                    <p class="text-muted">Your attendance records will appear here.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Academic Statistics -->
                    <div class="col-lg-4">
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-chart-line me-2"></i>
                                Academic Statistics
                            </h5>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Current CGPA</small>
                                <div class="d-flex align-items-center">
                                    <div class="progress-grade me-3" style="width: 100px;">
                                        <div class="progress-grade-bar" 
                                             style="width: <?= ($student['current_cgpa'] ?? 0) * 20 ?>%;
                                                    background: var(--dept-color);">
                                        </div>
                                    </div>
                                    <div class="fs-4 fw-bold">
                                        <?= $student['current_cgpa'] ? round($student['current_cgpa'], 2) : 'N/A' ?>
                                    </div>
                                </div>
                                <small class="text-muted mt-1">
                                    On a 4.0 scale
                                </small>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Total Credits Earned</small>
                                <div class="fs-3 fw-bold text-primary"><?= $student['credits_earned'] ?? 0 ?></div>
                                <small class="text-muted">
                                    Required: <?= $student['credits_required'] ?? 120 ?>
                                </small>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Academic Standing</small>
                                <div class="fw-medium">
                                    <?php if ($student['current_cgpa'] >= 3.5): ?>
                                        <span class="badge bg-success">First Class</span>
                                    <?php elseif ($student['current_cgpa'] >= 3.0): ?>
                                        <span class="badge bg-info">Second Class Upper</span>
                                    <?php elseif ($student['current_cgpa'] >= 2.0): ?>
                                        <span class="badge bg-warning">Second Class Lower</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Needs Improvement</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Attendance Rate</small>
                                <div class="d-flex align-items-center">
                                    <div class="progress-grade me-3" style="width: 100px;">
                                        <div class="progress-grade-bar" 
                                             style="width: <?= $avg_attendance ?>%;
                                                    background: <?= $avg_attendance >= 75 ? 'var(--ddu-green)' : 
                                                                ($avg_attendance >= 50 ? 'var(--ddu-orange)' : 'var(--ddu-red)') ?>;">
                                        </div>
                                    </div>
                                    <div class="fs-4 fw-bold"><?= $avg_attendance ?>%</div>
                                </div>
                                <small class="text-muted mt-1">
                                    Overall attendance percentage
                                </small>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button class="btn btn-primary w-100" onclick="downloadTranscript()">
                                    <i class="fas fa-download me-1"></i> Download Transcript
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Security Tab -->
            <div class="tab-pane fade" id="security" role="tabpanel">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Change Password -->
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-key me-2"></i>
                                Change Password
                            </h5>
                            
                            <?php if ($password_error): ?>
                                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i> <?= $password_error ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="" id="passwordForm">
                                <input type="hidden" name="change_password" value="1">
                                
                                <div class="mb-4">
                                    <label class="form-label">Current Password</label>
                                    <div class="input-group">
                                        <input type="password" name="current_password" class="form-control" 
                                               id="currentPassword" required>
                                        <button class="btn btn-outline-secondary" type="button" 
                                                onclick="togglePassword('currentPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" class="form-control" 
                                               id="newPassword" required minlength="8"
                                               onkeyup="checkPasswordStrength(this.value)">
                                        <button class="btn btn-outline-secondary" type="button" 
                                                onclick="togglePassword('newPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength mt-2">
                                        <div class="password-strength-bar" id="passwordStrength"></div>
                                    </div>
                                    <small class="text-muted">
                                        Password must be at least 8 characters long
                                    </small>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" class="form-control" 
                                               id="confirmPassword" required minlength="8">
                                        <button class="btn btn-outline-secondary" type="button" 
                                                onclick="togglePassword('confirmPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small id="passwordMatch" class="text-muted"></small>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Password Requirements:</strong>
                                    <ul class="mb-0 mt-2">
                                        <li>Minimum 8 characters</li>
                                        <li>Include uppercase and lowercase letters</li>
                                        <li>Include at least one number</li>
                                        <li>Include at least one special character</li>
                                    </ul>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Update Password
                                </button>
                            </form>
                        </div>
                        
                        <!-- Security Settings -->
                        <div class="profile-card mt-4">
                            <h5 class="mb-4">
                                <i class="fas fa-shield-alt me-2"></i>
                                Security Settings
                            </h5>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="twoFactorAuth">
                                <label class="form-check-label" for="twoFactorAuth">
                                    Two-Factor Authentication
                                </label>
                                <small class="text-muted d-block">
                                    Add an extra layer of security to your account
                                </small>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="emailNotifications" checked>
                                <label class="form-check-label" for="emailNotifications">
                                    Email Notifications
                                </label>
                                <small class="text-muted d-block">
                                    Receive important updates via email
                                </small>
                            </div>
                            
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="sessionTimeout">
                                <label class="form-check-label" for="sessionTimeout">
                                    Automatic Session Timeout
                                </label>
                                <small class="text-muted d-block">
                                    Logout after 30 minutes of inactivity
                                </small>
                            </div>
                            
                            <div class="mt-4">
                                <button class="btn btn-outline-primary" onclick="saveSecuritySettings()">
                                    <i class="fas fa-save me-1"></i> Save Settings
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Login Activity -->
                    <div class="col-lg-4">
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-history me-2"></i>
                                Login Activity
                            </h5>
                            
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">Last Login</small>
                                <div class="fw-medium">
                                    <?= date('F j, Y h:i A', strtotime($student['last_login'] ?? $student['created_at'])) ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">Current Session</small>
                                <div class="fw-medium">
                                    Started: <?= date('h:i A') ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-1">Active Devices</small>
                                <div class="fw-medium">1 Device</div>
                                <small class="text-muted">This browser session</small>
                            </div>
                            
                            <div class="text-center">
                                <button class="btn btn-outline-danger btn-sm" onclick="logoutAllDevices()">
                                    <i class="fas fa-sign-out-alt me-1"></i> Logout All Devices
                                </button>
                            </div>
                        </div>
                        
                        <!-- Account Actions -->
                        <div class="profile-card mt-4">
                            <h5 class="mb-4">
                                <i class="fas fa-cog me-2"></i>
                                Account Actions
                            </h5>
                            
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-primary text-start" onclick="exportData()">
                                    <i class="fas fa-download me-2"></i> Export My Data
                                </button>
                                <button class="btn btn-outline-primary text-start" onclick="downloadIdCard()">
                                    <i class="fas fa-id-card me-2"></i> Download ID Card
                                </button>
                                <button class="btn btn-outline-primary text-start" onclick="requestTranscript()">
                                    <i class="fas fa-file-alt me-2"></i> Request Official Transcript
                                </button>
                                <button class="btn btn-outline-danger text-start" onclick="deleteAccount()">
                                    <i class="fas fa-trash-alt me-2"></i> Delete Account
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity Tab -->
            <div class="tab-pane fade" id="activity" role="tabpanel">
                <div class="row">
                    <div class="col-lg-8">
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-stream me-2"></i>
                                Recent Activity Timeline
                            </h5>
                            
                            <?php if ($activities_result && $activities_result->num_rows > 0): ?>
                                <?php while ($activity = $activities_result->fetch_assoc()): 
                                    $icon_class = '';
                                    $bg_class = '';
                                    
                                    if ($activity['type'] == 'attendance') {
                                        $icon_class = 'fas fa-calendar-check';
                                        $bg_class = 'attendance-icon';
                                    } elseif ($activity['type'] == 'assignment') {
                                        $icon_class = 'fas fa-file-alt';
                                        $bg_class = 'assignment-icon';
                                    } elseif ($activity['type'] == 'leave') {
                                        $icon_class = 'fas fa-calendar-times';
                                        $bg_class = 'leave-icon';
                                    }
                                ?>
                                    <div class="activity-item">
                                        <div class="d-flex align-items-center">
                                            <div class="<?= $bg_class ?>">
                                                <i class="<?= $icon_class ?>"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($activity['description']) ?></h6>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($activity['course_code']): ?>
                                                        <span class="course-badge me-3">
                                                            <?= htmlspecialchars($activity['course_code']) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <small class="text-muted me-3">
                                                        <i class="far fa-calendar me-1"></i>
                                                        <?= date('M j, Y', strtotime($activity['date'])) ?>
                                                    </small>
                                                    <?php if ($activity['extra_info']): ?>
                                                        <small class="text-muted">
                                                            <i class="fas fa-info-circle me-1"></i>
                                                            <?= htmlspecialchars($activity['extra_info']) ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                    <h5>No Recent Activity</h5>
                                    <p class="text-muted">Your activities will appear here.</p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-center mt-4">
                                <a href="activity_log.php" class="btn btn-outline-primary">
                                    <i class="fas fa-list-alt me-1"></i> View Full Activity Log
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Activity Statistics -->
                    <div class="col-lg-4">
                        <div class="profile-card">
                            <h5 class="mb-4">
                                <i class="fas fa-chart-bar me-2"></i>
                                Activity Statistics
                            </h5>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">This Month</small>
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="fw-bold fs-4">12</div>
                                        <small class="text-muted">Classes</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="fw-bold fs-4">3</div>
                                        <small class="text-muted">Assignments</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="fw-bold fs-4">1</div>
                                        <small class="text-muted">Leaves</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <small class="text-muted d-block mb-2">Weekly Activity</small>
                                <canvas id="activityChart" height="150"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="update_profile" value="1">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" name="full_name" class="form-control" 
                                       value="<?= htmlspecialchars($student['full_name']) ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phone Number *</label>
                                <input type="tel" name="phone" class="form-control" 
                                       value="<?= htmlspecialchars($student['phone'] ?? '') ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Emergency Contact</label>
                                <input type="tel" name="emergency_contact" class="form-control" 
                                       value="<?= htmlspecialchars($student['emergency_contact'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Blood Group</label>
                                <select name="blood_group" class="form-select">
                                    <option value="">Select Blood Group</option>
                                    <option value="A+" <?= ($student['blood_group'] ?? '') == 'A+' ? 'selected' : '' ?>>A+</option>
                                    <option value="A-" <?= ($student['blood_group'] ?? '') == 'A-' ? 'selected' : '' ?>>A-</option>
                                    <option value="B+" <?= ($student['blood_group'] ?? '') == 'B+' ? 'selected' : '' ?>>B+</option>
                                    <option value="B-" <?= ($student['blood_group'] ?? '') == 'B-' ? 'selected' : '' ?>>B-</option>
                                    <option value="O+" <?= ($student['blood_group'] ?? '') == 'O+' ? 'selected' : '' ?>>O+</option>
                                    <option value="O-" <?= ($student['blood_group'] ?? '') == 'O-' ? 'selected' : '' ?>>O-</option>
                                    <option value="AB+" <?= ($student['blood_group'] ?? '') == 'AB+' ? 'selected' : '' ?>>AB+</option>
                                    <option value="AB-" <?= ($student['blood_group'] ?? '') == 'AB-' ? 'selected' : '' ?>>AB-</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Current Address</label>
                            <textarea name="current_address" class="form-control" rows="3"><?= htmlspecialchars($student['current_address'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Permanent Address</label>
                            <textarea name="permanent_address" class="form-control" rows="3"><?= htmlspecialchars($student['permanent_address'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Profile Picture Modal -->
    <div class="modal fade" id="profilePictureModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-camera me-2"></i> Update Profile Picture</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-body">
                        <?php if ($profile_error): ?>
                            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i> <?= $profile_error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mb-4">
                            <?php if (!empty($student['profile_image'])): ?>
                                <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_image']) ?>" 
                                     id="currentProfilePic" class="rounded-circle" width="150" height="150">
                            <?php else: ?>
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($student['full_name']) ?>&background=003366&color=fff&size=150" 
                                     id="currentProfilePic" class="rounded-circle" width="150" height="150">
                            <?php endif; ?>
                        </div>
                        
                        <div class="file-upload-area">
                            <i class="fas fa-cloud-upload-alt fa-3x mb-3" style="color: var(--dept-color);"></i>
                            <h5>Choose a new profile picture</h5>
                            <p class="text-muted mb-3">
                                Recommended size: 500x500 pixels
                            </p>
                            <input type="file" name="profile_picture" id="profilePictureInput" 
                                   class="d-none" accept="image/*" onchange="previewProfilePicture(this)">
                            <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('profilePictureInput').click()">
                                <i class="fas fa-folder-open me-1"></i> Choose File
                            </button>
                            <small class="d-block text-muted mt-2">
                                Max file size: 5MB • Supported formats: JPG, PNG, GIF
                            </small>
                        </div>
                        
                        <div id="profilePicturePreview" class="text-center mt-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Picture</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            let strength = 0;
            
            // Length check
            if (password.length >= 8) strength++;
            if (password.length >= 12) strength++;
            
            // Mixed case check
            if (password.match(/([a-z].*[A-Z])|([A-Z].*[a-z])/)) strength++;
            
            // Numbers check
            if (password.match(/([0-9])/)) strength++;
            
            // Special characters check
            if (password.match(/([!,%,&,@,#,$,^,*,?,_,~])/)) strength++;
            
            // Update strength bar
            let width = 0;
            let className = 'strength-weak';
            
            switch(strength) {
                case 0:
                case 1:
                    width = 25;
                    className = 'strength-weak';
                    break;
                case 2:
                    width = 50;
                    className = 'strength-fair';
                    break;
                case 3:
                    width = 75;
                    className = 'strength-good';
                    break;
                case 4:
                case 5:
                    width = 100;
                    className = 'strength-strong';
                    break;
            }
            
            strengthBar.style.width = width + '%';
            strengthBar.className = 'password-strength-bar ' + className;
        }
        
        // Password match checker
        document.getElementById('confirmPassword')?.addEventListener('input', function() {
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = this.value;
            const matchIndicator = document.getElementById('passwordMatch');
            
            if (confirmPassword === '') {
                matchIndicator.textContent = '';
                matchIndicator.className = 'text-muted';
            } else if (newPassword === confirmPassword) {
                matchIndicator.textContent = 'Passwords match';
                matchIndicator.className = 'text-success';
            } else {
                matchIndicator.textContent = 'Passwords do not match';
                matchIndicator.className = 'text-danger';
            }
        });
        
        // Toggle password visibility
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const button = input.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
        
        // Preview profile picture
        function previewProfilePicture(input) {
            const previewDiv = document.getElementById('profilePicturePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewDiv.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Preview:</strong>
                            <div class="text-center mt-2">
                                <img src="${e.target.result}" class="rounded-circle" width="100" height="100">
                            </div>
                        </div>
                    `;
                };
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Print profile
        function printProfile() {
            window.print();
        }
        
        // Download transcript
        function downloadTranscript() {
            alert('Downloading transcript...');
            // In real app: window.open('download_transcript.php', '_blank');
        }
        
        // Download ID card
        function downloadIdCard() {
            alert('Downloading ID card...');
            // In real app: window.open('download_id_card.php', '_blank');
        }
        
        // Export data
        function exportData() {
            if (confirm('This will export all your personal data. Continue?')) {
                alert('Preparing your data for export...');
                // In real app: window.open('export_data.php', '_blank');
            }
        }
        
        // Request transcript
        function requestTranscript() {
            alert('Requesting official transcript...');
            // In real app: window.open('request_transcript.php', '_blank');
        }
        
        // Delete account
        function deleteAccount() {
            if (confirm('Are you sure you want to delete your account? This action cannot be undone.')) {
                if (confirm('This will permanently delete all your data. Type DELETE to confirm:')) {
                    const confirmation = prompt('Type DELETE to confirm:');
                    if (confirmation === 'DELETE') {
                        alert('Account deletion requested. You will be logged out.');
                        // In real app: window.location.href = 'delete_account.php';
                    }
                }
            }
        }
        
        // Logout all devices
        function logoutAllDevices() {
            if (confirm('This will log you out from all devices. Continue?')) {
                alert('Logging out from all devices...');
                // In real app: window.location.href = 'logout_all.php';
            }
        }
        
        // Save security settings
        function saveSecuritySettings() {
            const twoFactorAuth = document.getElementById('twoFactorAuth').checked;
            const emailNotifications = document.getElementById('emailNotifications').checked;
            const sessionTimeout = document.getElementById('sessionTimeout').checked;
            
            // In real app, this would be an AJAX call
            fetch('update_security_settings.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    two_factor_auth: twoFactorAuth,
                    email_notifications: emailNotifications,
                    session_timeout: sessionTimeout
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Security settings updated successfully!');
                } else {
                    alert('Failed to update settings: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error updating settings. Please try again.');
            });
        }
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Activity chart
            const activityCtx = document.getElementById('activityChart');
            if (activityCtx) {
                new Chart(activityCtx, {
                    type: 'bar',
                    data: {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        datasets: [{
                            label: 'Activities',
                            data: [3, 5, 2, 8, 4, 1, 0],
                            backgroundColor: var(--dept-color),
                            borderColor: var(--dept-color),
                            borderWidth: 1,
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            }
            
            // Initialize form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    // Add any additional validation here
                });
            });
        });
    </script>
</body>
</html>