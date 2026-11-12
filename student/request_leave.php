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

/* ======================================================
   FETCH STUDENT INFORMATION
====================================================== */
$student_sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        u.phone,
        u.profile_image,
        u.department_id,
        d.department_name,
        d.department_code
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
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
   FETCH ENROLLED COURSES FOR LEAVE REQUEST
====================================================== */
$courses_sql = "
    SELECT 
        c.course_id,
        c.course_code,
        c.course_name,
        u.full_name as teacher_name
    FROM course_students cs
    JOIN courses c ON cs.course_id = c.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE cs.student_id = ?
      AND cs.status = 'active'
    GROUP BY c.course_id
    ORDER BY c.course_code
";

$courses_stmt = $conn->prepare($courses_sql);
if ($courses_stmt) {
    $courses_stmt->bind_param("i", $student_id);
    $courses_stmt->execute();
    $courses_result = $courses_stmt->get_result();
}

/* ======================================================
   FETCH LEAVE BALANCE
====================================================== */
$leave_balance_sql = "
    SELECT 
        leave_type,
        total_allowed,
        used_so_far,
        (total_allowed - used_so_far) as remaining,
        valid_till
    FROM leave_balance
    WHERE student_id = ?
      AND valid_till >= CURDATE()
    ORDER BY leave_type
";

$balance_stmt = $conn->prepare($leave_balance_sql);
if ($balance_stmt) {
    $balance_stmt->bind_param("i", $student_id);
    $balance_stmt->execute();
    $balance_result = $balance_stmt->get_result();
}

/* ======================================================
   FETCH RECENT LEAVE REQUESTS
====================================================== */
$recent_requests_sql = "
    SELECT 
        l.*,
        c.course_code,
        c.course_name,
        u.full_name as approved_by_name,
        DATE_FORMAT(l.created_at, '%b %d, %Y') as requested_date,
        DATE_FORMAT(l.leave_date, '%a, %b %d, %Y') as leave_date_formatted,
        CASE 
            WHEN l.status = 'pending' THEN 'warning'
            WHEN l.status = 'approved' THEN 'success'
            WHEN l.status = 'rejected' THEN 'danger'
            ELSE 'secondary'
        END as status_color
    FROM leave_requests l
    LEFT JOIN courses c ON l.course_id = c.course_id
    LEFT JOIN users u ON l.approved_by = u.user_id
    WHERE l.student_id = ?
    ORDER BY l.created_at DESC
    LIMIT 5
";

$requests_stmt = $conn->prepare($recent_requests_sql);
if ($requests_stmt) {
    $requests_stmt->bind_param("i", $student_id);
    $requests_stmt->execute();
    $requests_result = $requests_stmt->get_result();
}

