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
        u.user_id,
        u.full_name,
        u.email,
        u.profile_image,
        d.department_name,
        d.department_id
    FROM users u
    LEFT JOIN departments d 
        ON u.department_id = d.department_id
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
   GET WEEK FILTER
====================================================== */
$week_start = isset($_GET['week']) ? $_GET['week'] : date('Y-m-d');
$week_start_date = new DateTime($week_start);
$week_start_date->modify('this week');
$week_end_date = clone $week_start_date;
$week_end_date->modify('+6 days');

/* ======================================================
   FETCH SCHEDULES ASSIGNED BY DEPARTMENT HEAD
   FIXED: Using course_teachers table to get teacher assignments
====================================================== */
$schedule_sql = "
    SELECT 
        cs.*,
        c.course_code,
        c.course_name,
        c.course_id,
        u.full_name as created_by_name,
        u.email as created_by_email
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN course_teachers ct ON cs.course_id = ct.course_id
    LEFT JOIN users u ON cs.created_by = u.user_id
    WHERE ct.teacher_id = ?
      AND cs.is_active = 1
    ORDER BY 
        CASE cs.day_of_week 
            WHEN 'Monday' THEN 1
            WHEN 'Tuesday' THEN 2
            WHEN 'Wednesday' THEN 3
            WHEN 'Thursday' THEN 4
            WHEN 'Friday' THEN 5
            WHEN 'Saturday' THEN 6
            WHEN 'Sunday' THEN 7
        END,
        cs.start_time
";

$schedule_stmt = $conn->prepare($schedule_sql);
if ($schedule_stmt) {
    $schedule_stmt->bind_param("i", $teacher_id);
    $schedule_stmt->execute();
    $schedule_result = $schedule_stmt->get_result();
} else {
    die("Schedule query failed: " . $conn->error);
}

/* ======================================================
   ORGANIZE SCHEDULE BY DAY
====================================================== */
$schedule_by_day = [
    'Monday' => [],
    'Tuesday' => [],
    'Wednesday' => [],
    'Thursday' => [],
    'Friday' => [],
    'Saturday' => [],
    'Sunday' => []
];

if ($schedule_result && $schedule_result->num_rows > 0) {
    while ($class = $schedule_result->fetch_assoc()) {
        $day = $class['day_of_week'];
        if (isset($schedule_by_day[$day])) {
            $schedule_by_day[$day][] = $class;
        }
    }
    $schedule_result->data_seek(0); // Reset pointer
}

/* ======================================================
   GET TODAY'S SCHEDULE
====================================================== */
$today_day = date('l');
$today_schedule = $schedule_by_day[$today_day] ?? [];

/* ======================================================
   COUNT STATISTICS
====================================================== */
$total_classes = 0;
$unique_courses = [];

foreach ($schedule_by_day as $day_classes) {
    $total_classes += count($day_classes);
    foreach ($day_classes as $class) {
        $unique_courses[$class['course_id']] = $class['course_name'];
    }
}

/* ======================================================
   GET UPCOMING CLASSES (NEXT 7 DAYS)
====================================================== */
$upcoming_classes = [];

// Get upcoming classes for next 7 days
$current_date = new DateTime();
for ($i = 0; $i < 7; $i++) {
    $date = clone $current_date;
    $date->modify("+$i days");
    $day_name = $date->format('l');
    
    if (isset($schedule_by_day[$day_name]) && !empty($schedule_by_day[$day_name])) {
        foreach ($schedule_by_day[$day_name] as $class) {
            $class_copy = $class;
            $class_copy['date'] = $date->format('Y-m-d');
            $class_copy['day_name'] = $day_name;
            $class_copy['date_display'] = $date->format('M j');
            $class_copy['weekday'] = $date->format('D');
            $upcoming_classes[] = $class_copy;
        }
    }
}

// Sort upcoming classes by date and time
usort($upcoming_classes, function($a, $b) {
    $date_cmp = strtotime($a['date']) - strtotime($b['date']);
    if ($date_cmp == 0) {
        return strtotime($a['start_time']) - strtotime($b['start_time']);
    }
    return $date_cmp;
});

// Limit to 10 upcoming classes
$upcoming_classes = array_slice($upcoming_classes, 0, 10);

