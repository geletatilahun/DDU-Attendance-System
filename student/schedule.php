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

// Get view type (day, week, month)
$view_type = isset($_GET['view']) ? $_GET['view'] : 'week';
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_day = isset($_GET['day']) ? ucfirst($_GET['day']) : date('l');

// Get week range for week view
$week_start = date('Y-m-d', strtotime('monday this week', strtotime($selected_date)));
$week_end = date('Y-m-d', strtotime('sunday this week', strtotime($selected_date)));

/* ======================================================
   FETCH STUDENT INFORMATION
====================================================== */
$student_sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
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
   FETCH WEEKLY SCHEDULE
====================================================== */
$schedule_sql = "
    SELECT 
        cs.*,
        c.course_id,
        c.course_code,
        c.course_name,
        c.course_description,
        c.course_color,
        c.credits,
        u.full_name as teacher_name,
        u.email as teacher_email,
        u.profile_image as teacher_image,
        r.room_number,
        r.building,
        r.floor,
        r.capacity,
        CASE 
            WHEN cs.day_of_week = 'Monday' THEN 1
            WHEN cs.day_of_week = 'Tuesday' THEN 2
            WHEN cs.day_of_week = 'Wednesday' THEN 3
            WHEN cs.day_of_week = 'Thursday' THEN 4
            WHEN cs.day_of_week = 'Friday' THEN 5
            WHEN cs.day_of_week = 'Saturday' THEN 6
            WHEN cs.day_of_week = 'Sunday' THEN 7
        END as day_order
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN course_students cos ON c.course_id = cos.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    LEFT JOIN classrooms r ON cs.room_id = r.room_id
    WHERE cos.student_id = ?
      AND cs.is_active = 1
      AND (cs.start_date IS NULL OR cs.start_date <= ?)
      AND (cs.end_date IS NULL OR cs.end_date >= ?)
";

// Apply filters based on view type
if ($view_type == 'day') {
    $schedule_sql .= " AND cs.day_of_week = ?";
    $params = [$student_id, $selected_date, $selected_date, $selected_day];
    $param_types = "isss";
} else {
    $schedule_sql .= " ORDER BY day_order, cs.start_time";
    $params = [$student_id, $selected_date, $selected_date];
    $param_types = "iss";
}

$schedule_stmt = $conn->prepare($schedule_sql);
if ($schedule_stmt) {
    $schedule_stmt->bind_param($param_types, ...$params);
    $schedule_stmt->execute();
    $schedule_result = $schedule_stmt->get_result();
    
    // Organize schedule by day
    $weekly_schedule = [];
    $daily_schedule = [];
    $total_classes = 0;
    
    while ($class = $schedule_result->fetch_assoc()) {
        $day = $class['day_of_week'];
        if (!isset($weekly_schedule[$day])) {
            $weekly_schedule[$day] = [];
        }
        $weekly_schedule[$day][] = $class;
        
        if ($view_type == 'day' && $day == $selected_day) {
            $daily_schedule[] = $class;
        }
        $total_classes++;
    }
} else {
    $weekly_schedule = [];
    $daily_schedule = [];
    $total_classes = 0;
}

/* ======================================================
   FETCH TODAY'S SCHEDULE
====================================================== */
$today = date('Y-m-d');
$today_day = date('l');

$today_schedule_sql = "
    SELECT 
        cs.*,
        c.course_code,
        c.course_name,
        c.course_color,
        u.full_name as teacher_name,
        r.room_number,
        r.building
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN course_students cos ON c.course_id = cos.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    LEFT JOIN classrooms r ON cs.room_id = r.room_id
    WHERE cos.student_id = ?
      AND cs.day_of_week = ?
      AND cs.is_active = 1
      AND (cs.start_date IS NULL OR cs.start_date <= ?)
      AND (cs.end_date IS NULL OR cs.end_date >= ?)
    ORDER BY cs.start_time
";

$today_stmt = $conn->prepare($today_schedule_sql);
if ($today_stmt) {
    $today_stmt->bind_param("isss", $student_id, $today_day, $today, $today);
    $today_stmt->execute();
    $today_result = $today_stmt->get_result();
    $today_classes = $today_result->num_rows;
}

/* ======================================================
   FETCH CLASS STATISTICS
====================================================== */
$stats_sql = "
    SELECT 
        cs.day_of_week,
        COUNT(*) as class_count,
        SUM(TIME_TO_SEC(TIMEDIFF(cs.end_time, cs.start_time))) / 3600 as total_hours,
        GROUP_CONCAT(DISTINCT c.course_code ORDER BY c.course_code) as course_codes
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN course_students cos ON c.course_id = cos.course_id
    WHERE cos.student_id = ?
      AND cs.is_active = 1
    GROUP BY cs.day_of_week
    ORDER BY 
        CASE cs.day_of_week
            WHEN 'Monday' THEN 1
            WHEN 'Tuesday' THEN 2
            WHEN 'Wednesday' THEN 3
            WHEN 'Thursday' THEN 4
            WHEN 'Friday' THEN 5
            WHEN 'Saturday' THEN 6
            WHEN 'Sunday' THEN 7
        END
";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $student_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    
    $day_stats = [];
    $total_weekly_hours = 0;
    
    while ($stat = $stats_result->fetch_assoc()) {
        $day_stats[$stat['day_of_week']] = $stat;
        $total_weekly_hours += $stat['total_hours'];
    }
}