/* ======================================================
   PROCESS LEAVE REQUEST FORM
====================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type = $_POST['leave_type'] ?? '';
    $course_id = $_POST['course_id'] ?? null;
    $leave_date = $_POST['leave_date'] ?? '';
    $end_date = $_POST['end_date'] ?? $leave_date;
    $reason = $_POST['reason'] ?? '';
    $supporting_docs = $_FILES['supporting_docs'] ?? null;
    
    // Validation
    if (empty($leave_type) || empty($leave_date) || empty($reason)) {
        $error_msg = "Please fill in all required fields.";
    } else {
        // Check if date is not in past
        if (strtotime($leave_date) < strtotime(date('Y-m-d'))) {
            $error_msg = "Leave date cannot be in the past.";
        } else {
            // Check leave balance if applicable
            if ($leave_type != 'emergency') {
                $balance_check = $conn->prepare("
                    SELECT remaining FROM leave_balance 
                    WHERE student_id = ? AND leave_type = ? AND valid_till >= CURDATE()
                ");
                $balance_check->bind_param("is", $student_id, $leave_type);
                $balance_check->execute();
                $balance = $balance_check->get_result()->fetch_assoc();
                
                if (!$balance || $balance['remaining'] <= 0) {
                    $error_msg = "Insufficient leave balance for this type.";
                }
            }
            
            if (!$error_msg) {
                // Handle file upload
                $file_path = null;
                if ($supporting_docs && $supporting_docs['error'] == UPLOAD_ERR_OK) {
                    $upload_dir = '../uploads/leave_docs/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_name = time() . '_' . basename($supporting_docs['name']);
                    $file_path = 'leave_docs/' . $file_name;
                    $upload_file = $upload_dir . $file_name;
                    
                    if (move_uploaded_file($supporting_docs['tmp_name'], $upload_file)) {
                        // File uploaded successfully
                    }
                }
                
                // Insert leave request
                $insert_sql = "
                    INSERT INTO leave_requests (
                        student_id, course_id, leave_type, leave_date, end_date, 
                        reason, supporting_docs, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ";
                
                $insert_stmt = $conn->prepare($insert_sql);
                if ($insert_stmt) {
                    $insert_stmt->bind_param(
                        "iisssss", 
                        $student_id, $course_id, $leave_type, $leave_date, $end_date, 
                        $reason, $file_path
                    );
                    
                    if ($insert_stmt->execute()) {
                        $success_msg = "Leave request submitted successfully!";
                        
                        // Send notification to teacher if course-specific
                        if ($course_id) {
                            // Get teacher for this course
                            $teacher_sql = "
                                SELECT u.email, u.full_name 
                                FROM course_teachers ct
                                JOIN users u ON ct.teacher_id = u.user_id
                                WHERE ct.course_id = ?
                                LIMIT 1
                            ";
                            $teacher_stmt = $conn->prepare($teacher_sql);
                            $teacher_stmt->bind_param("i", $course_id);
                            $teacher_stmt->execute();
                            $teacher = $teacher_stmt->get_result()->fetch_assoc();
                            
                            if ($teacher) {
                                // In real app, send email notification
                                // mail($teacher['email'], "New Leave Request", ...);
                            }
                        }
                    } else {
                        $error_msg = "Failed to submit leave request: " . $conn->error;
                    }
                }
            }
        }
    }
}

/* ======================================================
   FETCH UPCOMING CLASSES FOR DATE SELECTION
====================================================== */
$upcoming_classes_sql = "
    SELECT 
        cs.day_of_week,
        cs.start_time,
        cs.end_time,
        c.course_code,
        c.course_name,
        u.full_name as teacher_name
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN course_students cos ON c.course_id = cos.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE cos.student_id = ?
      AND cs.is_active = 1
    ORDER BY cs.day_of_week, cs.start_time
";

$classes_stmt = $conn->prepare($upcoming_classes_sql);
if ($classes_stmt) {
    $classes_stmt->bind_param("i", $student_id);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
}

