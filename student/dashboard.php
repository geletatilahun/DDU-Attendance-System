<?php
/**
 * STUDENT DASHBOARD - CORRECTED VERSION
 * DDU Attendance System
 * Fixed and Enhanced Version
 */

// Error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Start session first
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Include database connection
if (!file_exists('../includes/db_connect.php')) {
    die("System configuration error. Please contact administrator.");
}
require_once('../includes/db_connect.php');

// Include authentication
if (!file_exists('../includes/auth.php')) {
    die("Authentication system error. Please contact administrator.");
}
require_once('../includes/auth.php');

// Check if user has student role
checkRole(['student']);

$student_id = (int) $_SESSION['user_id'];

// ======================================================
// FETCH STUDENT INFORMATION - CORRECTED
// ======================================================

// First, let's check if database connection is working
if (!isset($conn) || !$conn) {
    die("Database connection failed. Please contact administrator.");
}

// Fetch student information
$student_sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        u.phone,
        u.profile_image,
        u.department_id,
        COALESCE(d.department_name, 'Not assigned') as department_name,
        COALESCE(d.department_code, 'N/A') as department_code
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.user_id = ? AND u.role = 'student'
    LIMIT 1
";

$student_stmt = $conn->prepare($student_sql);
if (!$student_stmt) {
    die("Database query error. Please try again later.");
}

$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student_result = $student_stmt->get_result();

if (!$student_result || $student_result->num_rows === 0) {
    die("Student not found in database.");
}

$student = $student_result->fetch_assoc();

// Set default values for optional fields
$student['semester'] = 'Not assigned';
$student['academic_year'] = 'N/A';
$student['enrollment_date'] = 'N/A';

// ======================================================
// FETCH ENROLLED COURSES
// ======================================================
$courses_sql = "
    SELECT 
        c.course_id,
        c.course_code,
        c.course_name,
        c.credits,
        u.full_name as teacher_name
    FROM courses c
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE c.course_id IN (
        SELECT course_id FROM course_students WHERE student_id = ?
    )
    AND c.is_active = 1
    ORDER BY c.course_code
";

$courses_stmt = $conn->prepare($courses_sql);
$courses_result = null;
$total_courses = 0;

if ($courses_stmt) {
    $courses_stmt->bind_param("i", $student_id);
    $courses_stmt->execute();
    $courses_result = $courses_stmt->get_result();
    $total_courses = $courses_result ? $courses_result->num_rows : 0;
}

// ======================================================
// FETCH TODAY'S CLASSES
// ======================================================
$today_day = date('l'); // e.g., Monday, Tuesday
$today_date = date('Y-m-d');
$today_classes_result = null;

// Check if class_schedule table exists
$check_schedule = $conn->query("SHOW TABLES LIKE 'class_schedule'");
if ($check_schedule && $check_schedule->num_rows > 0) {
    $today_classes_sql = "
        SELECT 
            cs.schedule_id,
            cs.course_id,
            cs.day_of_week,
            cs.start_time,
            cs.end_time,
            cs.room_number,
            c.course_code,
            c.course_name,
            u.full_name as teacher_name
        FROM class_schedule cs
        JOIN courses c ON cs.course_id = c.course_id
        LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
        LEFT JOIN users u ON ct.teacher_id = u.user_id
        WHERE cs.course_id IN (
            SELECT course_id FROM course_students WHERE student_id = ?
        )
        AND cs.day_of_week = ?
        AND cs.is_active = 1
        ORDER BY cs.start_time
    ";
    
    $today_classes_stmt = $conn->prepare($today_classes_sql);
    if ($today_classes_stmt) {
        $today_classes_stmt->bind_param("is", $student_id, $today_day);
        $today_classes_stmt->execute();
        $today_classes_result = $today_classes_stmt->get_result();
    }
}

// ======================================================
// FETCH ATTENDANCE SUMMARY
// ======================================================
$attendance_summary_sql = "
    SELECT 
        c.course_id,
        c.course_code,
        c.course_name,
        COUNT(*) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
        ROUND(
            COALESCE(
                (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0)),
                0
            ), 2
        ) as percentage
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
    GROUP BY c.course_id, c.course_code, c.course_name
    ORDER BY c.course_code
";

$attendance_stmt = $conn->prepare($attendance_summary_sql);
$attendance_result = null;
$total_classes = 0;
$total_present = 0;
$total_absent = 0;
$overall_percentage = 0;