/* ======================================================
   FETCH UPCOMING EXAMS
====================================================== */
$exams_sql = "
    SELECT 
        e.*,
        c.course_code,
        c.course_name,
        c.course_color,
        r.room_number,
        DATE_FORMAT(e.exam_date, '%a, %b %d, %Y') as formatted_date,
        DATE_FORMAT(e.start_time, '%h:%i %p') as start_time_formatted,
        DATE_FORMAT(e.end_time, '%h:%i %p') as end_time_formatted,
        DATEDIFF(e.exam_date, CURDATE()) as days_left
    FROM exams e
    JOIN courses c ON e.course_id = c.course_id
    JOIN course_students cs ON c.course_id = cs.course_id
    LEFT JOIN classrooms r ON e.room_id = r.room_id
    WHERE cs.student_id = ?
      AND e.exam_date >= CURDATE()
      AND e.is_active = 1
    ORDER BY e.exam_date, e.start_time
    LIMIT 5
";

$exams_stmt = $conn->prepare($exams_sql);
if ($exams_stmt) {
    $exams_stmt->bind_param("i", $student_id);
    $exams_stmt->execute();
    $exams_result = $exams_stmt->get_result();
}

/* ======================================================
   FETCH HOLIDAYS AND BREAKS
====================================================== */
$holidays_sql = "
    SELECT 
        h.*,
        DATE_FORMAT(h.start_date, '%b %d') as start_date_short,
        DATE_FORMAT(h.end_date, '%b %d') as end_date_short,
        CASE 
            WHEN CURDATE() BETWEEN h.start_date AND h.end_date THEN 'ongoing'
            WHEN CURDATE() > h.end_date THEN 'past'
            ELSE 'upcoming'
        END as status
    FROM holidays h
    WHERE h.is_active = 1
      AND h.end_date >= CURDATE() - INTERVAL 7 DAY
    ORDER BY h.start_date
    LIMIT 5
";

$holidays_result = $conn->query($holidays_sql);

/* ======================================================
   FETCH CLASSROOM LOCATIONS
====================================================== */
$classrooms_sql = "
    SELECT DISTINCT
        r.room_id,
        r.room_number,
        r.building,
        r.floor,
        r.capacity,
        GROUP_CONCAT(DISTINCT c.course_code SEPARATOR ', ') as courses
    FROM classrooms r
    JOIN class_schedule cs ON r.room_id = cs.room_id
    JOIN courses c ON cs.course_id = c.course_id
    JOIN course_students cos ON c.course_id = cos.course_id
    WHERE cos.student_id = ?
      AND cs.is_active = 1
    GROUP BY r.room_id
    ORDER BY r.building, r.floor, r.room_number
";

$classrooms_stmt = $conn->prepare($classrooms_sql);
if ($classrooms_stmt) {
    $classrooms_stmt->bind_param("i", $student_id);
    $classrooms_stmt->execute();
    $classrooms_result = $classrooms_stmt->get_result();
}

/* ======================================================
   FETCH ATTENDANCE FOR TODAY'S CLASSES
====================================================== */
$attendance_sql = "
    SELECT 
        a.*,
        c.course_code,
        c.course_name
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
      AND DATE(a.attendance_date) = CURDATE()
    ORDER BY a.check_in_time DESC
";