$page_title = "Request Leave";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Leave - DDU Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        
        .page-header {
            background: linear-gradient(135deg, var(--dept-color), #0055aa);
            color: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,51,102,0.2);
            position: relative;
            overflow: hidden;
        }
        
        .leave-form-card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border: 1px solid #f0f0f0;
        }
        
        .leave-form-card h4 {
            color: var(--ddu-blue);
            border-bottom: 3px solid var(--dept-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .form-section {
            margin-bottom: 25px;
            padding-bottom: 25px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .form-section:last-child {
            border-bottom: none;
        }
        
        .leave-type-card {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }
        
        .leave-type-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border-color: var(--dept-color);
        }
        
        .leave-type-card.selected {
            border-color: var(--ddu-green);
            background: linear-gradient(135deg, rgba(220,248,198,0.1), white);
        }
        
        .leave-type-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 15px;
        }
        
        .medical { background: #FF6B6B; color: white; }
        .personal { background: #4ECDC4; color: white; }
        .emergency { background: #FFD166; color: #333; }
        .academic { background: #06D6A0; color: white; }
        .other { background: #118AB2; color: white; }
        
        .balance-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 5px solid var(--dept-color);
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .request-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 5px solid;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .request-card:hover {
            transform: translateX(5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .request-card.pending { border-left-color: var(--ddu-orange); }
        .request-card.approved { border-left-color: var(--ddu-green); }
        .request-card.rejected { border-left-color: var(--ddu-red); }
        
        .calendar-preview {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .calendar-day {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 5px;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .calendar-day:hover {
            background: var(--dept-color);
            color: white;
        }
        
        .calendar-day.selected {
            background: var(--ddu-green);
            color: white;
            border-color: var(--ddu-green);
        }
        
        .calendar-day.has-class {
            background: #f8f9fa;
            border-color: var(--dept-color);
        }
        
        .calendar-day.weekend {
            color: #6c757d;
            opacity: 0.7;
        }
        
        .calendar-day.today {
            border-color: var(--ddu-orange);
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
        
        .file-upload-area.dragover {
            border-color: var(--ddu-green);
            background: rgba(40,167,69,0.1);
        }
        
        .uploaded-file {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .file-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
        }
        
        .file-icon.pdf { background: #FF6B6B; }
        .file-icon.doc { background: #2B579A; }
        .file-icon.jpg, .file-icon.png { background: #4CAF50; }
        .file-icon.default { background: #607D8B; }
        
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
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
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
            <a href="request_leave.php" class="nav-link active">
                <i class="fas fa-calendar-times"></i> Leave
            </a>
            <a href="resources.php" class="nav-link">
                <i class="fas fa-folder"></i> Resources
            </a>
            <a href="profile.php" class="nav-link">
                <i class="fas fa-user"></i> Profile
            </a>
            
            <div class="user-info-card mt-4">
                <div class="text-center mb-3">
                    <?php if (!empty($student['profile_image'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($student['profile_image']) ?>" 
                             class="profile-avatar mb-2" style="width: 60px; height: 60px;">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($student['full_name']) ?>&background=003366&color=fff&size=150&bold=true" 
                             class="profile-avatar mb-2" style="width: 60px; height: 60px;">
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
                    <i class="fas fa-calendar-times me-2"></i> Request Leave
                </h3>
                <p class="text-muted mb-0">
                    Submit and manage your leave requests
                </p>
            </div>
            
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                <a href="my_attendance.php" class="btn btn-primary">
                    <i class="fas fa-history me-1"></i> View Attendance
                </a>
            </div>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="fw-bold mb-3">Leave Request System</h1>
                    <p class="mb-0">
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-info-circle me-1"></i> Submit leave requests for approval
                        </span>
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-clock me-1"></i> Minimum 24 hours notice required
                        </span>
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-file-alt me-1"></i> Supporting documents recommended
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center" 
                         style="width: 100px; height: 100px;">
                        <i class="fas fa-file-contract fa-3x" style="color: var(--dept-color);"></i>
                    </div>
                    <small class="text-white-50 mt-2 d-block">Official Leave Application</small>
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
                                <h2 class="mb-0" id="totalRequests">0</h2>
                                <p class="mb-0">Total Requests</p>
                            </div>
                            <i class="fas fa-paper-plane stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0" id="approvedRequests">0</h2>
                                <p class="mb-0">Approved</p>
                            </div>
                            <i class="fas fa-check-circle stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-present">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0" id="pendingRequests">0</h2>
                                <p class="mb-0">Pending</p>
                            </div>
                            <i class="fas fa-clock stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-percentage">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0" id="leaveBalance">0</h2>
                                <p class="mb-0">Days Available</p>
                            </div>
                            <i class="fas fa-calendar-alt stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="row">
            <!-- Leave Request Form -->
            <div class="col-lg-8">
                <div class="leave-form-card">
                    <h4><i class="fas fa-file-medical me-2"></i> New Leave Request</h4>
                    
                    <?php if ($success_msg): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i> <?= $success_msg ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_msg): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i> <?= $error_msg ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" enctype="multipart/form-data" id="leaveForm">
                        <!-- Step 1: Leave Type -->
                        <div class="form-section">
                            <h5 class="mb-4"><i class="fas fa-list me-2"></i> 1. Select Leave Type</h5>
                            <div class="row" id="leaveTypeSelection">
                                <div class="col-md-4 mb-3">
                                    <div class="leave-type-card" data-type="medical">
                                        <div class="leave-type-icon medical">
                                            <i class="fas fa-heartbeat"></i>
                                        </div>
                                        <h6>Medical Leave</h6>
                                        <p class="text-muted small mb-0">Health-related issues</p>
                                        <small class="text-success">
                                            <i class="fas fa-check-circle me-1"></i>
                                            Doctor's note required
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="leave-type-card" data-type="personal">
                                        <div class="leave-type-icon personal">
                                            <i class="fas fa-home"></i>
                                        </div>
                                        <h6>Personal Leave</h6>
                                        <p class="text-muted small mb-0">Family/personal matters</p>
                                        <small class="text-info">
                                            <i class="fas fa-info-circle me-1"></i>
                                            5 days per semester
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="leave-type-card" data-type="emergency">
                                        <div class="leave-type-icon emergency">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </div>
                                        <h6>Emergency Leave</h6>
                                        <p class="text-muted small mb-0">Urgent situations</p>
                                        <small class="text-warning">
                                            <i class="fas fa-clock me-1"></i>
                                            Immediate approval needed
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="leave-type-card" data-type="academic">
                                        <div class="leave-type-icon academic">
                                            <i class="fas fa-graduation-cap"></i>
                                        </div>
                                        <h6>Academic Leave</h6>
                                        <p class="text-muted small mb-0">Conference/competition</p>
                                        <small class="text-primary">
                                            <i class="fas fa-file-alt me-1"></i>
                                            Proof of participation
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="leave-type-card" data-type="other">
                                        <div class="leave-type-icon other">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </div>
                                        <h6>Other Leave</h6>
                                        <p class="text-muted small mb-0">Other valid reasons</p>
                                        <small class="text-secondary">
                                            <i class="fas fa-question-circle me-1"></i>
                                            Case-by-case approval
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="leave_type" id="selectedLeaveType" required>
                        </div>
                        
                        <!-- Step 2: Course Selection -->
                        <div class="form-section">
                            <h5 class="mb-4"><i class="fas fa-book me-2"></i> 2. Select Course (Optional)</h5>
                            <div class="mb-3">
                                <label class="form-label">Choose affected course (if applicable)</label>
                                <select name="course_id" class="form-select" id="courseSelect">
                                    <option value="">-- All Courses (General Leave) --</option>
                                    <?php if ($courses_result && $courses_result->num_rows > 0): ?>
                                        <?php while ($course = $courses_result->fetch_assoc()): ?>
                                            <option value="<?= $course['course_id'] ?>">
                                                [<?= htmlspecialchars($course['course_code']) ?>] <?= htmlspecialchars($course['course_name']) ?>
                                                - <?= htmlspecialchars($course['teacher_name'] ?? 'No Instructor') ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </select>
                                <small class="text-muted">
                                    Leave blank if leave applies to all courses
                                </small>
                            </div>
                        </div>
                        
                        <!-- Step 3: Date Selection -->
                        <div class="form-section">
                            <h5 class="mb-4"><i class="fas fa-calendar-alt me-2"></i> 3. Select Dates</h5>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Start Date *</label>
                                    <input type="date" name="leave_date" class="form-control" 
                                           id="leaveDate" required min="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">End Date (Optional)</label>
                                    <input type="date" name="end_date" class="form-control" 
                                           id="endDate" min="<?= date('Y-m-d') ?>">
                                    <small class="text-muted">
                                        Leave blank for single day leave
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Calendar Preview -->
                            <div class="calendar-preview">
                                <h6 class="mb-3">Upcoming Classes on Selected Date</h6>
                                <div id="classSchedulePreview">
                                    <div class="text-center py-3">
                                        <i class="fas fa-calendar fa-2x text-muted mb-3"></i>
                                        <p class="text-muted mb-0">Select a date to view classes</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Reason -->
                        <div class="form-section">
                            <h5 class="mb-4"><i class="fas fa-comment-alt me-2"></i> 4. Reason for Leave *</h5>
                            <div class="mb-3">
                                <label class="form-label">Please provide detailed reason</label>
                                <textarea name="reason" class="form-control" rows="4" 
                                          placeholder="Explain the reason for your leave request..." 
                                          required></textarea>
                                <small class="text-muted">
                                    Minimum 50 characters. Be specific and provide relevant details.
                                </small>
                            </div>
                        </div>
                        
                        <!-- Step 5: Supporting Documents -->
                        <div class="form-section">
                            <h5 class="mb-4"><i class="fas fa-paperclip me-2"></i> 5. Supporting Documents (Optional)</h5>
                            <div class="file-upload-area" id="fileDropArea">
                                <i class="fas fa-cloud-upload-alt fa-3x mb-3" style="color: var(--dept-color);"></i>
                                <h5>Drop files here or click to upload</h5>
                                <p class="text-muted mb-3">
                                    Upload supporting documents (Medical certificate, invitation letter, etc.)
                                </p>
                                <input type="file" name="supporting_docs" id="fileInput" 
                                       class="d-none" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('fileInput').click()">
                                    <i class="fas fa-folder-open me-1"></i> Choose File
                                </button>
                                <small class="d-block text-muted mt-2">
                                    Max file size: 5MB • Supported formats: PDF, DOC, JPG, PNG
                                </small>
                            </div>
                            <div id="filePreview"></div>
                        </div>
                        
                        <!-- Step 6: Review & Submit -->
                        <div class="form-section">
                            <h5 class="mb-4"><i class="fas fa-check-circle me-2"></i> 6. Review & Submit</h5>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Important:</strong> 
                                <ul class="mb-0 mt-2">
                                    <li>Leave requests require minimum 24 hours advance notice</li>
                                    <li>Emergency leaves may be approved instantly</li>
                                    <li>False information may lead to disciplinary action</li>
                                    <li>You will be notified via email about approval status</li>
                                </ul>
                            </div>
                            
                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="termsCheck" required>
                                <label class="form-check-label" for="termsCheck">
                                    I confirm that the information provided is accurate and complete. 
                                    I understand that false information may lead to disciplinary action.
                                </label>
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" onclick="resetForm()">
                                    <i class="fas fa-redo me-1"></i> Reset Form
                                </button>
                                <button type="submit" class="btn btn-primary px-5">
                                    <i class="fas fa-paper-plane me-1"></i> Submit Leave Request
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Leave Balance -->
                <div class="balance-card">
                    <h6 class="mb-3">
                        <i class="fas fa-chart-pie me-2"></i>
                        Your Leave Balance
                    </h6>
                    
                    <?php if ($balance_result && $balance_result->num_rows > 0): ?>
                        <?php while ($balance = $balance_result->fetch_assoc()): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <span class="fw-medium"><?= ucfirst($balance['leave_type']) ?> Leave</span>
                                    <small class="text-muted d-block">
                                        Valid till: <?= date('M d, Y', strtotime($balance['valid_till'])) ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold"><?= $balance['remaining'] ?> days</div>
                                    <small class="text-muted">
                                        <?= $balance['used_so_far'] ?>/<?= $balance['total_allowed'] ?> used
                                    </small>
                                </div>
                            </div>
                            <div class="progress mb-3" style="height: 8px;">
                                <div class="progress-bar" 
                                     style="width: <?= ($balance['used_so_far'] / $balance['total_allowed']) * 100 ?>%;
                                            background: var(--dept-color);">
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-chart-pie fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No leave balance information available</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-center mt-3">
                        <button class="btn btn-outline-primary btn-sm" onclick="viewLeavePolicy()">
                            <i class="fas fa-file-contract me-1"></i> View Leave Policy
                        </button>
                    </div>
                </div>
                
                <!-- Recent Requests -->
                <div class="balance-card">
                    <h6 class="mb-3">
                        <i class="fas fa-history me-2"></i>
                        Recent Leave Requests
                    </h6>
                    
                    <?php if ($requests_result && $requests_result->num_rows > 0): ?>
                        <?php while ($request = $requests_result->fetch_assoc()): ?>
                            <div class="request-card <?= $request['status'] ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?= ucfirst($request['leave_type']) ?> Leave</h6>
                                        <small class="text-muted">
                                            <?= $request['leave_date_formatted'] ?>
                                            <?= $request['course_code'] ? ' • ' . $request['course_code'] : '' ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?= $request['status_color'] ?>">
                                        <?= ucfirst($request['status']) ?>
                                    </span>
                                </div>
                                <p class="mb-2 small"><?= htmlspecialchars(substr($request['reason'], 0, 80)) ?>...</p>
                                <div class="d-flex justify-content-between">
                                    <small class="text-muted">
                                        <?= $request['requested_date'] ?>
                                    </small>
                                    <?php if ($request['approved_by_name']): ?>
                                        <small class="text-muted">
                                            By: <?= htmlspecialchars($request['approved_by_name']) ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No leave requests yet</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-center mt-3">
                        <a href="leave_history.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-list-alt me-1"></i> View All Requests
                        </a>
                    </div>
                </div>
                
                <!-- Quick Tips -->
                <div class="balance-card">
                    <h6 class="mb-3">
                        <i class="fas fa-lightbulb me-2"></i>
                        Quick Tips
                    </h6>
                    <div class="mb-3">
                        <small class="text-success d-block mb-2">
                            <i class="fas fa-check-circle me-1"></i>
                            Submit requests at least 24 hours in advance
                        </small>
                        <small class="text-info d-block mb-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Medical leaves require doctor's certificate
                        </small>
                        <small class="text-warning d-block mb-2">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Emergency leaves can be requested after the fact
                        </small>
                        <small class="text-primary d-block mb-2">
                            <i class="fas fa-envelope me-1"></i>
                            Check your email for approval notifications
                        </small>
                    </div>
                </div>
                
                <!-- Academic Calendar -->
                <div class="balance-card">
                    <h6 class="mb-3">
                        <i class="fas fa-calendar me-2"></i>
                        Important Dates
                    </h6>
                    <div id="importantDates">
                        <div class="text-center py-3">
                            <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
                            <p class="text-muted mb-0 small">Loading academic calendar...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        // Initialize Flatpickr
        flatpickr("#leaveDate", {
            minDate: "today",
            onChange: function(selectedDates, dateStr) {
                updateClassSchedule(dateStr);
                if (!document.getElementById('endDate').value) {
                    document.getElementById('endDate').value = dateStr;
                }
                document.getElementById('endDate').min = dateStr;
            }
        });
        
        flatpickr("#endDate", {
            minDate: "today"
        });
        
        // Leave Type Selection
        document.querySelectorAll('.leave-type-card').forEach(card => {
            card.addEventListener('click', function() {
                // Remove selected class from all cards
                document.querySelectorAll('.leave-type-card').forEach(c => {
                    c.classList.remove('selected');
                });
                
                // Add selected class to clicked card
                this.classList.add('selected');
                
                // Set hidden input value
                const leaveType = this.dataset.type;
                document.getElementById('selectedLeaveType').value = leaveType;
                
                // Update form based on leave type
                updateFormForLeaveType(leaveType);
            });
        });
        
        function updateFormForLeaveType(leaveType) {
            const courseSelect = document.getElementById('courseSelect');
            const fileArea = document.getElementById('fileDropArea');
            
            // Update requirements based on leave type
            switch(leaveType) {
                case 'medical':
                    fileArea.innerHTML = `
                        <i class="fas fa-file-medical fa-3x mb-3" style="color: #FF6B6B;"></i>
                        <h5>Medical Certificate Required</h5>
                        <p class="text-muted mb-3">
                            Please upload doctor's certificate or medical report
                        </p>
                        <input type="file" name="supporting_docs" id="fileInput" 
                               class="d-none" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                        <button type="button" class="btn btn-outline-danger" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-file-medical me-1"></i> Upload Medical Certificate
                        </button>
                        <small class="d-block text-muted mt-2">
                            Required for medical leave approval
                        </small>
                    `;
                    break;
                    
                case 'academic':
                    fileArea.innerHTML = `
                        <i class="fas fa-graduation-cap fa-3x mb-3" style="color: #06D6A0;"></i>
                        <h5>Proof of Participation</h5>
                        <p class="text-muted mb-3">
                            Upload invitation letter or conference details
                        </p>
                        <input type="file" name="supporting_docs" id="fileInput" 
                               class="d-none" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                        <button type="button" class="btn btn-outline-success" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-file-alt me-1"></i> Upload Proof Document
                        </button>
                    `;
                    break;
                    
                default:
                    fileArea.innerHTML = `
                        <i class="fas fa-cloud-upload-alt fa-3x mb-3" style="color: var(--dept-color);"></i>
                        <h5>Drop files here or click to upload</h5>
                        <p class="text-muted mb-3">
                            Upload supporting documents (optional)
                        </p>
                        <input type="file" name="supporting_docs" id="fileInput" 
                               class="d-none" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <button type="button" class="btn btn-outline-primary" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-folder-open me-1"></i> Choose File
                        </button>
                        <small class="d-block text-muted mt-2">
                            Max file size: 5MB • Supported formats: PDF, DOC, JPG, PNG
                        </small>
                    `;
            }
            
            // Re-attach event listeners
            setupFileUpload();
        }
        
        // File Upload Handling
        function setupFileUpload() {
            const fileInput = document.getElementById('fileInput');
            const fileDropArea = document.getElementById('fileDropArea');
            const filePreview = document.getElementById('filePreview');
            
            if (fileInput) {
                fileInput.addEventListener('change', handleFileSelect);
            }
            
            if (fileDropArea) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    fileDropArea.addEventListener(eventName, preventDefaults, false);
                });
                
                ['dragenter', 'dragover'].forEach(eventName => {
                    fileDropArea.addEventListener(eventName, highlight, false);
                });
                
                ['dragleave', 'drop'].forEach(eventName => {
                    fileDropArea.addEventListener(eventName, unhighlight, false);
                });
                
                fileDropArea.addEventListener('drop', handleDrop, false);
            }
        }
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        function highlight() {
            document.getElementById('fileDropArea').classList.add('dragover');
        }
        
        function unhighlight() {
            document.getElementById('fileDropArea').classList.remove('dragover');
        }
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                // Simulate file input
                const fileInput = document.getElementById('fileInput');
                fileInput.files = files;
                handleFileSelect({ target: fileInput });
            }
        }
        
        function handleFileSelect(e) {
            const files = e.target.files;
            const filePreview = document.getElementById('filePreview');
            
            filePreview.innerHTML = '';
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileType = file.type;
                const fileName = file.name;
                const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
                
                // Check file size
                if (fileSize > 5) {
                    alert('File size exceeds 5MB limit: ' + fileName);
                    continue;
                }
                
                let iconClass = 'fas fa-file';
                let fileClass = 'default';
                
                if (fileType.includes('pdf')) {
                    iconClass = 'fas fa-file-pdf';
                    fileClass = 'pdf';
                } else if (fileType.includes('word') || fileType.includes('doc')) {
                    iconClass = 'fas fa-file-word';
                    fileClass = 'doc';
                } else if (fileType.includes('image')) {
                    iconClass = 'fas fa-file-image';
                    fileClass = 'jpg';
                }
                
                const fileElement = document.createElement('div');
                fileElement.className = 'uploaded-file';
                fileElement.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div class="file-icon ${fileClass}">
                            <i class="${iconClass}"></i>
                        </div>
                        <div>
                            <div class="fw-medium">${fileName}</div>
                            <small class="text-muted">${fileSize} MB</small>
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeFile(this)">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                filePreview.appendChild(fileElement);
            }
        }
        
        function removeFile(button) {
            const fileElement = button.closest('.uploaded-file');
            fileElement.remove();
            
            // Clear file input
            document.getElementById('fileInput').value = '';
        }
        
        // Update class schedule preview
        function updateClassSchedule(dateStr) {
            const previewDiv = document.getElementById('classSchedulePreview');
            
            // Show loading
            previewDiv.innerHTML = `
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary mb-2"></div>
                    <p class="text-muted mb-0">Loading classes for ${dateStr}...</p>
                </div>
            `;
            
            // Simulate API call
            setTimeout(() => {
                // This would be an AJAX call to fetch classes for the date
                const dayOfWeek = new Date(dateStr).toLocaleDateString('en-US', { weekday: 'long' });
                
                // Check if there are classes on this day
                const hasClasses = Math.random() > 0.5; // Simulated
                
                if (hasClasses) {
                    previewDiv.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>You have classes on ${dayOfWeek}</strong>
                            <div class="mt-2">
                                <small class="d-block">
                                    <i class="fas fa-clock me-1"></i> 10:00 AM - 11:30 AM: CS401
                                </small>
                                <small class="d-block">
                                    <i class="fas fa-clock me-1"></i> 02:00 PM - 03:30 PM: MA301
                                </small>
                            </div>
                        </div>
                        <small class="text-muted">
                            Consider requesting leave for specific courses if needed.
                        </small>
                    `;
                } else {
                    previewDiv.innerHTML = `
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>No classes scheduled for ${dayOfWeek}</strong>
                        </div>
                        <small class="text-muted">
                            You can take general leave for this day.
                        </small>
                    `;
                }
            }, 500);
        }
        
        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
                document.getElementById('leaveForm').reset();
                document.querySelectorAll('.leave-type-card').forEach(card => {
                    card.classList.remove('selected');
                });
                document.getElementById('selectedLeaveType').value = '';
                document.getElementById('filePreview').innerHTML = '';
                document.getElementById('classSchedulePreview').innerHTML = `
                    <div class="text-center py-3">
                        <i class="fas fa-calendar fa-2x text-muted mb-3"></i>
                        <p class="text-muted mb-0">Select a date to view classes</p>
                    </div>
                `;
            }
        }
        
        // View leave policy
        function viewLeavePolicy() {
            // In real app, open modal or new page
            alert('Opening leave policy document...');
            // window.open('leave_policy.pdf', '_blank');
        }
        
        // Load important dates
        function loadImportantDates() {
            fetch('get_important_dates.php')
                .then(response => response.json())
                .then(dates => {
                    const container = document.getElementById('importantDates');
                    let html = '';
                    
                    dates.forEach(date => {
                        html += `
                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                <div>
                                    <small class="fw-medium">${date.title}</small>
                                    <small class="text-muted d-block">${date.date}</small>
                                </div>
                                <span class="badge bg-${date.type === 'holiday' ? 'success' : 'info'}">
                                    ${date.type}
                                </span>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html || `
                        <div class="text-center py-3">
                            <i class="fas fa-calendar fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No important dates</p>
                        </div>
                    `;
                })
                .catch(error => {
                    document.getElementById('importantDates').innerHTML = `
                        <div class="text-center py-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-muted mb-3"></i>
                            <p class="text-muted mb-0">Failed to load dates</p>
                        </div>
                    `;
                });
        }
        
        // Update statistics
        function updateStatistics() {
            // Simulate statistics
            document.getElementById('totalRequests').textContent = '12';
            document.getElementById('approvedRequests').textContent = '8';
            document.getElementById('pendingRequests').textContent = '3';
            document.getElementById('leaveBalance').textContent = '15';
        }
        
        // Form validation
        document.getElementById('leaveForm').addEventListener('submit', function(e) {
            const leaveType = document.getElementById('selectedLeaveType').value;
            const leaveDate = document.getElementById('leaveDate').value;
            const reason = document.querySelector('textarea[name="reason"]').value;
            
            if (!leaveType) {
                e.preventDefault();
                alert('Please select a leave type.');
                return;
            }
            
            if (!leaveDate) {
                e.preventDefault();
                alert('Please select a leave date.');
                return;
            }
            
            if (reason.length < 50) {
                e.preventDefault();
                alert('Reason must be at least 50 characters long.');
                return;
            }
            
            // Check if date is at least 24 hours in advance (except emergency)
            if (leaveType !== 'emergency') {
                const selectedDate = new Date(leaveDate);
                const now = new Date();
                const hoursDiff = (selectedDate - now) / (1000 * 60 * 60);
                
                if (hoursDiff < 24) {
                    if (!confirm('This leave is requested with less than 24 hours notice. It may not be approved. Continue?')) {
                        e.preventDefault();
                        return;
                    }
                }
            }
        });
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            setupFileUpload();
            loadImportantDates();
            updateStatistics();
            
            // Auto-select today's date
            document.getElementById('leaveDate').value = new Date().toISOString().split('T')[0];
            
            // Set minimum end date
            document.getElementById('endDate').min = new Date().toISOString().split('T')[0];
        });
    </script>
</body>
</html>