/* ======================================================
   GET DEPARTMENT HEAD INFO
====================================================== */
$department_head_sql = "
    SELECT u.full_name, u.email, u.profile_image
    FROM users u
    WHERE u.department_id = ?
    AND u.role = 'department_head'
    AND u.is_active = 1
    LIMIT 1
";

$head_stmt = $conn->prepare($department_head_sql);
$head_stmt->bind_param("i", $teacher['department_id']);
$head_stmt->execute();
$head_result = $head_stmt->get_result();
$department_head = $head_result->fetch_assoc();


/* ======================================================
   GET SCHEDULE NOTIFICATIONS
====================================================== */
$notifications_sql = "
    SELECT 
        sn.*,
        cs.schedule_id,
        c.course_code,
        c.course_name
    FROM schedule_notifications sn
    LEFT JOIN class_schedule cs ON sn.schedule_id = cs.schedule_id
    LEFT JOIN courses c ON cs.course_id = c.course_id
    WHERE sn.teacher_id = ?
      AND sn.is_read = 0
    ORDER BY sn.created_at DESC
    LIMIT 5
";

$notifications_stmt = $conn->prepare($notifications_sql);
$notifications_stmt->bind_param("i", $teacher_id);
$notifications_stmt->execute();
$notifications_result = $notifications_stmt->get_result();

$unread_notifications = $notifications_result->num_rows;