$attendance_stmt = $conn->prepare($attendance_sql);
if ($attendance_stmt) {
    $attendance_stmt->bind_param("i", $student_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->get_result();
}

$page_title = "Class Schedule";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Schedule - DDU Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
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
        
        .page-header::before {
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
        
        .view-toggle {
            background: white;
            border-radius: 15px;
            padding: 5px;
            display: inline-flex;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .view-toggle .btn {
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 500;
        }
        
        .view-toggle .btn.active {
            background: var(--dept-color);
            color: white;
            box-shadow: 0 3px 10px rgba(0,51,102,0.2);
        }
        
        .time-slots {
            border-right: 2px solid #e9ecef;
            padding-right: 15px;
        }
        
        .time-slot {
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 15px;
            color: #6c757d;
            font-size: 0.9rem;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .time-slot.hour {
            font-weight: 600;
            color: var(--ddu-blue);
        }
        
        .schedule-grid {
            position: relative;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .day-header {
            background: linear-gradient(135deg, var(--dept-color), #0055aa);
            color: white;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            border-bottom: 3px solid white;
        }
        
        .day-header.today {
            background: linear-gradient(135deg, var(--ddu-green), #20c997);
            position: relative;
        }
        
        .day-header.today::after {
            content: 'TODAY';
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
        }
        
        .schedule-cell {
            height: 60px;
            border-bottom: 1px solid #f0f0f0;
            border-right: 1px solid #f0f0f0;
            position: relative;
        }
        
        .class-block {
            position: absolute;
            border-radius: 10px;
            padding: 10px;
            color: white;
            font-size: 0.85rem;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.3s;
            overflow: hidden;
            z-index: 1;
        }
        
        .class-block:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 2;
        }
        
        .class-block::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: rgba(255,255,255,0.3);
        }
        
        .class-block .course-code {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 2px;
        }
        
        .class-block .time {
            font-size: 0.8rem;
            opacity: 0.9;
        }
        
        .class-block .room {
            font-size: 0.8rem;
            opacity: 0.9;
            margin-top: 2px;
        }
        
        .class-details-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            border-left: 5px solid var(--dept-color);
            transition: all 0.3s;
        }
        
        .class-details-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .class-details-card.current {
            border-left-color: var(--ddu-green);
            background: linear-gradient(135deg, rgba(220,248,198,0.1), white);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(40,167,69,0.4); }
            70% { box-shadow: 0 0 0 10px rgba(40,167,69,0); }
            100% { box-shadow: 0 0 0 0 rgba(40,167,69,0); }
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
        
        .calendar-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .exam-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--ddu-red);
            transition: all 0.3s;
        }
        
        .exam-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .holiday-card {
            background: linear-gradient(135deg, #f8f9fa, white);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--ddu-orange);
        }
        
        .classroom-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
            transition: all 0.3s;
        }
        
        .classroom-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .day-summary {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .day-summary:hover {
            background: var(--dept-color);
            color: white;
            transform: scale(1.02);
        }
        
        .day-summary.active {
            background: var(--dept-color);
            color: white;
            box-shadow: 0 5px 15px rgba(0,51,102,0.2);
        }
        
        .day-summary .day-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .day-summary .class-count {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .day-summary .hours {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .attendance-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .attendance-badge.present {
            background: #d4edda;
            color: #155724;
        }
        
        .attendance-badge.absent {
            background: #f8d7da;
            color: #721c24;
        }
        
        .attendance-badge.late {
            background: #fff3cd;
            color: #856404;
        }
        
        .attendance-badge.excused {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .empty-schedule {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }
        
        .empty-schedule i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .navigation-arrows {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .navigation-arrows .btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        
        .current-time-line {
            position: absolute;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--ddu-red);
            z-index: 10;
            pointer-events: none;
        }
        
        .current-time-line::before {
            content: '';
            position: absolute;
            left: -6px;
            top: -4px;
            width: 10px;
            height: 10px;
            background: var(--ddu-red);
            border-radius: 50%;
        }
        
        .time-now {
            position: absolute;
            right: 10px;
            top: -10px;
            background: var(--ddu-red);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 600;
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
        
        .fc {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .fc-toolbar-title {
            color: var(--ddu-blue);
            font-weight: 600;
        }
        
        .fc-button {
            background-color: var(--dept-color) !important;
            border-color: var(--dept-color) !important;
        }
        
        .fc-button:hover {
            background-color: #002244 !important;
            border-color: #002244 !important;
        }
        
        .fc-day-today {
            background-color: rgba(0,51,102,0.1) !important;
        }
        
        .fc-event {
            border-radius: 8px;
            border: none;
            padding: 3px 8px;
            font-size: 0.85rem;
        }
        
        .fc-event:hover {
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
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
            <a href="schedule.php" class="nav-link active">
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
                    <i class="fas fa-calendar-alt me-2"></i> Class Schedule
                </h3>
                <p class="text-muted mb-0">
                    Manage your class timetable and upcoming events
                </p>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="navigation-arrows me-3">
                    <?php if ($view_type == 'day'): ?>
                        <a href="schedule.php?view=day&date=<?= date('Y-m-d', strtotime($selected_date . ' -1 day')) ?>" 
                           class="btn btn-outline-primary">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <span class="mx-2 fw-medium"><?= date('F j, Y', strtotime($selected_date)) ?></span>
                        <a href="schedule.php?view=day&date=<?= date('Y-m-d', strtotime($selected_date . ' +1 day')) ?>" 
                           class="btn btn-outline-primary">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php elseif ($view_type == 'week'): ?>
                        <a href="schedule.php?view=week&date=<?= date('Y-m-d', strtotime($week_start . ' -7 days')) ?>" 
                           class="btn btn-outline-primary">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <span class="mx-2 fw-medium">
                            <?= date('M j', strtotime($week_start)) ?> - <?= date('M j, Y', strtotime($week_end)) ?>
                        </span>
                        <a href="schedule.php?view=week&date=<?= date('Y-m-d', strtotime($week_start . ' +7 days')) ?>" 
                           class="btn btn-outline-primary">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                
                <a href="dashboard.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
                <button class="btn btn-primary" onclick="printSchedule()">
                    <i class="fas fa-print me-1"></i> Print
                </button>
            </div>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="fw-bold mb-3">Class Timetable</h1>
                    <p class="mb-0">
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-clock me-1"></i> <?= $total_classes ?> Weekly Classes
                        </span>
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-chalkboard-teacher me-1"></i> <?= $today_classes ?? 0 ?> Today's Classes
                        </span>
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-hourglass-half me-1"></i> <?= round($total_weekly_hours ?? 0, 1) ?> Weekly Hours
                        </span>
                    </p>
                    <div class="mt-4">
                        <div class="view-toggle">
                            <a href="schedule.php?view=day&date=<?= date('Y-m-d') ?>" 
                               class="btn <?= $view_type == 'day' ? 'active' : '' ?>">
                                <i class="fas fa-calendar-day me-1"></i> Day
                            </a>
                            <a href="schedule.php?view=week&date=<?= date('Y-m-d') ?>" 
                               class="btn <?= $view_type == 'week' ? 'active' : '' ?>">
                                <i class="fas fa-calendar-week me-1"></i> Week
                            </a>
                            <a href="schedule.php?view=month" 
                               class="btn <?= $view_type == 'month' ? 'active' : '' ?>">
                                <i class="fas fa-calendar me-1"></i> Month
                            </a>
                        </div>
                        <a href="schedule.php?view=day&date=<?= date('Y-m-d') ?>" class="btn btn-outline-light ms-3">
                            <i class="fas fa-calendar-check me-1"></i> Today
                        </a>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="bg-white rounded-circle d-inline-flex align-items-center justify-content-center" 
                         style="width: 100px; height: 100px;">
                        <div class="text-center">
                            <div class="fs-4 fw-bold text-primary"><?= date('d') ?></div>
                            <div class="text-muted small"><?= date('M') ?></div>
                            <div class="text-muted small"><?= date('Y') ?></div>
                        </div>
                    </div>
                    <small class="text-white-50 mt-2 d-block">Current Date</small>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-attendance" onclick="showTodaySchedule()">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $today_classes ?? 0 ?></h2>
                                <p class="mb-0">Today's Classes</p>
                                <small class="opacity-75">
                                    <?= date('l, F j') ?>
                                </small>
                            </div>
                            <i class="fas fa-calendar-day stat-icon"></i>
                        </div>
                        <div class="mt-3">
                            <?php if ($today_result && $today_result->num_rows > 0): 
                                $today_result->data_seek(0);
                                $count = 0;
                                while ($class = $today_result->fetch_assoc() && $count < 2): 
                                    $count++;
                                    $start_time = date('h:i A', strtotime($class['start_time']));
                            ?>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <small><?= $class['course_code'] ?></small>
                                    <small class="badge bg-light text-dark"><?= $start_time ?></small>
                                </div>
                            <?php endwhile; endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $total_classes ?></h2>
                                <p class="mb-0">Weekly Classes</p>
                                <small class="opacity-75">
                                    <?= round($total_weekly_hours ?? 0, 1) ?> hours
                                </small>
                            </div>
                            <i class="fas fa-calendar-week stat-icon"></i>
                        </div>
                        <div class="mt-3">
                            <?php 
                            $days_with_classes = 0;
                            $max_classes = 0;
                            $busiest_day = '';
                            
                            if (isset($day_stats)) {
                                foreach ($day_stats as $day => $stat) {
                                    if ($stat['class_count'] > $max_classes) {
                                        $max_classes = $stat['class_count'];
                                        $busiest_day = $day;
                                    }
                                    $days_with_classes++;
                                }
                            }
                            ?>
                            <small>
                                Busiest: <?= $busiest_day ?> (<?= $max_classes ?> classes)
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-present">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $exams_result ? $exams_result->num_rows : 0 ?></h2>
                                <p class="mb-0">Upcoming Exams</p>
                                <small class="opacity-75">
                                    Tests & assessments
                                </small>
                            </div>
                            <i class="fas fa-file-alt stat-icon"></i>
                        </div>
                        <div class="mt-3">
                            <?php if ($exams_result && $exams_result->num_rows > 0): 
                                $exams_result->data_seek(0);
                                $exam = $exams_result->fetch_assoc();
                            ?>
                                <small>Next: <?= $exam['formatted_date'] ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-percentage">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $classrooms_result ? $classrooms_result->num_rows : 0 ?></h2>
                                <p class="mb-0">Classrooms</p>
                                <small class="opacity-75">
                                    Locations & rooms
                                </small>
                            </div>
                            <i class="fas fa-map-marker-alt stat-icon"></i>
                        </div>
                        <div class="mt-3">
                            <?php if ($classrooms_result && $classrooms_result->num_rows > 0): 
                                $classrooms_result->data_seek(0);
                                $room = $classrooms_result->fetch_assoc();
                            ?>
                                <small>Main: <?= htmlspecialchars($room['building'] ?? 'N/A') ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="row">
            <!-- Left Column: Schedule View -->
            <div class="col-lg-<?= $view_type == 'month' ? '12' : '8' ?>">
                <?php if ($view_type == 'month'): ?>
                    <!-- Calendar View -->
                    <div class="calendar-card">
                        <div id="calendar"></div>
                    </div>
                <?php elseif ($view_type == 'day'): ?>
                    <!-- Day View -->
                    <div class="schedule-grid">
                        <div class="row g-0">
                            <!-- Time Slots -->
                            <div class="col-2 time-slots">
                                <?php for ($hour = 8; $hour <= 20; $hour++): ?>
                                    <div class="time-slot hour"><?= sprintf('%02d:00', $hour) ?></div>
                                    <div class="time-slot"><?= sprintf('%02d:30', $hour) ?></div>
                                <?php endfor; ?>
                            </div>
                            
                            <!-- Day Schedule -->
                            <div class="col-10 position-relative">
                                <?php 
                                // Calculate current time position
                                $current_hour = date('G');
                                $current_minute = date('i');
                                $current_position = (($current_hour - 8) * 120 + ($current_minute * 2));
                                
                                if ($current_hour >= 8 && $current_hour <= 20): 
                                ?>
                                    <div class="current-time-line" style="top: <?= $current_position ?>px;">
                                        <div class="time-now"><?= date('h:i A') ?></div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php for ($hour = 8; $hour <= 20; $hour++): ?>
                                    <div class="schedule-cell"></div>
                                    <div class="schedule-cell"></div>
                                <?php endfor; ?>
                                
                                <!-- Render Classes -->
                                <?php if (!empty($daily_schedule)): 
                                    foreach ($daily_schedule as $class): 
                                        $start_time = strtotime($class['start_time']);
                                        $end_time = strtotime($class['end_time']);
                                        
                                        $start_hour = date('G', $start_time);
                                        $start_minute = date('i', $start_time);
                                        $end_hour = date('G', $end_time);
                                        $end_minute = date('i', $end_time);
                                        
                                        $top = (($start_hour - 8) * 120 + ($start_minute * 2));
                                        $height = (($end_hour - $start_hour) * 120 + ($end_minute - $start_minute) * 2);
                                        
                                        $is_current = (time() >= $start_time && time() <= $end_time);
                                        $course_color = $class['course_color'] ?? $dept_color;
                                ?>
                                    <div class="class-block <?= $is_current ? 'current' : '' ?>" 
                                         style="top: <?= $top ?>px; height: <?= $height ?>px; background: <?= $course_color ?>;"
                                         onclick="showClassDetails(<?= $class['schedule_id'] ?>)">
                                        <div class="course-code"><?= htmlspecialchars($class['course_code']) ?></div>
                                        <div class="time">
                                            <?= date('h:i A', $start_time) ?> - <?= date('h:i A', $end_time) ?>
                                        </div>
                                        <div class="room"><?= htmlspecialchars($class['room_number'] ?? 'TBA') ?></div>
                                        <?php if ($is_current): ?>
                                            <div class="position-absolute bottom-2 end-2">
                                                <span class="badge bg-white text-dark">NOW</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Today's Classes List -->
                    <div class="mt-4">
                        <h5 class="mb-3">
                            <i class="fas fa-list me-2"></i>
                            Today's Classes - <?= date('l, F j, Y') ?>
                        </h5>
                        
                        <?php if (!empty($daily_schedule)): ?>
                            <?php foreach ($daily_schedule as $class): 
                                $is_current = (time() >= strtotime($class['start_time']) && time() <= strtotime($class['end_time']));
                                $course_color = $class['course_color'] ?? $dept_color;
                            ?>
                                <div class="class-details-card <?= $is_current ? 'current' : '' ?>">
                                    <div class="row align-items-center">
                                        <div class="col-md-8">
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="badge me-3" style="background: <?= $course_color ?>; color: white;">
                                                    <?= htmlspecialchars($class['course_code']) ?>
                                                </div>
                                                <span class="fw-bold"><?= htmlspecialchars($class['course_name']) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center text-muted">
                                                <span class="me-3">
                                                    <i class="far fa-clock me-1"></i>
                                                    <?= date('h:i A', strtotime($class['start_time'])) ?> - 
                                                    <?= date('h:i A', strtotime($class['end_time'])) ?>
                                                </span>
                                                <?php if (!empty($class['room_number'])): ?>
                                                    <span class="me-3">
                                                        <i class="fas fa-map-marker-alt me-1"></i>
                                                        <?= htmlspecialchars($class['room_number']) ?>
                                                        <?= !empty($class['building']) ? ' (' . htmlspecialchars($class['building']) . ')' : '' ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span>
                                                    <i class="fas fa-chalkboard-teacher me-1"></i>
                                                    <?= htmlspecialchars($class['teacher_name'] ?? 'TBA') ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="col-md-4 text-end">
                                            <?php if ($is_current): ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-play me-1"></i> Ongoing
                                                </span>
                                            <?php elseif (strtotime($class['start_time']) > time()): ?>
                                                <?php 
                                                $time_left = strtotime($class['start_time']) - time();
                                                $hours_left = floor($time_left / 3600);
                                                $minutes_left = floor(($time_left % 3600) / 60);
                                                ?>
                                                <small class="text-muted d-block">Starts in</small>
                                                <span class="badge bg-primary">
                                                    <?= $hours_left ?>h <?= $minutes_left ?>m
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">
                                                    <i class="fas fa-check me-1"></i> Completed
                                                </span>
                                            <?php endif; ?>
                                            
                                            <div class="mt-2">
                                                <button class="btn btn-sm btn-outline-primary"
                                                        onclick="markAttendance(<?= $class['course_id'] ?>)">
                                                    <i class="fas fa-check-circle me-1"></i> Mark Attendance
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-schedule">
                                <i class="fas fa-coffee fa-3x mb-3"></i>
                                <h4>No Classes Today</h4>
                                <p class="text-muted">Enjoy your free day! You have no scheduled classes.</p>
                                <a href="schedule.php?view=week" class="btn btn-primary">
                                    <i class="fas fa-calendar-week me-1"></i> View Weekly Schedule
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <!-- Week View -->
                    <div class="schedule-grid">
                        <div class="row g-0">
                            <!-- Time Slots -->
                            <div class="col-2 time-slots">
                                <?php for ($hour = 8; $hour <= 20; $hour++): ?>
                                    <div class="time-slot hour"><?= sprintf('%02d:00', $hour) ?></div>
                                    <div class="time-slot"><?= sprintf('%02d:30', $hour) ?></div>
                                <?php endfor; ?>
                            </div>
                            
                            <!-- Days of Week -->
                            <?php 
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                            foreach ($days as $index => $day): 
                                $is_today = ($day == date('l'));
                            ?>
                                <div class="col position-relative">
                                    <div class="day-header <?= $is_today ? 'today' : '' ?>">
                                        <?= substr($day, 0, 3) ?><br>
                                        <small><?= date('d', strtotime($week_start . " +$index days")) ?></small>
                                    </div>
                                    
                                    <?php for ($hour = 8; $hour <= 20; $hour++): ?>
                                        <div class="schedule-cell"></div>
                                        <div class="schedule-cell"></div>
                                    <?php endfor; ?>
                                    
                                    <!-- Render Classes for this day -->
                                    <?php if (isset($weekly_schedule[$day])): 
                                        foreach ($weekly_schedule[$day] as $class): 
                                            $start_time = strtotime($class['start_time']);
                                            $end_time = strtotime($class['end_time']);
                                            
                                            $start_hour = date('G', $start_time);
                                            $start_minute = date('i', $start_time);
                                            $end_hour = date('G', $end_time);
                                            $end_minute = date('i', $end_time);
                                            
                                            $top = (($start_hour - 8) * 120 + ($start_minute * 2));
                                            $height = (($end_hour - $start_hour) * 120 + ($end_minute - $start_minute) * 2);
                                            
                                            $course_color = $class['course_color'] ?? $dept_color;
                                    ?>
                                        <div class="class-block" 
                                             style="top: <?= $top ?>px; height: <?= $height ?>px; background: <?= $course_color ?>;"
                                             onclick="showClassDetails(<?= $class['schedule_id'] ?>)">
                                            <div class="course-code"><?= htmlspecialchars($class['course_code']) ?></div>
                                            <div class="time">
                                                <?= date('h:i', $start_time) ?> - <?= date('h:i', $end_time) ?>
                                            </div>
                                            <div class="room"><?= htmlspecialchars($class['room_number'] ?? '') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Weekly Summary -->
                <?php if ($view_type == 'week'): ?>
                    <div class="row mt-4 g-3">
                        <?php foreach ($days as $day): 
                            $class_count = isset($weekly_schedule[$day]) ? count($weekly_schedule[$day]) : 0;
                            $total_hours = isset($day_stats[$day]) ? round($day_stats[$day]['total_hours'], 1) : 0;
                            $is_today = ($day == date('l'));
                        ?>
                            <div class="col-md-2 col-sm-4 col-6">
                                <a href="schedule.php?view=day&day=<?= $day ?>&date=<?= date('Y-m-d', strtotime($week_start . " +" . array_search($day, $days) . " days")) ?>"
                                   class="day-summary <?= $is_today ? 'active' : '' ?>">
                                    <div class="day-name"><?= substr($day, 0, 3) ?></div>
                                    <div class="class-count"><?= $class_count ?></div>
                                    <div class="hours"><?= $total_hours ?> hrs</div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column: Sidebar -->
            <?php if ($view_type != 'month'): ?>
                <div class="col-lg-4">
                    <!-- Today's Attendance -->
                    <div class="calendar-card">
                        <h6 class="mb-3">
                            <i class="fas fa-check-circle me-2"></i>
                            Today's Attendance
                        </h6>
                        
                        <?php if ($attendance_result && $attendance_result->num_rows > 0): ?>
                            <?php while ($attendance = $attendance_result->fetch_assoc()): 
                                $badge_class = '';
                                if ($attendance['status'] == 'present') $badge_class = 'present';
                                elseif ($attendance['status'] == 'absent') $badge_class = 'absent';
                                elseif ($attendance['status'] == 'late') $badge_class = 'late';
                                elseif ($attendance['status'] == 'excused') $badge_class = 'excused';
                            ?>
                                <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                    <div>
                                        <small class="d-block"><?= htmlspecialchars($attendance['course_code']) ?></small>
                                        <span class="fw-medium"><?= htmlspecialchars($attendance['course_name']) ?></span>
                                    </div>
                                    <div class="text-end">
                                        <span class="attendance-badge <?= $badge_class ?>">
                                            <?= ucfirst($attendance['status']) ?>
                                        </span>
                                        <?php if ($attendance['check_in_time']): ?>
                                            <small class="d-block text-muted">
                                                <?= date('h:i A', strtotime($attendance['check_in_time'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-clipboard-check fa-2x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No attendance marked yet today</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <a href="my_attendance.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-history me-1"></i> View Attendance History
                            </a>
                        </div>
                    </div>
                    
                    <!-- Upcoming Exams -->
                    <div class="calendar-card">
                        <h6 class="mb-3">
                            <i class="fas fa-file-alt me-2"></i>
                            Upcoming Exams
                        </h6>
                        
                        <?php if ($exams_result && $exams_result->num_rows > 0): ?>
                            <?php while ($exam = $exams_result->fetch_assoc()): 
                                $is_urgent = $exam['days_left'] <= 3;
                            ?>
                                <div class="exam-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($exam['title']) ?></h6>
                                            <p class="text-muted mb-1 small">
                                                <?= htmlspecialchars($exam['course_code']) ?> - 
                                                <?= htmlspecialchars($exam['course_name']) ?>
                                            </p>
                                            <small class="text-muted">
                                                <i class="far fa-calendar me-1"></i> <?= $exam['formatted_date'] ?>
                                                <i class="far fa-clock ms-3 me-1"></i> <?= $exam['start_time_formatted'] ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($is_urgent): ?>
                                                <span class="badge bg-danger">Urgent</span>
                                            <?php else: ?>
                                                <span class="badge bg-info"><?= $exam['days_left'] ?> days</span>
                                            <?php endif; ?>
                                            <?php if (!empty($exam['room_number'])): ?>
                                                <small class="text-muted d-block mt-1">
                                                    <?= htmlspecialchars($exam['room_number']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-calendar-check fa-2x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No upcoming exams</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <a href="exams.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-calendar me-1"></i> View Exam Schedule
                            </a>
                        </div>
                    </div>
                    
                    <!-- Holidays & Breaks -->
                    <div class="calendar-card">
                        <h6 class="mb-3">
                            <i class="fas fa-umbrella-beach me-2"></i>
                            Holidays & Breaks
                        </h6>
                        
                        <?php if ($holidays_result && $holidays_result->num_rows > 0): ?>
                            <?php while ($holiday = $holidays_result->fetch_assoc()): 
                                $is_ongoing = $holiday['status'] == 'ongoing';
                            ?>
                                <div class="holiday-card">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($holiday['title']) ?></h6>
                                            <small class="text-muted">
                                                <i class="far fa-calendar me-1"></i>
                                                <?= $holiday['start_date_short'] ?> - <?= $holiday['end_date_short'] ?>
                                            </small>
                                        </div>
                                        <?php if ($is_ongoing): ?>
                                            <span class="badge bg-success">Now</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-smile fa-2x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No upcoming holidays</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <a href="calendar.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-calendar-alt me-1"></i> Academic Calendar
                            </a>
                        </div>
                    </div>
                    
                    <!-- Classroom Locations -->
                    <div class="calendar-card">
                        <h6 class="mb-3">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            Classroom Locations
                        </h6>
                        
                        <?php if ($classrooms_result && $classrooms_result->num_rows > 0): 
                            $classrooms_result->data_seek(0);
                            while ($classroom = $classrooms_result->fetch_assoc()): 
                        ?>
                            <div class="classroom-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1"><?= htmlspecialchars($classroom['room_number']) ?></h6>
                                        <p class="text-muted mb-1 small">
                                            <?= htmlspecialchars($classroom['building']) ?>
                                            <?= !empty($classroom['floor']) ? ' • Floor ' . $classroom['floor'] : '' ?>
                                        </p>
                                        <small class="text-muted">
                                            <i class="fas fa-users me-1"></i>
                                            Capacity: <?= $classroom['capacity'] ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <button class="btn btn-sm btn-outline-primary"
                                                onclick="viewLocation('<?= htmlspecialchars($classroom['building']) ?>', '<?= htmlspecialchars($classroom['room_number']) ?>')">
                                            <i class="fas fa-directions"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-map fa-2x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No classroom information</p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <button class="btn btn-outline-primary btn-sm" onclick="showCampusMap()">
                                <i class="fas fa-map-marked-alt me-1"></i> View Campus Map
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Class Details Modal -->
    <div class="modal fade" id="classDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Class Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="classDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    
    <script>
        // Initialize FullCalendar for month view
        <?php if ($view_type == 'month'): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            
            // Fetch events for calendar
            fetch('get_calendar_events.php?student_id=<?= $student_id ?>')
                .then(response => response.json())
                .then(events => {
                    const calendar = new FullCalendar.Calendar(calendarEl, {
                        initialView: 'dayGridMonth',
                        headerToolbar: {
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,timeGridWeek,timeGridDay'
                        },
                        events: events,
                        eventClick: function(info) {
                            if (info.event.extendedProps.type === 'class') {
                                showClassDetails(info.event.extendedProps.schedule_id);
                            } else if (info.event.extendedProps.type === 'exam') {
                                showExamDetails(info.event.extendedProps.exam_id);
                            }
                        },
                        dateClick: function(info) {
                            window.location.href = `schedule.php?view=day&date=${info.dateStr}`;
                        },
                        eventContent: function(info) {
                            const element = document.createElement('div');
                            element.innerHTML = `
                                <div class="fc-event-content">
                                    <strong>${info.event.title}</strong>
                                    <div class="small">${info.event.extendedProps.time || ''}</div>
                                </div>
                            `;
                            return { domNodes: [element] };
                        }
                    });
                    
                    calendar.render();
                })
                .catch(error => {
                    console.error('Error loading calendar events:', error);
                    calendarEl.innerHTML = '<div class="alert alert-danger">Failed to load calendar events</div>';
                });
        });
        <?php endif; ?>
        
        // Schedule Functions
        function showClassDetails(scheduleId) {
            const modal = new bootstrap.Modal(document.getElementById('classDetailsModal'));
            const content = document.getElementById('classDetailsContent');
            
            content.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary mb-3"></div>
                    <p>Loading class details...</p>
                </div>
            `;
            
            modal.show();
            
            // Simulate AJAX call
            setTimeout(() => {
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-8">
                            <h4>Advanced Programming</h4>
                            <p class="text-muted">CS401 • Monday, 10:00 AM - 11:30 AM</p>
                            
                            <h6 class="mt-4">Class Information</h6>
                            <ul class="list-unstyled">
                                <li><strong>Instructor:</strong> Dr. John Smith</li>
                                <li><strong>Room:</strong> CS-201, Computer Science Building</li>
                                <li><strong>Credits:</strong> 4</li>
                                <li><strong>Semester:</strong> 4</li>
                                <li><strong>Schedule Type:</strong> Regular</li>
                            </ul>
                            
                            <h6 class="mt-4">Description</h6>
                            <p>Advanced programming concepts including data structures, algorithms, and software design patterns.</p>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6>Quick Actions</h6>
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-primary" onclick="markAttendance(${scheduleId})">
                                            <i class="fas fa-check-circle me-1"></i> Mark Attendance
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="viewCourseMaterials(${scheduleId})">
                                            <i class="fas fa-book me-1"></i> Course Materials
                                        </button>
                                        <button class="btn btn-outline-secondary" onclick="setReminder(${scheduleId})">
                                            <i class="fas fa-bell me-1"></i> Set Reminder
                                        </button>
                                        <a href="https://maps.google.com/?q=CS-201+Computer+Science+Building" 
                                           class="btn btn-outline-info" target="_blank">
                                            <i class="fas fa-directions me-1"></i> Get Directions
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }, 500);
        }
        
        function markAttendance(courseId) {
            if (confirm('Mark attendance for this class?')) {
                // In real app, this would be an AJAX call
                fetch('mark_attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        course_id: courseId,
                        student_id: <?= $student_id ?>,
                        status: 'present'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Attendance marked successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error marking attendance. Please try again.');
                });
            }
        }
        
        function showTodaySchedule() {
            window.location.href = 'schedule.php?view=day&date=<?= date('Y-m-d') ?>';
        }
        
        function printSchedule() {
            window.print();
        }
        
        function viewLocation(building, room) {
            alert(`Getting directions to ${room} in ${building}`);
            // In real app, open Google Maps with location
            // window.open(`https://maps.google.com/?q=${encodeURIComponent(building + ' ' + room)}`, '_blank');
        }
        
        function showCampusMap() {
            // In real app, this would show an interactive campus map
            alert('Opening campus map...');
            // window.open('campus_map.php', '_blank');
        }
        
        function viewCourseMaterials(scheduleId) {
            alert('Opening course materials for schedule ID: ' + scheduleId);
            // window.location.href = 'materials.php?schedule_id=' + scheduleId;
        }
        
        function setReminder(scheduleId) {
            const time = prompt('Set reminder (minutes before class):', '15');
            if (time) {
                alert(`Reminder set for ${time} minutes before class`);
                // In real app, this would set a browser/email notification
            }
        }
        
        function showExamDetails(examId) {
            alert('Showing exam details for ID: ' + examId);
            // window.location.href = 'exam_details.php?id=' + examId;
        }
        
        // Auto-refresh current time line
        setInterval(() => {
            const currentTimeLine = document.querySelector('.current-time-line');
            if (currentTimeLine) {
                const current_hour = new Date().getHours();
                const current_minute = new Date().getMinutes();
                
                if (current_hour >= 8 && current_hour <= 20) {
                    const current_position = ((current_hour - 8) * 120 + (current_minute * 2));
                    currentTimeLine.style.top = current_position + 'px';
                    
                    const timeNow = currentTimeLine.querySelector('.time-now');
                    if (timeNow) {
                        const now = new Date();
                        timeNow.textContent = now.toLocaleTimeString('en-US', {
                            hour: '2-digit',
                            minute: '2-digit',
                            hour12: true
                        });
                    }
                }
            }
            
            // Update countdowns for today's classes
            document.querySelectorAll('.class-details-card').forEach(card => {
                const timeBadge = card.querySelector('.badge.bg-primary');
                if (timeBadge && timeBadge.textContent.includes('h') && timeBadge.textContent.includes('m')) {
                    // Update countdown logic would go here
                }
            });
        }, 60000); // Update every minute
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowLeft' && e.ctrlKey) {
                // Previous day/week
                if (<?= $view_type == 'day' ? 'true' : 'false' ?>) {
                    window.location.href = 'schedule.php?view=day&date=<?= date('Y-m-d', strtotime($selected_date . ' -1 day')) ?>';
                } else if (<?= $view_type == 'week' ? 'true' : 'false' ?>) {
                    window.location.href = 'schedule.php?view=week&date=<?= date('Y-m-d', strtotime($week_start . ' -7 days')) ?>';
                }
            } else if (e.key === 'ArrowRight' && e.ctrlKey) {
                // Next day/week
                if (<?= $view_type == 'day' ? 'true' : 'false' ?>) {
                    window.location.href = 'schedule.php?view=day&date=<?= date('Y-m-d', strtotime($selected_date . ' +1 day')) ?>';
                } else if (<?= $view_type == 'week' ? 'true' : 'false' ?>) {
                    window.location.href = 'schedule.php?view=week&date=<?= date('Y-m-d', strtotime($week_start . ' +7 days')) ?>';
                }
            } else if (e.key === 't' && e.ctrlKey) {
                // Go to today
                window.location.href = 'schedule.php?view=day&date=<?= date('Y-m-d') ?>';
            } else if (e.key === 'p' && e.ctrlKey) {
                // Print schedule
                printSchedule();
            }
        });
        
        // Request notification permission
        if ("Notification" in window && Notification.permission === "default") {
            Notification.requestPermission();
        }
        
        // Set up class reminders
        function setupClassReminders() {
            document.querySelectorAll('.class-details-card').forEach(card => {
                const startTime = card.querySelector('.fa-clock')?.parentElement?.textContent?.match(/\d{1,2}:\d{2}\s?[AP]M/);
                if (startTime) {
                    const classTime = new Date();
                    const [time, modifier] = startTime[0].split(/(?=[AP]M)/);
                    let [hours, minutes] = time.split(':');
                    
                    if (modifier === 'PM' && hours !== '12') {
                        hours = parseInt(hours) + 12;
                    }
                    if (modifier === 'AM' && hours === '12') {
                        hours = '00';
                    }
                    
                    classTime.setHours(parseInt(hours), parseInt(minutes), 0, 0);
                    
                    // Set reminder 15 minutes before class
                    const reminderTime = new Date(classTime.getTime() - 15 * 60000);
                    
                    if (reminderTime > new Date()) {
                        const timeUntilReminder = reminderTime.getTime() - Date.now();
                        
                        setTimeout(() => {
                            if (Notification.permission === "granted") {
                                const courseCode = card.querySelector('.badge')?.textContent || 'Class';
                                new Notification("Class Reminder", {
                                    body: `${courseCode} starts in 15 minutes`,
                                    icon: "/favicon.ico"
                                });
                            }
                        }, timeUntilReminder);
                    }
                }
            });
        }
        
        // Set up reminders on page load
        document.addEventListener('DOMContentLoaded', setupClassReminders);
    </script>
</body>
</html>