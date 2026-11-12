<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

// Check student role
checkRole(['student']);

$student_id = $_SESSION['user_id'];

// Get student information for header
$student_query = $conn->prepare("
    SELECT u.full_name, u.email, u.profile_image, 
           d.department_name, d.department_code
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.user_id = ? AND u.role = 'student'
");
$student_query->bind_param("i", $student_id);
$student_query->execute();
$student_result = $student_query->get_result();
$student = $student_result->fetch_assoc();

// Get student's enrolled courses
$courses_query = $conn->prepare("
    SELECT c.course_id, c.course_code, c.course_name, 
           u.full_name as teacher_name
    FROM courses c
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE c.course_id IN (
        SELECT course_id FROM course_students WHERE student_id = ?
    )
    AND c.is_active = 1
    ORDER BY c.course_name
");
$courses_query->bind_param("i", $student_id);
$courses_query->execute();
$courses_result = $courses_query->get_result();

// Get attendance details for selected course
$selected_course_id = $_GET['course_id'] ?? 0;
$attendance_details = null;
$selected_course_name = '';

if ($selected_course_id > 0) {
    // Get course name
    $course_name_query = $conn->prepare("
        SELECT course_name FROM courses WHERE course_id = ?
    ");
    $course_name_query->bind_param("i", $selected_course_id);
    $course_name_query->execute();
    $course_name_result = $course_name_query->get_result();
    $course_data = $course_name_result->fetch_assoc();
    $selected_course_name = $course_data['course_name'] ?? '';
    
    $attendance_query = $conn->prepare("
        SELECT 
            a.attendance_id,
            a.attendance_date,
            a.session_type,
            a.status,
            a.check_in_time,
            a.check_out_time,
            a.notes,
            u.full_name as marked_by
        FROM attendance a
        LEFT JOIN users u ON a.marked_by = u.user_id
        WHERE a.student_id = ? AND a.course_id = ?
        ORDER BY a.attendance_date DESC, a.check_in_time DESC
    ");
    $attendance_query->bind_param("ii", $student_id, $selected_course_id);
    $attendance_query->execute();
    $attendance_details = $attendance_query->get_result();
    
    // Get course-specific stats
    $course_stats_query = $conn->prepare("
        SELECT 
            COUNT(*) as total_sessions,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count,
            ROUND(
                COALESCE(
                    (SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0)),
                    0
                ), 2
            ) as attendance_percentage
        FROM attendance
        WHERE student_id = ? AND course_id = ?
    ");
    $course_stats_query->bind_param("ii", $student_id, $selected_course_id);
    $course_stats_query->execute();
    $course_stats_result = $course_stats_query->get_result();
    $course_stats = $course_stats_result->fetch_assoc();
}

// Calculate overall statistics
$stats_query = $conn->prepare("
    SELECT 
        COUNT(DISTINCT course_id) as total_courses,
        COUNT(*) as total_sessions,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN status = 'excused' THEN 1 ELSE 0 END) as excused_count,
        ROUND(
            COALESCE(
                (SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0)),
                0
            ), 2
        ) as overall_percentage
    FROM attendance
    WHERE student_id = ?
");
$stats_query->bind_param("i", $student_id);
$stats_query->execute();
$stats_result = $stats_query->get_result();
$stats = $stats_result->fetch_assoc();

// Set default values if null
if (!$stats) {
    $stats = [
        'total_courses' => 0,
        'total_sessions' => 0,
        'present_count' => 0,
        'absent_count' => 0,
        'late_count' => 0,
        'excused_count' => 0,
        'overall_percentage' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Attendance - DDU Attendance System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-green: #28a745;
            --ddu-red: #dc3545;
            --ddu-yellow: #ffc107;
            --ddu-cyan: #17a2b8;
            --ddu-gray: #f8f9fa;
            --sidebar-width: 280px;
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', 'Roboto', 'Helvetica Neue', Arial, sans-serif;
        }

        body {
            background-color: #f5f7fa;
            min-height: 100vh;
            overflow-x: hidden;
            color: #333;
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: linear-gradient(180deg, var(--ddu-blue) 0%, #002244 100%);
            position: fixed;
            left: 0;
            top: 0;
            z-index: 1000;
            padding: 0;
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.15);
            transition: transform var(--transition-speed) ease;
        }

        .sidebar-header {
            padding: 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            text-align: center;
        }

        .sidebar-header h4 {
            color: white;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .sidebar-header small {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            letter-spacing: 0.5px;
        }

        .sidebar-nav {
            padding: 20px 15px;
            height: calc(100vh - 150px);
            overflow-y: auto;
        }

        .sidebar-nav::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .sidebar-nav::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 10px;
        }

        .nav-link-custom {
            color: rgba(255, 255, 255, 0.8);
            padding: 12px 20px;
            margin: 5px 0;
            border-radius: 10px;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            text-decoration: none;
            border: none;
            background: transparent;
            width: 100%;
            text-align: left;
        }

        .nav-link-custom:hover,
        .nav-link-custom.active {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            transform: translateX(5px);
        }

        .nav-link-custom i {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .user-info-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .user-info-card h6 {
            color: white;
            margin: 5px 0;
            font-weight: 600;
        }

        .user-info-card small {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
        }

        /* ===== MAIN CONTENT ===== */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 25px;
            min-height: 100vh;
            transition: margin-left var(--transition-speed) ease;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                padding-top: 80px;
            }
            
            .sidebar {
                transform: translateX(-100%);
                box-shadow: none;
            }
            
            .sidebar.mobile-open {
                transform: translateX(0);
                box-shadow: 5px 0 25px rgba(0, 0, 0, 0.3);
            }
        }

        /* ===== TOP NAVBAR ===== */
        .top-navbar {
            background: white;
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title h4 {
            color: var(--ddu-blue);
            font-weight: 700;
            margin-bottom: 5px;
        }

        .page-title small {
            color: #6c757d;
            font-size: 14px;
        }

        .top-navbar-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        /* ===== MOBILE MENU BUTTON ===== */
        .mobile-menu-btn {
            display: none;
            background: var(--ddu-blue);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 15px;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mobile-menu-btn:hover {
            background: #002244;
        }

        @media (max-width: 992px) {
            .mobile-menu-btn {
                display: inline-block;
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 999;
            }
        }

        /* ===== ATTENDANCE CARDS ===== */
        .attendance-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            background: white;
        }

        .attendance-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .progress-circle {
            width: 120px;
            height: 120px;
            position: relative;
            margin: 0 auto 15px;
        }

        .progress-circle svg {
            transform: rotate(-90deg);
        }

        .progress-circle .progress-ring-bg {
            stroke: #e9ecef;
            stroke-width: 8;
            fill: none;
        }

        .progress-circle .progress-ring {
            stroke-linecap: round;
            transition: stroke-dashoffset 0.35s;
            stroke-width: 8;
            fill: none;
        }

        .progress-value {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 24px;
            font-weight: bold;
            color: var(--ddu-blue);
        }

        .course-list-item {
            padding: 15px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .course-list-item:hover {
            border-color: var(--ddu-blue);
            background: #f8f9fa;
        }

        .course-list-item.active {
            border-color: var(--ddu-blue);
            background: #e7f1ff;
            box-shadow: 0 2px 10px rgba(0, 123, 255, 0.2);
        }

        /* ===== ATTENDANCE BADGES ===== */
        .attendance-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-present { 
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .badge-absent { 
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .badge-late { 
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .badge-excused { 
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* ===== CALENDAR STYLES ===== */
        .calendar-day {
            padding: 8px;
            border: 1px solid #dee2e6;
            height: 80px;
            font-size: 12px;
            position: relative;
        }

        .calendar-day.today {
            background: #e7f1ff;
            border-color: var(--ddu-blue);
        }

        .calendar-day .day-number {
            font-weight: 600;
            margin-bottom: 3px;
        }

        .attendance-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 2px;
        }

        .dot-present { background: #28a745; }
        .dot-absent { background: #dc3545; }
        .dot-late { background: #ffc107; }
        .dot-excused { background: #17a2b8; }

        /* ===== DATA TABLE STYLES ===== */
        .dataTables_wrapper {
            margin-top: 20px;
        }

        .dataTables_length select,
        .dataTables_filter input {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 5px 10px;
        }

        table.dataTable {
            border-collapse: collapse !important;
        }

        table.dataTable thead th {
            background: var(--ddu-blue);
            color: white;
            border: none;
            padding: 15px;
            font-weight: 600;
        }

        table.dataTable tbody td {
            padding: 12px;
            vertical-align: middle;
            border-color: #eee;
        }

        table.dataTable tbody tr:hover {
            background-color: rgba(0, 123, 255, 0.05);
        }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        /* ===== NOTIFICATION BADGE ===== */
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--ddu-red);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        /* ===== CHART CONTAINER ===== */
        .chart-container {
            position: relative;
            height: 200px;
            margin: 20px 0;
        }

        /* ===== FORM STYLES ===== */
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
        }

        .form-control, .form-select {
            border: 1px solid #ced4da;
            border-radius: 8px;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--ddu-blue);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }

        /* ===== RESPONSIVE UTILITIES ===== */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }
            
            .top-navbar {
                padding: 15px;
            }
            
            .attendance-card {
                padding: 20px;
            }
            
            .calendar-day {
                height: 60px;
                padding: 5px;
                font-size: 11px;
            }
            
            .progress-circle {
                width: 100px;
                height: 100px;
            }
            
            .progress-value {
                font-size: 20px;
            }
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            .sidebar,
            .mobile-menu-btn,
            .top-navbar-actions,
            .btn,
            .dataTables_length,
            .dataTables_filter,
            .dataTables_info,
            .dataTables_paginate {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .attendance-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }
            
            table {
                width: 100% !important;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Menu Button -->
    <button id="mobileMenuBtn" class="mobile-menu-btn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h4><i class="fas fa-user-graduate"></i> DDU Student</h4>
            <small>Attendance System</small>
        </div>
        
        <div class="sidebar-nav">
            <a href="dashboard.php" class="nav-link-custom">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="my_attendance.php" class="nav-link-custom active">
                <i class="fas fa-calendar-check"></i> My Attendance
            </a>
            <a href="my_courses.php" class="nav-link-custom">
                <i class="fas fa-book"></i> My Courses
            </a>
            <a href="schedule.php" class="nav-link-custom">
                <i class="fas fa-calendar-alt"></i> Schedule
            </a>
            <a href="request_leave.php" class="nav-link-custom">
                <i class="fas fa-calendar-times"></i> Request Leave
            </a>
            <a href="profile.php" class="nav-link-custom">
                <i class="fas fa-user"></i> Profile
            </a>
            
            <div class="user-info-card">
                <small>Logged in as:</small>
                <h6><?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?></h6>
                <small class="text-muted">Student | <?php echo htmlspecialchars($student['department_name'] ?? 'Not assigned'); ?></small>
                
                <a href="../logout.php" class="btn btn-danger btn-sm mt-3 w-100">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Top Navbar -->
        <div class="top-navbar">
            <div class="page-title">
                <h4><i class="fas fa-calendar-check me-2"></i> My Attendance</h4>
                <small>Track and view your attendance records</small>
            </div>
            
            <div class="top-navbar-actions">
                <!-- Print Button -->
                <button class="btn btn-outline-primary" onclick="printAttendance()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
                
                <!-- User Profile Dropdown -->
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <?php 
                        $profile_image_path = '../uploads/profiles/' . ($student['profile_image'] ?? '');
                        $default_avatar_url = 'https://ui-avatars.com/api/?name=' . urlencode($student['full_name'] ?? 'Student') . '&background=003366&color=fff&bold=true';
                        ?>
                        
                        <?php if (!empty($student['profile_image']) && file_exists($profile_image_path)): ?>
                            <img src="<?php echo $profile_image_path; ?>" 
                                 class="rounded-circle border border-2 border-primary" 
                                 width="40" 
                                 height="40"
                                 alt="Profile"
                                 onerror="this.src='<?php echo $default_avatar_url; ?>'">
                        <?php else: ?>
                            <img src="<?php echo $default_avatar_url; ?>" 
                                 class="rounded-circle border border-2 border-primary" 
                                 width="40" 
                                 height="40"
                                 alt="Profile">
                        <?php endif; ?>
                        
                        <div class="ms-2 d-none d-md-block">
                            <div class="fw-bold"><?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?></div>
                            <small class="text-muted">Student</small>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end shadow">
                        <a class="dropdown-item" href="profile.php">
                            <i class="fas fa-user me-2"></i> Profile
                        </a>
                        <a class="dropdown-item" href="settings.php">
                            <i class="fas fa-cog me-2"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger" href="../logout.php">
                            <i class="fas fa-sign-out-alt me-2"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Overview -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="attendance-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-3">Overall Attendance</h6>
                        <div class="progress-circle mb-3">
                            <svg width="120" height="120">
                                <?php
                                $circumference = 2 * M_PI * 54; // 2πr
                                $dashoffset = $circumference * (1 - ($stats['overall_percentage'] / 100));
                                ?>
                                <circle class="progress-ring-bg" cx="60" cy="60" r="54" />
                                <circle class="progress-ring" cx="60" cy="60" r="54" 
                                        stroke="<?php echo $stats['overall_percentage'] >= 75 ? '#28a745' : ($stats['overall_percentage'] >= 50 ? '#ffc107' : '#dc3545'); ?>"
                                        stroke-dasharray="<?php echo $circumference; ?>"
                                        stroke-dashoffset="<?php echo $dashoffset; ?>" />
                            </svg>
                            <div class="progress-value"><?php echo number_format($stats['overall_percentage'], 1); ?>%</div>
                        </div>
                        <small class="text-muted">Based on <?php echo $stats['total_sessions']; ?> sessions</small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="attendance-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-3">Present</h6>
                        <h2 class="display-4 text-success mb-2"><?php echo $stats['present_count'] ?? 0; ?></h2>
                        <small class="text-muted">Total present sessions</small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="attendance-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-3">Absent</h6>
                        <h2 class="display-4 text-danger mb-2"><?php echo $stats['absent_count'] ?? 0; ?></h2>
                        <small class="text-muted">Total absent sessions</small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="attendance-card">
                    <div class="card-body text-center">
                        <h6 class="text-muted mb-3">Courses</h6>
                        <h2 class="display-4 text-info mb-2"><?php echo $stats['total_courses'] ?? 0; ?></h2>
                        <small class="text-muted">Enrolled courses</small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Courses List -->
            <div class="col-lg-4">
                <div class="attendance-card">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h5 class="mb-0"><i class="fas fa-book me-2"></i> My Courses</h5>
                    </div>
                    <div class="card-body p-0 pt-3">
                        <?php if ($courses_result && $courses_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php 
                                $courses_result->data_seek(0);
                                while($course = $courses_result->fetch_assoc()): 
                                    // Get attendance percentage for this course
                                    $course_percentage_query = $conn->prepare("
                                        SELECT 
                                            COUNT(*) as total,
                                            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                                            ROUND(
                                                COALESCE(
                                                    (SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0)),
                                                    0
                                                ), 2
                                            ) as percentage
                                        FROM attendance
                                        WHERE student_id = ? AND course_id = ?
                                    ");
                                    $course_percentage_query->bind_param("ii", $student_id, $course['course_id']);
                                    $course_percentage_query->execute();
                                    $course_percentage_result = $course_percentage_query->get_result();
                                    $course_stats = $course_percentage_result->fetch_assoc();
                                    
                                    $percentage = $course_stats['percentage'] ?? 0;
                                    $status_class = $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger');
                                    $is_active = $selected_course_id == $course['course_id'];
                                ?>
                                <a href="my_attendance.php?course_id=<?php echo $course['course_id']; ?>" 
                                   class="course-list-item list-group-item-action border-0 rounded-0 <?php echo $is_active ? 'active' : ''; ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($course['course_code']); ?></h6>
                                            <small class="text-muted d-block mb-1"><?php echo htmlspecialchars($course['course_name']); ?></small>
                                            <small class="text-muted">
                                                <i class="fas fa-chalkboard-teacher me-1"></i>
                                                <?php echo htmlspecialchars($course['teacher_name'] ?? 'Not assigned'); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo number_format($percentage, 1); ?>%
                                            </span>
                                        </div>
                                    </div>
                                </a>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-book-open"></i>
                                <h6 class="mt-3">No Courses Found</h6>
                                <p class="text-muted small">You are not enrolled in any courses.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Attendance Calendar -->
                <div class="attendance-card mt-4">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i> This Month</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $month = date('n');
                        $year = date('Y');
                        $first_day = date('N', strtotime("$year-$month-01"));
                        $days_in_month = date('t', strtotime("$year-$month-01"));
                        
                        // Get attendance for this month
                        $month_attendance = [];
                        $attendance_month_query = $conn->prepare("
                            SELECT DATE(attendance_date) as date, status 
                            FROM attendance 
                            WHERE student_id = ? 
                            AND MONTH(attendance_date) = ? 
                            AND YEAR(attendance_date) = ?
                        ");
                        $attendance_month_query->bind_param("iii", $student_id, $month, $year);
                        $attendance_month_query->execute();
                        $attendance_month_result = $attendance_month_query->get_result();
                        
                        while($row = $attendance_month_result->fetch_assoc()) {
                            $month_attendance[$row['date']] = $row['status'];
                        }
                        ?>
                        
                        <div class="row mb-2">
                            <?php 
                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                            foreach($days as $day): 
                            ?>
                            <div class="col text-center p-1">
                                <small class="text-muted fw-bold"><?php echo substr($day, 0, 1); ?></small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="row">
                            <?php 
                            // Fill empty days before first day of month
                            for($i = 1; $i < $first_day; $i++): 
                            ?>
                            <div class="col calendar-day p-2 bg-light"></div>
                            <?php endfor; ?>
                            
                            <?php 
                            // Display days of month
                            for($day_counter = 1; $day_counter <= $days_in_month; $day_counter++): 
                                $date = sprintf("%04d-%02d-%02d", $year, $month, $day_counter);
                                $is_today = $date == date('Y-m-d');
                                $attendance_status = $month_attendance[$date] ?? null;
                                
                                $status_class = '';
                                if ($attendance_status == 'present') $status_class = 'dot-present';
                                elseif ($attendance_status == 'absent') $status_class = 'dot-absent';
                                elseif ($attendance_status == 'late') $status_class = 'dot-late';
                                elseif ($attendance_status == 'excused') $status_class = 'dot-excused';
                            ?>
                            <div class="col calendar-day p-2 <?php echo $is_today ? 'today' : ''; ?>">
                                <div class="day-number"><?php echo $day_counter; ?></div>
                                <?php if($attendance_status): ?>
                                <div class="mt-1">
                                    <span class="attendance-dot <?php echo $status_class; ?>" 
                                          title="<?php echo ucfirst($attendance_status); ?>"></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php 
                                // Start new row after 7 days
                                if(($day_counter + $first_day - 1) % 7 == 0 && $day_counter != $days_in_month) {
                                    echo '</div><div class="row">';
                                }
                            endfor; 
                            
                            // Fill empty days after last day of month
                            $total_cells = ceil(($days_in_month + $first_day - 1) / 7) * 7;
                            $remaining_cells = $total_cells - ($days_in_month + $first_day - 1);
                            for($i = 0; $i < $remaining_cells; $i++):
                            ?>
                            <div class="col calendar-day p-2 bg-light"></div>
                            <?php endfor; ?>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted d-flex align-items-center">
                                <span class="attendance-dot dot-present me-2"></span> Present
                                <span class="attendance-dot dot-absent mx-3 me-2"></span> Absent
                                <span class="attendance-dot dot-late mx-3 me-2"></span> Late
                                <span class="attendance-dot dot-excused mx-3 me-2"></span> Excused
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Attendance Details -->
            <div class="col-lg-8">
                <?php if($selected_course_id > 0): ?>
                <div class="attendance-card">
                    <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-clipboard-list me-2"></i> 
                            Attendance Details - 
                            <?php echo htmlspecialchars($selected_course_name); ?>
                        </h5>
                        <div>
                            <?php if (isset($course_stats) && $course_stats): ?>
                            <span class="badge bg-primary me-2">
                                <?php echo number_format($course_stats['attendance_percentage'] ?? 0, 1); ?>%
                            </span>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-primary" onclick="printAttendance()">
                                <i class="fas fa-print me-1"></i> Print
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($attendance_details && $attendance_details->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="attendanceTable">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Session</th>
                                            <th>Status</th>
                                            <th>Time</th>
                                            <th>Marked By</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($record = $attendance_details->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($record['attendance_date'])); ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('D', strtotime($record['attendance_date'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?php echo ucfirst($record['session_type'] ?? 'Regular'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $status_class = [
                                                    'present' => 'badge-present',
                                                    'absent' => 'badge-absent',
                                                    'late' => 'badge-late',
                                                    'excused' => 'badge-excused'
                                                ][$record['status']] ?? 'badge-secondary';
                                                ?>
                                                <span class="attendance-badge <?php echo $status_class; ?>">
                                                    <?php echo ucfirst($record['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if($record['check_in_time']): ?>
                                                <?php echo date('h:i A', strtotime($record['check_in_time'])); ?>
                                                <?php if($record['check_out_time']): ?>
                                                <br><small>to <?php echo date('h:i A', strtotime($record['check_out_time'])); ?></small>
                                                <?php endif; ?>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['marked_by'] ?? 'System'); ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($record['notes'] ?? 'No notes'); ?>
                                                </small>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Attendance Summary Chart -->
                            <?php if (isset($course_stats)): ?>
                            <div class="mt-4">
                                <h6 class="mb-3">Attendance Distribution</h6>
                                <div class="chart-container">
                                    <canvas id="attendanceChart"></canvas>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-clipboard-list"></i>
                                <h5 class="mt-3">No Attendance Records</h5>
                                <p class="text-muted">No attendance records found for this course.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="attendance-card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-clipboard-list fa-4x text-muted mb-3"></i>
                        <h4>Select a Course</h4>
                        <p class="text-muted">Choose a course from the list to view attendance details</p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Leave Request Form -->
                <div class="attendance-card mt-4">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h5 class="mb-0"><i class="fas fa-calendar-times me-2"></i> Request Leave</h5>
                    </div>
                    <div class="card-body">
                        <form id="leaveRequestForm" action="submit_leave.php" method="POST">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Course</label>
                                    <select class="form-select" name="course_id" required>
                                        <option value="">Select course...</option>
                                        <?php 
                                        if ($courses_result) {
                                            $courses_result->data_seek(0);
                                            while($course = $courses_result->fetch_assoc()): 
                                        ?>
                                        <option value="<?php echo $course['course_id']; ?>">
                                            <?php echo htmlspecialchars($course['course_code']); ?>
                                        </option>
                                        <?php 
                                            endwhile;
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Leave Date</label>
                                    <input type="date" class="form-control" name="leave_date" required 
                                           min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Session Type</label>
                                    <select class="form-select" name="session_type" required>
                                        <option value="lecture">Lecture</option>
                                        <option value="lab">Lab</option>
                                        <option value="tutorial">Tutorial</option>
                                        <option value="all">All Day</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Reason</label>
                                <textarea class="form-control" name="reason" rows="3" required 
                                          placeholder="Please specify the reason for leave..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Supporting Document (Optional)</label>
                                <input type="file" class="form-control" name="support_doc" accept=".pdf,.jpg,.png,.jpeg">
                                <small class="text-muted">Max file size: 5MB. Allowed: PDF, JPG, PNG</small>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2"></i> Submit Request
                            </button>
                            <button type="reset" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-redo me-2"></i> Reset
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // ======================================================
        // INITIALIZATION
        // ======================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize DataTable if table exists
            if ($('#attendanceTable').length) {
                $('#attendanceTable').DataTable({
                    pageLength: 10,
                    order: [[0, 'desc']],
                    language: {
                        search: "Search records:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ entries",
                        paginate: {
                            first: "First",
                            last: "Last",
                            next: "Next",
                            previous: "Previous"
                        }
                    }
                });
            }
            
            // Initialize attendance chart if needed
            initializeAttendanceChart();
            
            // Setup mobile sidebar toggle
            setupMobileMenu();
            
            // Setup form validation
            setupLeaveForm();
            
            // Auto-refresh page every 2 minutes for updates
            setupAutoRefresh();
        });
        
        // ======================================================
        // MOBILE SIDEBAR TOGGLE
        // ======================================================
        function setupMobileMenu() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.getElementById('sidebar');
            
            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    sidebar.classList.toggle('mobile-open');
                });
                
                // Close sidebar when clicking outside on mobile
                document.addEventListener('click', function(event) {
                    if (window.innerWidth <= 992 && 
                        !sidebar.contains(event.target) && 
                        !mobileMenuBtn.contains(event.target) && 
                        sidebar.classList.contains('mobile-open')) {
                        sidebar.classList.remove('mobile-open');
                    }
                });
            }
        }
        
        // ======================================================
        // ATTENDANCE CHART
        // ======================================================
        function initializeAttendanceChart() {
            const ctx = document.getElementById('attendanceChart');
            
            if (!ctx) return;
            
            <?php if (isset($course_stats) && $course_stats): ?>
                const present = <?php echo $course_stats['present_count'] ?? 0; ?>;
                const absent = <?php echo $course_stats['absent_count'] ?? 0; ?>;
                const late = <?php echo $course_stats['late_count'] ?? 0; ?>;
                const excused = <?php echo $course_stats['excused_count'] ?? 0; ?>;
                
                // Only show chart if there's data
                if (present > 0 || absent > 0 || late > 0 || excused > 0) {
                    new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Present', 'Absent', 'Late', 'Excused'],
                            datasets: [{
                                data: [present, absent, late, excused],
                                backgroundColor: [
                                    '#28a745',
                                    '#dc3545',
                                    '#ffc107',
                                    '#17a2b8'
                                ],
                                borderColor: 'white',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.raw || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((value / total) * 100);
                                            return `${label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            cutout: '70%'
                        }
                    });
                } else {
                    ctx.parentElement.innerHTML = `
                        <div class="text-center py-3">
                            <i class="fas fa-chart-pie text-muted fa-2x mb-2"></i>
                            <p class="text-muted mb-0">No attendance data available for chart</p>
                        </div>
                    `;
                }
            <?php endif; ?>
        }
        
        // ======================================================
        // PRINT FUNCTIONALITY
        // ======================================================
        function printAttendance() {
            // Store original content
            const originalContent = document.body.innerHTML;
            
            // Get printable content
            const printContent = document.querySelector('.main-content').innerHTML;
            
            // Create print window
            document.body.innerHTML = `
                <div style="padding: 20px;">
                    <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #003366; padding-bottom: 10px;">
                        <h2 style="color: #003366; margin: 0;">DDU Attendance System</h2>
                        <h3 style="color: #666; margin: 10px 0 0;">Attendance Report</h3>
                        <p style="color: #999; margin: 5px 0;">
                            Student: <?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?> | 
                            Date: ${new Date().toLocaleDateString()}
                        </p>
                    </div>
                    ${printContent}
                </div>
            `;
            
            // Print
            window.print();
            
            // Restore original content
            document.body.innerHTML = originalContent;
            
            // Reinitialize DataTable and other scripts
            if ($('#attendanceTable').length) {
                $('#attendanceTable').DataTable({
                    pageLength: 10,
                    order: [[0, 'desc']]
                });
            }
        }
        
        // ======================================================
        // LEAVE FORM HANDLING
        // ======================================================
        function setupLeaveForm() {
            const form = document.getElementById('leaveRequestForm');
            
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    // Basic validation
                    const courseId = form.querySelector('[name="course_id"]').value;
                    const leaveDate = form.querySelector('[name="leave_date"]').value;
                    const reason = form.querySelector('[name="reason"]').value;
                    
                    if (!courseId || !leaveDate || !reason) {
                        alert('Please fill in all required fields.');
                        return;
                    }
                    
                    // Check if date is not in the past
                    const selectedDate = new Date(leaveDate);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    if (selectedDate < today) {
                        alert('Leave date cannot be in the past.');
                        return;
                    }
                    
                    // Show loading indicator
                    const submitBtn = form.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Submitting...';
                    submitBtn.disabled = true;
                    
                    // Submit form (in real implementation, this would be AJAX)
                    setTimeout(() => {
                        alert('Leave request submitted successfully!');
                        form.reset();
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 1500);
                });
            }
        }
        
        // ======================================================
        // AUTO REFRESH
        // ======================================================
        function setupAutoRefresh() {
            // Auto-refresh page every 2 minutes (120000 ms)
            setInterval(() => {
                // Only refresh if not interacting with form or DataTable search
                if (!document.querySelector('input:focus, textarea:focus, select:focus')) {
                    location.reload();
                }
            }, 120000);
        }
        
        // ======================================================
        // ERROR HANDLING
        // ======================================================
        window.onerror = function(msg, url, lineNo, columnNo, error) {
            console.error('JavaScript Error:', {
                message: msg,
                url: url,
                line: lineNo,
                column: columnNo,
                error: error
            });
            
            // Show user-friendly error in development
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'alert alert-danger position-fixed top-0 end-0 m-3';
                errorDiv.style.zIndex = '9999';
                errorDiv.style.maxWidth = '300px';
                errorDiv.innerHTML = `
                    <button type="button" class="btn-close float-end" onclick="this.parentElement.remove()"></button>
                    <strong>Script Error:</strong><br>
                    <small>${msg}</small>
                `;
                document.body.appendChild(errorDiv);
                
                setTimeout(() => errorDiv.remove(), 10000);
            }
            
            return false;
        };
    </script>
</body>
</html>