if ($attendance_stmt) {
    $attendance_stmt->bind_param("i", $student_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
    
    if ($attendance_result && $attendance_result->num_rows > 0) {
        while ($att = $attendance_result->fetch_assoc()) {
            $total_classes += $att['total_classes'];
            $total_present += $att['present_count'];
            $total_absent += $att['absent_count'];
        }
        
        if ($total_classes > 0) {
            $overall_percentage = round(($total_present / $total_classes) * 100, 2);
        }
        
        // Reset pointer for later use
        $attendance_result->data_seek(0);
    }
}

// ======================================================
// FETCH RECENT ATTENDANCE (Last 10 records)
// ======================================================
$recent_attendance_sql = "
    SELECT 
        a.attendance_id,
        a.status,
        a.attendance_date,
        a.check_in_time,
        c.course_code,
        c.course_name,
        u.full_name as teacher_name
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE a.student_id = ?
    ORDER BY a.attendance_date DESC, a.check_in_time DESC
    LIMIT 10
";

$recent_stmt = $conn->prepare($recent_attendance_sql);
$recent_result = null;

if ($recent_stmt) {
    $recent_stmt->bind_param("i", $student_id);
    $recent_stmt->execute();
    $recent_result = $recent_stmt->get_result();
}

// ======================================================
// FETCH UPCOMING ASSIGNMENTS/EXAMS
// ======================================================
$upcoming_sql = "
    SELECT 
        a.assignment_id,
        a.title,
        a.description,
        a.due_date,
        c.course_code,
        c.course_name
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.course_id IN (
        SELECT course_id FROM course_students WHERE student_id = ?
    )
    AND a.due_date >= CURDATE()
    AND a.is_active = 1
    ORDER BY a.due_date
    LIMIT 5
";

$upcoming_stmt = $conn->prepare($upcoming_sql);
$upcoming_result = null;

if ($upcoming_stmt) {
    $upcoming_stmt->bind_param("i", $student_id);
    $upcoming_stmt->execute();
    $upcoming_result = $upcoming_stmt->get_result();
}

// ======================================================
// FETCH NOTICES/ANNOUNCEMENTS
// ======================================================
$notices_sql = "
    SELECT 
        n.notice_id,
        n.title,
        n.content,
        n.priority,
        n.created_at,
        c.course_code,
        u.full_name as posted_by
    FROM notices n
    LEFT JOIN courses c ON n.course_id = c.course_id
    LEFT JOIN users u ON n.posted_by = u.user_id
    WHERE (
        n.course_id IN (SELECT course_id FROM course_students WHERE student_id = ?) 
        OR n.course_id IS NULL
    )
    AND n.is_active = 1
    AND (n.expiry_date IS NULL OR n.expiry_date >= CURDATE())
    ORDER BY n.created_at DESC
    LIMIT 5
";

$notices_stmt = $conn->prepare($notices_sql);
$notices_result = null;

if ($notices_stmt) {
    $notices_stmt->bind_param("i", $student_id);
    $notices_stmt->execute();
    $notices_result = $notices_stmt->get_result();
}

// ======================================================
// HTML PAGE STARTS HERE
// ======================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - DDU Attendance System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-light-blue: #4A90E2;
            --ddu-green: #28a745;
            --ddu-red: #dc3545;
            --ddu-purple: #6f42c1;
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

        /* ===== PROFILE CARD ===== */
        .profile-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 8px 30px rgba(0, 51, 102, 0.1);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .student-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid var(--ddu-blue);
            object-fit: cover;
            box-shadow: 0 4px 15px rgba(0, 51, 102, 0.2);
        }

        .profile-detail-item {
            margin-bottom: 15px;
        }

        .profile-detail-item .label {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .profile-detail-item .value {
            color: #333;
            font-weight: 500;
        }

        /* ===== STATISTICS CARDS ===== */
        .stat-card {
            border: none;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            height: 100%;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }

        .stat-card.bg-attendance {
            background: linear-gradient(135deg, #003366, #4A90E2);
        }
        .stat-card.bg-attendance::before {
            background: rgba(255, 255, 255, 0.5);
        }

        .stat-card.bg-courses {
            background: linear-gradient(135deg, #6f42c1, #9c71e8);
        }
        .stat-card.bg-courses::before {
            background: rgba(255, 255, 255, 0.5);
        }

        .stat-card.bg-present {
            background: linear-gradient(135deg, #28a745, #5cd85c);
        }
        .stat-card.bg-present::before {
            background: rgba(255, 255, 255, 0.5);
        }

        .stat-card.bg-percentage {
            background: linear-gradient(135deg, #17a2b8, #5bc0de);
        }
        .stat-card.bg-percentage::before {
            background: rgba(255, 255, 255, 0.5);
        }

        .stat-icon {
            font-size: 40px;
            opacity: 0.8;
            color: white;
        }

        .attendance-progress {
            height: 6px;
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
            margin-top: 15px;
            overflow: hidden;
        }

        .attendance-progress .progress-bar {
            border-radius: 3px;
        }

        /* ===== CONTENT CARDS ===== */
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            height: 100%;
        }

        .content-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--ddu-gray);
        }

        .content-card-header h5 {
            color: var(--ddu-blue);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ===== TODAY'S CLASSES ===== */
        .class-card {
            background: linear-gradient(135deg, rgba(74, 144, 226, 0.1), rgba(74, 144, 226, 0.05));
            border-left: 4px solid var(--ddu-light-blue);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .class-card:hover {
            background: linear-gradient(135deg, rgba(74, 144, 226, 0.15), rgba(74, 144, 226, 0.1));
            transform: translateX(3px);
        }

        .class-card.current {
            border-left-color: var(--ddu-green);
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }

        /* ===== ATTENDANCE BADGES ===== */
        .attendance-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: 600;
            color: white;
        }

        .attendance-badge.high {
            background: linear-gradient(135deg, var(--ddu-green), #5cd85c);
        }

        .attendance-badge.medium {
            background: linear-gradient(135deg, var(--ddu-gold), #ffed4e);
            color: #333;
        }

        .attendance-badge.low {
            background: linear-gradient(135deg, var(--ddu-red), #ff6b6b);
        }

        /* ===== TABLES ===== */
        .table-container {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .custom-table {
            margin-bottom: 0;
            width: 100%;
        }

        .custom-table thead th {
            background: var(--ddu-blue);
            color: white;
            border: none;
            padding: 15px;
            font-weight: 600;
        }

        .custom-table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-color: #eee;
        }

        .custom-table tbody tr:hover {
            background-color: rgba(0, 51, 102, 0.03);
        }

        /* ===== ASSIGNMENT CARDS ===== */
        .assignment-card {
            background: white;
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .assignment-card:hover {
            border-color: var(--ddu-light-blue);
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1);
        }

        .assignment-card.urgent {
            border-left: 4px solid var(--ddu-red);
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-3px); }
            75% { transform: translateX(3px); }
        }

        /* ===== NOTICE CARDS ===== */
        .notice-card {
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.1), rgba(255, 215, 0, 0.05));
            border-left: 4px solid var(--ddu-gold);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .notice-card:hover {
            transform: translateX(3px);
            background: linear-gradient(135deg, rgba(255, 215, 0, 0.15), rgba(255, 215, 0, 0.1));
        }

        .notice-card.important {
            border-left-color: var(--ddu-red);
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
        }

        /* ===== QUICK ACTIONS ===== */
        .quick-actions {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .quick-action-btn {
            height: 100px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-radius: 15px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            width: 100%;
        }

        .quick-action-btn i {
            font-size: 24px;
        }

        .quick-action-btn:hover {
            transform: translateY(-5px);
        }

        /* ===== EMPTY STATES ===== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
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
            height: 300px;
            margin-bottom: 20px;
        }

        /* ===== LOADING OVERLAY ===== */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--ddu-blue);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ===== RESPONSIVE UTILITIES ===== */
        @media (max-width: 768px) {
            .profile-card {
                padding: 20px;
            }
            
            .student-avatar {
                width: 100px;
                height: 100px;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .content-card {
                padding: 20px;
            }
            
            .main-content {
                padding: 15px;
            }
            
            .top-navbar {
                padding: 15px;
            }
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            .sidebar,
            .mobile-menu-btn,
            .top-navbar-actions,
            .quick-actions,
            .btn {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .content-card,
            .profile-card,
            .stat-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="spinner"></div>
    </div>

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
            <a href="dashboard.php" class="nav-link-custom active">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="my_attendance.php" class="nav-link-custom">
                <i class="fas fa-calendar-check"></i> My Attendance
            </a>
            <a href="courses.php" class="nav-link-custom">
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
                <h4><i class="fas fa-tachometer-alt me-2"></i> Student Dashboard</h4>
                <small>Welcome back, <?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?>! Today is <?php echo date('l, F j, Y'); ?></small>
            </div>
            
            <div class="top-navbar-actions">
                <!-- Notifications -->
                <div class="dropdown">
                    <a href="#" class="position-relative text-decoration-none" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-5" style="color: var(--ddu-blue);"></i>
                        <?php if ($notices_result && $notices_result->num_rows > 0): ?>
                            <span class="notification-badge"><?php echo $notices_result->num_rows; ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end p-0" style="width: 350px;">
                        <div class="card border-0">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0">Recent Notices</h6>
                            </div>
                            <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                                <?php if ($notices_result && $notices_result->num_rows > 0): ?>
                                    <?php 
                                    $notices_result->data_seek(0);
                                    while ($notice = $notices_result->fetch_assoc()): 
                                    ?>
                                        <a class="dropdown-item border-bottom p-3" href="#">
                                            <div class="d-flex w-100 justify-content-between">
                                                <small class="fw-bold"><?php echo htmlspecialchars($notice['posted_by'] ?? 'System'); ?></small>
                                                <small class="text-muted"><?php echo date('M d, Y', strtotime($notice['created_at'])); ?></small>
                                            </div>
                                            <p class="mb-1 small"><?php echo htmlspecialchars(substr($notice['title'] ?? '', 0, 50)); ?></p>
                                            <small class="text-muted"><?php echo htmlspecialchars($notice['course_code'] ?? 'General'); ?></small>
                                        </a>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-bell-slash text-muted fa-2x mb-2"></i>
                                        <p class="text-muted mb-0">No new notices</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer text-center">
                                <a class="btn btn-sm btn-outline-primary w-100" href="notices.php">View All Notices</a>
                            </div>
                        </div>
                    </div>
                </div>
                
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
                            <small class="text-muted">Student ID: <?php echo htmlspecialchars($student['user_id'] ?? 'N/A'); ?></small>
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

        <!-- Student Profile Card -->
        <div class="profile-card">
            <div class="row align-items-center">
                <div class="col-md-3 col-lg-2 text-center">
                    <?php if (!empty($student['profile_image']) && file_exists($profile_image_path)): ?>
                        <img src="<?php echo $profile_image_path; ?>" 
                             class="student-avatar mb-3"
                             alt="Profile"
                             onerror="this.src='<?php echo $default_avatar_url . '&size=150'; ?>'">
                    <?php else: ?>
                        <img src="<?php echo $default_avatar_url . '&size=150'; ?>" 
                             class="student-avatar mb-3"
                             alt="Profile">
                    <?php endif; ?>
                    
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="location.href='profile.php'">
                        <i class="fas fa-edit me-1"></i> Edit Profile
                    </button>
                </div>
                
                <div class="col-md-9 col-lg-10">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="mb-1"><?php echo htmlspecialchars($student['full_name'] ?? 'Student'); ?></h2>
                            <p class="text-muted mb-3">Welcome to your personalized dashboard</p>
                        </div>
                        <span class="badge bg-primary fs-6">Student</span>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-primary rounded-circle p-2 me-3">
                                    <i class="fas fa-id-card text-white"></i>
                                </div>
                                <div>
                                    <small class="text-muted d-block">Student ID</small>
                                    <strong><?php echo htmlspecialchars($student['user_id'] ?? 'N/A'); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-success rounded-circle p-2 me-3">
                                    <i class="fas fa-building text-white"></i>
                                </div>
                                <div>
                                    <small class="text-muted d-block">Department</small>
                                    <strong><?php echo htmlspecialchars($student['department_name'] ?? 'Not assigned'); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="d-flex align-items-center">
                                <div class="bg-info rounded-circle p-2 me-3">
                                    <i class="fas fa-envelope text-white"></i>
                                </div>
                                <div>
                                    <small class="text-muted d-block">Email</small>
                                    <strong><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-attendance" onclick="location.href='my_attendance.php'">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo number_format($overall_percentage, 1); ?>%</h2>
                                <p class="mb-0">Overall Attendance</p>
                                <small class="opacity-75"><?php echo $total_present; ?> present of <?php echo $total_classes; ?> classes</small>
                            </div>
                            <i class="fas fa-chart-line stat-icon"></i>
                        </div>
                        <div class="progress attendance-progress mt-3">
                            <div class="progress-bar bg-white" style="width: <?php echo min($overall_percentage, 100); ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-courses" onclick="location.href='my_courses.php'">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $total_courses; ?></h2>
                                <p class="mb-0">Enrolled Courses</p>
                                <small class="opacity-75">Currently active</small>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-present" onclick="location.href='my_attendance.php?filter=present'">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $total_present; ?></h2>
                                <p class="mb-0">Present Days</p>
                                <small class="opacity-75">Total attended classes</small>
                            </div>
                            <i class="fas fa-calendar-check stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-percentage text-white" onclick="location.href='my_attendance.php'">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?php echo $total_classes; ?></h2>
                                <p class="mb-0">Total Classes</p>
                                <small class="opacity-75">All scheduled classes</small>
                            </div>
                            <i class="fas fa-chalkboard-teacher stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Classes & Course-wise Attendance -->
        <div class="row">
            <!-- Today's Classes -->
            <div class="col-lg-6">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5><i class="fas fa-calendar-day me-2"></i> Today's Classes</h5>
                        <?php if ($today_classes_result && $today_classes_result->num_rows > 0): ?>
                            <span class="badge bg-primary"><?php echo $today_classes_result->num_rows; ?> classes</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($today_classes_result && $today_classes_result->num_rows > 0): ?>
                        <?php 
                        $current_time = date('H:i:s');
                        while ($class = $today_classes_result->fetch_assoc()): 
                            $is_current = ($current_time >= $class['start_time'] && $current_time <= $class['end_time']);
                            $is_upcoming = (strtotime($class['start_time']) > strtotime($current_time));
                        ?>
                            <div class="class-card <?php echo $is_current ? 'current' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="bg-primary rounded-circle p-2 me-3">
                                                <i class="fas fa-book text-white"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($class['course_name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($class['course_code']); ?></small>
                                            </div>
                                        </div>
                                        
                                        <div class="row mt-2">
                                            <div class="col-md-6">
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo date('h:i A', strtotime($class['start_time'])); ?> - 
                                                    <?php echo date('h:i A', strtotime($class['end_time'])); ?>
                                                </small>
                                            </div>
                                            <div class="col-md-6">
                                                <small class="text-muted">
                                                    <i class="fas fa-chalkboard-teacher me-1"></i>
                                                    <?php echo htmlspecialchars($class['teacher_name'] ?? 'Not assigned'); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-end ms-3">
                                        <?php if ($is_current): ?>
                                            <span class="badge bg-warning px-3 py-2">
                                                <i class="fas fa-play me-1"></i> Ongoing
                                            </span>
                                        <?php elseif ($is_upcoming): ?>
                                            <?php 
                                            $time_left = strtotime($class['start_time']) - strtotime($current_time);
                                            $hours_left = floor($time_left / 3600);
                                            $minutes_left = floor(($time_left % 3600) / 60);
                                            ?>
                                            <div class="text-center">
                                                <small class="text-muted d-block">Starts in</small>
                                                <span class="badge bg-info px-3 py-2">
                                                    <?php echo $hours_left; ?>h <?php echo $minutes_left; ?>m
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary px-3 py-2">
                                                <i class="fas fa-check me-1"></i> Completed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-coffee"></i>
                            <h5 class="mt-3">No Classes Today</h5>
                            <p class="text-muted">Enjoy your free day! Take time to study or relax.</p>
                            <button class="btn btn-outline-primary mt-2" onclick="location.href='schedule.php'">
                                <i class="fas fa-calendar-alt me-2"></i>View Weekly Schedule
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Course-wise Attendance -->
            <div class="col-lg-6">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5><i class="fas fa-chart-bar me-2"></i> Course-wise Attendance</h5>
                        <?php if ($attendance_result && $attendance_result->num_rows > 0): ?>
                            <div>
                                <span class="badge bg-primary"><?php echo $attendance_result->num_rows; ?> courses</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($attendance_result && $attendance_result->num_rows > 0): ?>
                        <div class="chart-container">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                        
                        <div class="mt-4">
                            <?php 
                            $attendance_result->data_seek(0);
                            while ($att = $attendance_result->fetch_assoc()): 
                                $att_class = $att['percentage'] >= 75 ? 'high' : ($att['percentage'] >= 50 ? 'medium' : 'low');
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <div>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($att['course_code']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($att['course_name']); ?></small>
                                        </div>
                                        <span class="attendance-badge <?php echo $att_class; ?>">
                                            <?php echo number_format($att['percentage'], 1); ?>%
                                        </span>
                                    </div>
                                    <div class="progress attendance-progress" style="height: 8px;">
                                        <div class="progress-bar 
                                            <?php echo $att['percentage'] >= 75 ? 'bg-success' : 
                                                  ($att['percentage'] >= 50 ? 'bg-warning' : 'bg-danger'); ?>" 
                                             style="width: <?php echo min($att['percentage'], 100); ?>%">
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between mt-1">
                                        <small class="text-muted">
                                            <span class="text-success">P:<?php echo $att['present_count']; ?></span> | 
                                            <span class="text-danger">A:<?php echo $att['absent_count']; ?></span> | 
                                            <span class="text-warning">L:<?php echo $att['late_count']; ?></span> | 
                                            <span class="text-info">E:<?php echo $att['excused_count']; ?></span>
                                        </small>
                                        <small class="text-muted">Total: <?php echo $att['total_classes']; ?></small>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                        
                        <div class="text-center mt-4">
                            <a href="my_attendance.php" class="btn btn-primary">
                                <i class="fas fa-eye me-2"></i> View Detailed Attendance
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar"></i>
                            <h5 class="mt-3">No Attendance Data</h5>
                            <p class="text-muted">Attendance records will appear here once you attend classes.</p>
                            <button class="btn btn-outline-primary mt-2" onclick="location.href='schedule.php'">
                                <i class="fas fa-calendar me-2"></i>Check Schedule
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Attendance & Upcoming -->
        <div class="row mt-4">
            <!-- Recent Attendance -->
            <div class="col-lg-7">
                <div class="content-card">
                    <div class="content-card-header">
                        <h5><i class="fas fa-history me-2"></i> Recent Attendance</h5>
                        <a href="my_attendance.php" class="btn btn-sm btn-outline-primary">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    
                    <?php if ($recent_result && $recent_result->num_rows > 0): ?>
                        <div class="table-container">
                            <table class="custom-table table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Course</th>
                                        <th>Status</th>
                                        <th>Time</th>
                                        <th>Teacher</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($record = $recent_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <small class="fw-bold"><?php echo date('M d, Y', strtotime($record['attendance_date'])); ?></small>
                                            </td>
                                            <td>
                                                <div>
                                                    <small class="fw-bold"><?php echo htmlspecialchars($record['course_code']); ?></small><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($record['course_name']); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <?php 
                                                $badge_class = 'bg-secondary';
                                                $icon = 'fa-question';
                                                if ($record['status'] == 'present') {
                                                    $badge_class = 'bg-success';
                                                    $icon = 'fa-check';
                                                } elseif ($record['status'] == 'absent') {
                                                    $badge_class = 'bg-danger';
                                                    $icon = 'fa-times';
                                                } elseif ($record['status'] == 'late') {
                                                    $badge_class = 'bg-warning';
                                                    $icon = 'fa-clock';
                                                } elseif ($record['status'] == 'excused') {
                                                    $badge_class = 'bg-info';
                                                    $icon = 'fa-flag';
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?> px-3 py-2">
                                                    <i class="fas <?php echo $icon; ?> me-1"></i>
                                                    <?php echo ucfirst($record['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($record['check_in_time']): ?>
                                                    <span class="badge bg-light text-dark">
                                                        <?php echo date('h:i A', strtotime($record['check_in_time'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($record['teacher_name'] ?? 'N/A'); ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-times"></i>
                            <h5 class="mt-3">No Recent Attendance</h5>
                            <p class="text-muted">Your attendance records will appear here after attending classes.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upcoming & Notices -->
            <div class="col-lg-5">
                <!-- Upcoming Assignments/Exams -->
                <div class="content-card mb-4">
                    <div class="content-card-header">
                        <h5><i class="fas fa-tasks me-2"></i> Upcoming Tasks</h5>
                        <?php if ($upcoming_result && $upcoming_result->num_rows > 0): ?>
                            <span class="badge bg-primary"><?php echo $upcoming_result->num_rows; ?> pending</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($upcoming_result && $upcoming_result->num_rows > 0): ?>
                        <?php while ($item = $upcoming_result->fetch_assoc()): 
                            $days_left = floor((strtotime($item['due_date']) - time()) / (60 * 60 * 24));
                            $is_urgent = $days_left <= 3;
                        ?>
                            <div class="assignment-card <?php echo $is_urgent ? 'urgent' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="bg-warning rounded-circle p-2 me-3">
                                                <i class="fas fa-clipboard-list text-white"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($item['title']); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($item['course_code']); ?> • 
                                                    <?php echo htmlspecialchars($item['course_name']); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="far fa-calendar me-1"></i> 
                                                Due: <?php echo date('M d, Y', strtotime($item['due_date'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="text-end ms-3">
                                        <?php if ($is_urgent): ?>
                                            <span class="badge bg-danger px-3 py-2">
                                                <i class="fas fa-exclamation-triangle me-1"></i> Urgent
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-info px-3 py-2">
                                                <?php echo $days_left; ?> days left
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        
                        <div class="text-center mt-3">
                            <a href="assignments.php" class="btn btn-sm btn-outline-primary">
                                View All Assignments
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-calendar-check"></i>
                            <h6 class="mt-3">No Upcoming Tasks</h6>
                            <p class="text-muted small">You're all caught up! Great work!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Notices -->
                <div class="content-card">
                    <div class="content-card-header">
                        <h5><i class="fas fa-bullhorn me-2"></i> Latest Notices</h5>
                        <?php if ($notices_result && $notices_result->num_rows > 0): ?>
                            <span class="badge bg-primary"><?php echo $notices_result->num_rows; ?> new</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($notices_result && $notices_result->num_rows > 0): ?>
                        <?php 
                        $notices_result->data_seek(0);
                        while ($notice = $notices_result->fetch_assoc()): 
                            $is_important = ($notice['priority'] ?? '') == 'high';
                        ?>
                            <div class="notice-card <?php echo $is_important ? 'important' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-2">
                                            <div class="<?php echo $is_important ? 'bg-danger' : 'bg-primary'; ?> rounded-circle p-2 me-3">
                                                <i class="fas fa-bullhorn text-white"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($notice['title']); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($notice['course_code'] ?? 'General'); ?> • 
                                                    <?php echo htmlspecialchars($notice['posted_by'] ?? 'System'); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <p class="mb-2 small">
                                            <?php echo htmlspecialchars(substr($notice['content'] ?? '', 0, 120)); ?>...
                                        </p>
                                    </div>
                                    <small class="text-muted ms-3"><?php echo date('M d', strtotime($notice['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        
                        <div class="text-center mt-3">
                            <a href="notices.php" class="btn btn-sm btn-outline-primary">
                                View All Notices
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <h6 class="mt-3">No Notices</h6>
                            <p class="text-muted small">No announcements at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions mt-4">
            <h5 class="mb-4"><i class="fas fa-bolt me-2"></i> Quick Actions</h5>
            <div class="row">
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <button class="quick-action-btn btn btn-primary" onclick="location.href='my_attendance.php'">
                        <i class="fas fa-calendar-check fa-2x"></i>
                        <span>Attendance</span>
                    </button>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <button class="quick-action-btn btn btn-success" onclick="location.href='my_courses.php'">
                        <i class="fas fa-book fa-2x"></i>
                        <span>My Courses</span>
                    </button>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <button class="quick-action-btn btn btn-info" onclick="location.href='schedule.php'">
                        <i class="fas fa-calendar-alt fa-2x"></i>
                        <span>Schedule</span>
                    </button>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <button class="quick-action-btn btn btn-warning" onclick="location.href='request_leave.php'">
                        <i class="fas fa-calendar-times fa-2x"></i>
                        <span>Request Leave</span>
                    </button>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <button class="quick-action-btn btn btn-secondary" onclick="location.href='profile.php'">
                        <i class="fas fa-user fa-2x"></i>
                        <span>Profile</span>
                    </button>
                </div>
                <div class="col-md-2 col-sm-4 col-6 mb-3">
                    <button class="quick-action-btn btn btn-dark" onclick="printDashboard()">
                        <i class="fas fa-print fa-2x"></i>
                        <span>Print</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-5 text-center text-muted">
            <small>
                &copy; <?php echo date('Y'); ?> DDU Attendance System • Student Dashboard • 
                Last updated: <?php echo date('h:i A'); ?>
            </small>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ======================================================
        // INITIALIZATION
        // ======================================================
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading overlay
            setTimeout(() => {
                document.getElementById('loadingOverlay').style.opacity = '0';
                setTimeout(() => {
                    document.getElementById('loadingOverlay').style.display = 'none';
                }, 300);
            }, 500);
            
            // Initialize attendance chart
            initializeAttendanceChart();
            
            // Update countdown timers
            updateCountdowns();
            
            // Set interval for countdown updates (every minute)
            setInterval(updateCountdowns, 60000);
            
            // Set interval for page refresh (every 5 minutes)
            setInterval(() => {
                if (!document.querySelector('.modal.show')) {
                    location.reload();
                }
            }, 300000);
            
            // Add click effects to buttons
            initializeButtonEffects();
            
            // Check for mobile view
            checkMobileView();
        });
        
        // ======================================================
        // MOBILE SIDEBAR TOGGLE
        // ======================================================
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('mobile-open');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            
            if (window.innerWidth <= 992 && 
                !sidebar.contains(event.target) && 
                !mobileBtn.contains(event.target) && 
                sidebar.classList.contains('mobile-open')) {
                sidebar.classList.remove('mobile-open');
            }
        });
        
        // ======================================================
        // ATTENDANCE CHART
        // ======================================================
        function initializeAttendanceChart() {
            const ctx = document.getElementById('attendanceChart');
            
            if (!ctx) return;
            
            <?php if ($attendance_result && $attendance_result->num_rows > 0): ?>
                const courses = [];
                const percentages = [];
                const colors = [];
                
                <?php 
                $attendance_result->data_seek(0);
                while ($att = $attendance_result->fetch_assoc()): 
                ?>
                    courses.push('<?php echo addslashes($att['course_code']); ?>');
                    percentages.push(<?php echo floatval($att['percentage']); ?>);
                    colors.push(
                        <?php echo $att['percentage']; ?> >= 75 ? 'rgba(40, 167, 69, 0.7)' :
                        <?php echo $att['percentage']; ?> >= 50 ? 'rgba(255, 193, 7, 0.7)' :
                        'rgba(220, 53, 69, 0.7)'
                    );
                <?php endwhile; ?>
                
                // Create chart
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: courses,
                        datasets: [{
                            label: 'Attendance Percentage',
                            data: percentages,
                            backgroundColor: colors,
                            borderColor: colors.map(color => color.replace('0.7', '1')),
                            borderWidth: 1,
                            borderRadius: 5
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.dataset.label}: ${context.raw}%`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        }
                    }
                });
            <?php else: ?>
                // Hide chart container if no data
                ctx.parentElement.style.display = 'none';
            <?php endif; ?>
        }
        
        // ======================================================
        // COUNTDOWN TIMERS FOR CLASSES
        // ======================================================
        function updateCountdowns() {
            const now = new Date();
            const currentTime = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
            
            document.querySelectorAll('.class-card').forEach(card => {
                const timeElements = card.querySelectorAll('.text-muted');
                if (timeElements.length > 0) {
                    const timeText = timeElements[0].textContent;
                    if (timeText && timeText.includes(':')) {
                        const timeMatch = timeText.match(/(\d{1,2}:\d{2}\s?[AP]M)/g);
                        if (timeMatch && timeMatch.length >= 1) {
                            const startTime = convertTo24Hour(timeMatch[0]);
                            const startSeconds = timeToSeconds(startTime);
                            
                            // Update countdown if class hasn't started yet
                            const countdownElement = card.querySelector('.badge.bg-info');
                            if (countdownElement && currentTime < startSeconds) {
                                const timeLeft = startSeconds - currentTime;
                                const hoursLeft = Math.floor(timeLeft / 3600);
                                const minutesLeft = Math.floor((timeLeft % 3600) / 60);
                                
                                if (hoursLeft > 0 || minutesLeft > 0) {
                                    countdownElement.innerHTML = `${hoursLeft}h ${minutesLeft}m`;
                                } else {
                                    countdownElement.innerHTML = 'Starting soon';
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Convert 12-hour time to 24-hour format
        function convertTo24Hour(time12h) {
            const [time, modifier] = time12h.split(/(?=[AP]M)/);
            let [hours, minutes] = time.split(':');
            
            hours = parseInt(hours, 10);
            
            if (modifier === 'PM' && hours !== 12) {
                hours += 12;
            }
            
            if (modifier === 'AM' && hours === 12) {
                hours = 0;
            }
            
            return `${hours.toString().padStart(2, '0')}:${minutes}:00`;
        }
        
        // Convert time string to seconds
        function timeToSeconds(timeStr) {
            const [hours, minutes, seconds] = timeStr.split(':').map(Number);
            return hours * 3600 + minutes * 60 + seconds;
        }
        
        // ======================================================
        // PRINT DASHBOARD FUNCTION
        // ======================================================
        function printDashboard() {
            window.print();
        }
        
        // ======================================================
        // BUTTON EFFECTS
        // ======================================================
        function initializeButtonEffects() {
            // Add ripple effect to buttons
            document.querySelectorAll('.btn, .nav-link-custom, .stat-card').forEach(element => {
                element.addEventListener('click', function(e) {
                    // Create ripple element
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.cssText = `
                        position: absolute;
                        border-radius: 50%;
                        background: rgba(255, 255, 255, 0.7);
                        transform: scale(0);
                        animation: ripple 0.6s linear;
                        width: ${size}px;
                        height: ${size}px;
                        left: ${x}px;
                        top: ${y}px;
                        pointer-events: none;
                    `;
                    
                    this.style.position = 'relative';
                    this.style.overflow = 'hidden';
                    this.appendChild(ripple);
                    
                    // Remove ripple after animation
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
        }
        
        // ======================================================
        // MOBILE VIEW CHECK
        // ======================================================
        function checkMobileView() {
            const sidebar = document.getElementById('sidebar');
            
            function handleResize() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('mobile-open');
                }
            }
            
            window.addEventListener('resize', handleResize);
            handleResize(); // Initial check
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
                errorDiv.className = 'alert alert-danger position-fixed top-0 start-0 m-3';
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
        
        // ======================================================
        // ADDITIONAL CSS FOR RIPPLE EFFECT
        // ======================================================
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
            
            @media (max-width: 992px) {
                body {
                    overflow-x: hidden;
                }
                
                .sidebar.mobile-open {
                    transform: translateX(0);
                    box-shadow: 5px 0 25px rgba(0, 0, 0, 0.3);
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>