/* ======================================================
   HANDLE NOTIFICATION READ
====================================================== */
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = (int) $_GET['mark_read'];
    $mark_read_sql = "UPDATE schedule_notifications SET is_read = 1 WHERE notification_id = ? AND teacher_id = ?";
    $mark_stmt = $conn->prepare($mark_read_sql);
    $mark_stmt->bind_param("ii", $notification_id, $teacher_id);
    $mark_stmt->execute();
    
    // Redirect back to avoid resubmission
    header("Location: schedule.php");
    exit;
}

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "My Schedule";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Schedule - DDU Attendance System</title>
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
        
        .bg-total { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-today { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .bg-courses { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }
        .bg-notifications { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        
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
        
        .time-slot {
            border-left: 4px solid #007bff;
            background: #f8f9fa;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .time-slot:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .today-highlight {
            background-color: #e8f4ff;
            border-color: var(--ddu-blue);
        }
        
        .day-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .current-time {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
            box-shadow: 0 0 10px rgba(255,193,7,0.3);
        }
        
        .now-badge {
            background: var(--ddu-red);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 10px;
        }
        
        .department-head-info {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .head-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid white;
        }
        
        .notification-item {
            border-left: 4px solid #007bff;
            padding: 10px 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 0 5px 5px 0;
        }
        
        .notification-item.unread {
            border-left-color: var(--ddu-red);
            background: #fff5f5;
        }
        
        .session-badge {
            font-size: 0.7rem;
            position: absolute;
            top: 10px;
            right: 10px;
        }
        
        .lecture-badge { background: #007bff; }
        .lab-badge { background: #28a745; }
        .tutorial-badge { background: #fd7e14; }
        .exam-badge { background: #dc3545; }
        
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
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h4><i class="fas fa-chalkboard-teacher"></i> DDU Teacher</h4>
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
            <a href="schedule.php" class="nav-link active">
                <i class="fas fa-calendar-alt"></i> Schedule
            </a>
            <a href="profile.php" class="nav-link">
                <i class="fas fa-user"></i> Profile
            </a>
            
            <div class="mt-4 px-3">
                <div class="user-info">
                    <small>Logged in as:</small>
                    <h6><?= htmlspecialchars($teacher['full_name']) ?></h6>
                    <small class="text-muted">Teacher | <?= htmlspecialchars($teacher['department_name']) ?></small>
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
                    <i class="fas fa-calendar-alt"></i> My Schedule
                </h4>
                <small class="text-muted">Welcome back, <?= htmlspecialchars($teacher['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <!-- Notifications Dropdown -->
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-4" style="color: var(--ddu-blue);"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="notification-badge"><?= $unread_notifications > 9 ? '9+' : $unread_notifications ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end" style="width: 350px;">
                        <h6 class="dropdown-header">Schedule Notifications</h6>
                        <?php if ($unread_notifications > 0): ?>
                            <?php 
                            $notifications_result->data_seek(0);
                            while ($notification = $notifications_result->fetch_assoc()): 
                            ?>
                                <div class="notification-item unread">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <small class="text-muted"><?= date('M j, h:i A', strtotime($notification['created_at'])) ?></small>
                                            <p class="mb-1"><?= htmlspecialchars($notification['message']) ?></p>
                                        </div>
                                        <a href="schedule.php?mark_read=<?= $notification['notification_id'] ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                           <i class="fas fa-check"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <a class="dropdown-item text-center" href="#">
                                <i class="fas fa-check-circle text-success"></i> All caught up!
                            </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="notifications.php">
                            View All Notifications
                        </a>
                    </div>
                </div>
                
                <!-- User Profile Dropdown -->
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <?php if (!empty($teacher['profile_image'])): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($teacher['profile_image']) ?>" 
                                 class="rounded-circle" width="40" height="40">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($teacher['full_name']) ?>&background=003366&color=fff" 
                                 class="rounded-circle" width="40" height="40">
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
        
        <!-- Department Head Information -->
        <?php if ($department_head): ?>
        <div class="department-head-info">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <?php if (!empty($department_head['profile_image'])): ?>
                        <img src="../uploads/profiles/<?= htmlspecialchars($department_head['profile_image']) ?>" 
                             class="head-avatar">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($department_head['full_name']) ?>&background=fff&color=003366" 
                             class="head-avatar">
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1">
                    <h5 class="mb-1">Schedule Managed By Department Head</h5>
                    <p class="mb-1">
                        <i class="fas fa-user-tie me-1"></i>
                        <strong><?= htmlspecialchars($department_head['full_name']) ?></strong>
                    </p>
                    <p class="mb-0">
                        <i class="fas fa-envelope me-1"></i>
                        <?= htmlspecialchars($department_head['email']) ?>
                    </p>
                </div>
                <div class="text-end">
                    <a href="mailto:<?= htmlspecialchars($department_head['email']) ?>" class="btn btn-light btn-sm">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                    <button class="btn btn-outline-light btn-sm ms-2" onclick="reportScheduleIssue()">
                        <i class="fas fa-flag"></i> Report Issue
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Week Navigation -->
        <div class="chart-container">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-calendar me-2" style="color: var(--ddu-blue);"></i>
                    Weekly Schedule
                </h5>
                
                <div class="btn-group">
                    <a href="schedule.php?week=<?= date('Y-m-d', strtotime($week_start . ' -7 days')) ?>" 
                       class="btn btn-outline-primary btn-sm">
                       <i class="fas fa-chevron-left"></i> Previous Week
                    </a>
                    <a href="schedule.php" class="btn btn-primary btn-sm">
                       Current Week
                    </a>
                    <a href="schedule.php?week=<?= date('Y-m-d', strtotime($week_start . ' +7 days')) ?>" 
                       class="btn btn-outline-primary btn-sm">
                       Next Week <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            
            <div class="text-center mb-4">
                <h4><?= $week_start_date->format('F j, Y') ?> - <?= $week_end_date->format('F j, Y') ?></h4>
                <p class="text-muted">Week <?= $week_start_date->format('W') ?> of <?= $week_start_date->format('Y') ?></p>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-total">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $total_classes ?></h2>
                                <p class="mb-0">Total Classes/Week</p>
                            </div>
                            <i class="fas fa-calendar-alt stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-today">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= count($today_schedule) ?></h2>
                                <p class="mb-0">Today's Classes</p>
                            </div>
                            <i class="fas fa-calendar-day stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= count($unique_courses) ?></h2>
                                <p class="mb-0">Assigned Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-notifications">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $unread_notifications ?></h2>
                                <p class="mb-0">New Notifications</p>
                            </div>
                            <i class="fas fa-bell stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Today's Schedule Highlight -->
        <div class="chart-container mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-calendar-day me-2" style="color: var(--ddu-blue);"></i>
                    Today's Schedule
                    <span class="badge bg-primary ms-2"><?= count($today_schedule) ?></span>
                </h5>
                <div class="badge bg-warning text-dark">
                    <i class="fas fa-clock me-1"></i> <?= date('l, F j, Y') ?>
                </div>
            </div>
            
            <?php if (count($today_schedule) > 0): ?>
                <div class="row">
                    <?php 
                    $current_time = date('H:i:s');
                    foreach ($today_schedule as $class): 
                        $is_current = ($current_time >= $class['start_time'] && $current_time <= $class['end_time']);
                        $session_badge_class = '';
                        switch($class['session_type']) {
                            case 'lecture': $session_badge_class = 'lecture-badge'; break;
                            case 'lab': $session_badge_class = 'lab-badge'; break;
                            case 'tutorial': $session_badge_class = 'tutorial-badge'; break;
                            case 'exam': $session_badge_class = 'exam-badge'; break;
                        }
                    ?>
                        <div class="col-lg-6 mb-3">
                            <div class="time-slot position-relative <?= $is_current ? 'current-time' : '' ?>">
                                <?php if ($session_badge_class): ?>
                                    <span class="badge session-badge <?= $session_badge_class ?>">
                                        <?= ucfirst($class['session_type']) ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($is_current): ?>
                                    <span class="now-badge">NOW</span>
                                <?php endif; ?>
                                
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="mb-1"><?= htmlspecialchars($class['course_name']) ?></h5>
                                        <p class="text-muted mb-1">
                                            <i class="fas fa-book me-1"></i>
                                            <?= htmlspecialchars($class['course_code']) ?>
                                        </p>
                                        <p class="mb-2">
                                            <i class="fas fa-clock me-1"></i>
                                            <strong>
                                                <?= date('h:i A', strtotime($class['start_time'])) ?> - 
                                                <?= date('h:i A', strtotime($class['end_time'])) ?>
                                            </strong>
                                        </p>
                                        <p class="mb-0">
                                            <i class="fas fa-door-open me-1"></i>
                                            <?= htmlspecialchars($class['room_number'] ?? 'Room not specified') ?>
                                            <?php if (!empty($class['building'])): ?>
                                                (<?= htmlspecialchars($class['building']) ?>)
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!empty($class['created_by_name'])): ?>
                                            <p class="mb-0 mt-2">
                                                <small class="text-muted">
                                                    <i class="fas fa-user-tie"></i>
                                                    Scheduled by: <?= htmlspecialchars($class['created_by_name']) ?>
                                                </small>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <div class="btn-group-vertical">
                                            <a href="mark_attendance.php?course_id=<?= $class['course_id'] ?>&schedule_id=<?= $class['schedule_id'] ?>" 
                                               class="btn btn-success btn-sm mb-2">
                                               <i class="fas fa-clipboard-check me-1"></i> Mark Attendance
                                            </a>
                                            <a href="course_details.php?id=<?= $class['course_id'] ?>" 
                                               class="btn btn-primary btn-sm">
                                               <i class="fas fa-eye me-1"></i> View Course
                                            </a>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($is_current): ?>
                                    <div class="mt-3">
                                        <div class="progress" style="height: 10px;">
                                            <?php 
                                            $class_duration = strtotime($class['end_time']) - strtotime($class['start_time']);
                                            $time_elapsed = strtotime($current_time) - strtotime($class['start_time']);
                                            $percentage = ($time_elapsed / $class_duration) * 100;
                                            ?>
                                            <div class="progress-bar bg-danger" style="width: <?= min($percentage, 100) ?>%"></div>
                                        </div>
                                        <small class="text-muted">Class in progress (<?= round($percentage) ?>% complete)</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-coffee fa-3x text-muted mb-3"></i>
                    <h4>No Classes Today</h4>
                    <p class="text-muted">Enjoy your free day! No classes scheduled for today.</p>
                    <p class="text-muted">Contact department head for schedule updates.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Weekly Schedule -->
        <div class="chart-container mt-4">
            <h5 class="mb-4">
                <i class="fas fa-calendar-week me-2" style="color: var(--ddu-blue);"></i>
                Weekly Schedule
            </h5>
            
            <div class="row">
                <?php 
                $days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                foreach ($days_of_week as $day): 
                    $is_today = ($day == $today_day);
                    $day_date = clone $week_start_date;
                    $day_offset = array_search($day, $days_of_week);
                    $day_date->modify("+$day_offset days");
                ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="day-header <?= $is_today ? 'today-highlight' : '' ?>">
                            <h5 class="mb-0">
                                <?= $day ?>
                                <?php if ($is_today): ?>
                                    <span class="badge bg-warning">Today</span>
                                <?php endif; ?>
                            </h5>
                            <small><?= $day_date->format('M j') ?></small>
                            <div class="mt-1">
                                <small>
                                    <i class="fas fa-chalkboard-teacher"></i> 
                                    <?= count($schedule_by_day[$day] ?? []) ?> classes
                                </small>
                            </div>
                        </div>
                        
                        <?php if (!empty($schedule_by_day[$day])): ?>
                            <?php foreach ($schedule_by_day[$day] as $class): 
                                $session_badge_class = '';
                                switch($class['session_type']) {
                                    case 'lecture': $session_badge_class = 'lecture-badge'; break;
                                    case 'lab': $session_badge_class = 'lab-badge'; break;
                                    case 'tutorial': $session_badge_class = 'tutorial-badge'; break;
                                    case 'exam': $session_badge_class = 'exam-badge'; break;
                                }
                            ?>
                                <div class="time-slot mb-2 position-relative">
                                    <?php if ($session_badge_class): ?>
                                        <span class="badge session-badge <?= $session_badge_class ?>">
                                            <?= ucfirst($class['session_type']) ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($class['course_name']) ?></h6>
                                            <p class="text-muted mb-1 small"><?= htmlspecialchars($class['course_code']) ?></p>
                                            <p class="mb-1 small">
                                                <i class="fas fa-clock"></i> 
                                                <?= date('h:i A', strtotime($class['start_time'])) ?> - 
                                                <?= date('h:i A', strtotime($class['end_time'])) ?>
                                            </p>
                                            <p class="mb-0 small">
                                                <i class="fas fa-door-open"></i> 
                                                <?= htmlspecialchars($class['room_number'] ?? 'N/A') ?>
                                            </p>
                                        </div>
                                        <div class="text-end">
                                            <div class="btn-group-vertical">
                                                <a href="mark_attendance.php?course_id=<?= $class['course_id'] ?>&schedule_id=<?= $class['schedule_id'] ?>" 
                                                   class="btn btn-sm btn-outline-success mb-1">
                                                   <i class="fas fa-clipboard-check"></i>
                                                </a>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="setReminder('<?= $day ?>', '<?= $class['course_name'] ?>', '<?= date('h:i A', strtotime($class['start_time'])) ?>')">
                                                    <i class="fas fa-bell"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                <p class="mb-0">No classes scheduled</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Upcoming Classes -->
        <div class="chart-container mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-clock me-2" style="color: var(--ddu-blue);"></i>
                    Upcoming Classes (Next 7 Days)
                </h5>
                <span class="badge bg-primary"><?= count($upcoming_classes) ?> classes</span>
            </div>
            
            <?php if (!empty($upcoming_classes)): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Course</th>
                                <th>Time</th>
                                <th>Room</th>
                                <th>Session</th>
                                <th>Scheduled By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_classes as $class): 
                                $is_today = (date('Y-m-d', strtotime($class['date'])) == date('Y-m-d'));
                                $is_tomorrow = (date('Y-m-d', strtotime($class['date'])) == date('Y-m-d', strtotime('+1 day')));
                            ?>
                                <tr class="<?= $is_today ? 'table-warning' : ($is_tomorrow ? 'table-info' : '') ?>">
                                    <td>
                                        <strong><?= $class['date_display'] ?></strong><br>
                                        <small>
                                            <?php if ($is_today): ?>
                                                <span class="badge bg-warning text-dark">Today</span>
                                            <?php elseif ($is_tomorrow): ?>
                                                <span class="badge bg-info">Tomorrow</span>
                                            <?php else: ?>
                                                <?= $class['weekday'] ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($class['course_name']) ?></strong><br>
                                        <small><?= htmlspecialchars($class['course_code']) ?></small>
                                    </td>
                                    <td>
                                        <?= date('h:i A', strtotime($class['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($class['end_time'])) ?>
                                    </td>
                                    <td><?= htmlspecialchars($class['room_number'] ?? 'N/A') ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $class['session_type'] == 'lecture' ? 'primary' : 
                                            ($class['session_type'] == 'lab' ? 'success' : 
                                            ($class['session_type'] == 'tutorial' ? 'warning' : 'danger')) 
                                        ?>">
                                            <?= ucfirst($class['session_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= htmlspecialchars($class['created_by_name'] ?? 'Dept. Head') ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="mark_attendance.php?course_id=<?= $class['course_id'] ?>&schedule_id=<?= $class['schedule_id'] ?>" 
                                               class="btn btn-success btn-sm">
                                               <i class="fas fa-clipboard-check"></i>
                                            </a>
                                            <button class="btn btn-primary btn-sm" 
                                                    onclick="setClassReminder('<?= $class['date'] ?>', '<?= $class['course_name'] ?>', '<?= date('h:i A', strtotime($class['start_time'])) ?>')">
                                               <i class="fas fa-bell"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                    <h4>No Upcoming Classes</h4>
                    <p class="text-muted">No classes scheduled for the next 7 days.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="chart-container mt-4">
            <h5 class="mb-4">
                <i class="fas fa-bolt me-2" style="color: var(--ddu-gold);"></i>
                Quick Actions
            </h5>
            <div class="row">
                <div class="col-md-3 col-sm-6 mb-3">
                    <a href="mark_attendance.php" class="btn btn-success w-100">
                        <i class="fas fa-clipboard-check"></i><br>Mark Attendance
                    </a>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <a href="courses.php" class="btn btn-primary w-100">
                        <i class="fas fa-book"></i><br>View Courses
                    </a>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <button class="btn btn-info w-100" onclick="printSchedule()">
                        <i class="fas fa-print"></i><br>Print Schedule
                    </button>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                    <button class="btn btn-warning w-100" onclick="exportSchedule()">
                        <i class="fas fa-download"></i><br>Export Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Set reminder for a class
        function setReminder(day, courseName, time) {
            if (confirm(`Set reminder for ${courseName} on ${day} at ${time}?`)) {
                alert(`Reminder set for ${courseName} on ${day} at ${time}`);
                // In real implementation: Save reminder to database or localStorage
            }
        }
        
        // Set class reminder with date
        function setClassReminder(date, courseName, time) {
            const formattedDate = new Date(date).toLocaleDateString('en-US', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            if (confirm(`Set reminder for ${courseName} on ${formattedDate} at ${time}?`)) {
                alert(`Reminder set for ${courseName} on ${formattedDate} at ${time}`);
                // In real implementation: Save reminder to database
            }
        }
        
        // Report schedule issue
        function reportScheduleIssue() {
            const issue = prompt('Please describe the schedule issue:');
            if (issue) {
                // In real implementation: Send AJAX request to save issue
                alert('Issue reported successfully. Department head will be notified.');
            }
        }
        
        // Print schedule
        function printSchedule() {
            window.print();
        }
        
        // Export schedule
        function exportSchedule() {
            alert('Exporting schedule as PDF...');
            // In real implementation: Generate and download PDF
        }
        
        // Auto-refresh page every 60 seconds to update current class status
        let refreshTimer = setTimeout(function() {
            location.reload();
        }, 60000);
        
        // Clear timer on page unload
        window.addEventListener('beforeunload', function() {
            clearTimeout(refreshTimer);
        });
        
        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Highlight current time in schedule
            highlightCurrentClasses();
            
            // Add countdown for upcoming classes
            updateClassCountdowns();
            
            // Set up periodic updates for current class progress bars
            setInterval(updateProgressBars, 30000); // Update every 30 seconds
            
            // Check for new notifications every 2 minutes
            setInterval(checkNewNotifications, 120000);
        });
        
        // Highlight current classes
        function highlightCurrentClasses() {
            const currentTime = new Date();
            const currentHour = currentTime.getHours();
            const currentMinute = currentTime.getMinutes();
            const currentTimeStr = `${currentHour.toString().padStart(2, '0')}:${currentMinute.toString().padStart(2, '0')}:00`;
            
            document.querySelectorAll('.time-slot').forEach(slot => {
                const timeText = slot.textContent;
                const timeMatch = timeText.match(/(\d{1,2}:\d{2}\s?[AP]M)/g);
                if (timeMatch && timeMatch.length >= 2) {
                    const startTime = convertTo24Hour(timeMatch[0]);
                    const endTime = convertTo24Hour(timeMatch[1]);
                    
                    if (currentTimeStr >= startTime && currentTimeStr <= endTime) {
                        slot.classList.add('current-time');
                        
                        // Add "NOW" badge if not already present
                        if (!slot.querySelector('.now-badge')) {
                            const nowBadge = document.createElement('span');
                            nowBadge.className = 'now-badge';
                            nowBadge.textContent = 'NOW';
                            slot.querySelector('.d-flex').appendChild(nowBadge);
                        }
                    }
                }
            });
        }
        
        // Convert 12-hour time to 24-hour time
        function convertTo24Hour(time12h) {
            const [time, modifier] = time12h.split(/(?=[AP]M)/);
            let [hours, minutes] = time.split(':');
            
            if (hours === '12') {
                hours = '00';
            }
            
            if (modifier === 'PM') {
                hours = parseInt(hours, 10) + 12;
            }
            
            return `${hours.toString().padStart(2, '0')}:${minutes}:00`;
        }
        
        // Update progress bars for current classes
        function updateProgressBars() {
            const currentTime = new Date();
            
            document.querySelectorAll('.current-time .progress-bar').forEach(progressBar => {
                const slot = progressBar.closest('.time-slot');
                const timeText = slot.textContent;
                const timeMatch = timeText.match(/(\d{1,2}:\d{2}\s?[AP]M)/g);
                
                if (timeMatch && timeMatch.length >= 2) {
                    const startTime = new Date();
                    const endTime = new Date();
                    
                    const startParts = convertTo24Hour(timeMatch[0]).split(':');
                    const endParts = convertTo24Hour(timeMatch[1]).split(':');
                    
                    startTime.setHours(startParts[0], startParts[1], 0);
                    endTime.setHours(endParts[0], endParts[1], 0);
                    
                    const totalDuration = endTime - startTime;
                    const elapsed = currentTime - startTime;
                    const percentage = Math.min((elapsed / totalDuration) * 100, 100);
                    
                    progressBar.style.width = `${percentage}%`;
                    
                    // Update label
                    const label = slot.querySelector('.text-muted');
                    if (label) {
                        label.textContent = `Class in progress (${Math.round(percentage)}% complete)`;
                    }
                }
            });
        }
        
        // Update countdown for upcoming classes
        function updateClassCountdowns() {
            const now = new Date();
            
            document.querySelectorAll('tbody tr').forEach(row => {
                const dateCell = row.cells[0];
                const timeCell = row.cells[2];
                
                if (dateCell && timeCell) {
                    const dateText = dateCell.querySelector('strong').textContent;
                    const year = now.getFullYear();
                    const [month, day] = dateText.split(' ');
                    const timeText = timeCell.textContent.split('-')[0].trim();
                    
                    // Parse the date and time
                    const classDateTime = new Date(`${month} ${day}, ${year} ${timeText}`);
                    
                    // If class is in the future, add countdown
                    if (classDateTime > now) {
                        const timeDiff = classDateTime - now;
                        const hoursLeft = Math.floor(timeDiff / (1000 * 60 * 60));
                        const minutesLeft = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
                        
                        if (hoursLeft < 24) {
                            const countdownSpan = document.createElement('span');
                            countdownSpan.className = 'badge bg-info ms-2';
                            countdownSpan.innerHTML = `<i class="fas fa-clock"></i> ${hoursLeft}h ${minutesLeft}m`;
                            dateCell.appendChild(countdownSpan);
                        }
                    }
                }
            });
        }
        
        // Check for new notifications
        function checkNewNotifications() {
            // In real implementation: Make AJAX call to check for new notifications
            // For now, just reload the page if there are new notifications
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.new_notifications > 0) {
                        location.reload();
                    }
                });
        }
        
        // Add to calendar function
        function addToCalendar(eventName, date, startTime, endTime, location, description) {
            // Create iCalendar content
            const startDateTime = new Date(`${date}T${startTime}`).toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
            const endDateTime = new Date(`${date}T${endTime}`).toISOString().replace(/[-:]/g, '').split('.')[0] + 'Z';
            
            const icsContent = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'CALSCALE:GREGORIAN',
                'BEGIN:VEVENT',
                `SUMMARY:${eventName}`,
                `DTSTART:${startDateTime}`,
                `DTEND:${endDateTime}`,
                `LOCATION:${location}`,
                `DESCRIPTION:${description}`,
                'END:VEVENT',
                'END:VCALENDAR'
            ].join('\r\n');
            
            // Create download link
            const blob = new Blob([icsContent], { type: 'text/calendar' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${eventName.replace(/\s+/g, '_')}.ics`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>