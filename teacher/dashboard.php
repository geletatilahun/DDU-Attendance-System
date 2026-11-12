<?php
session_start();

require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

/* ======================================================
   ACCESS CONTROL
====================================================== */
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
        d.department_name
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
   FETCH ASSIGNED COURSES
====================================================== */
$courses_sql = "
    SELECT 
        c.course_id,
        c.course_name,
        c.course_code,
        c.semester,
        COUNT(DISTINCT cs.student_id) AS student_count
    FROM course_teachers ct
    JOIN courses c ON ct.course_id = c.course_id
    LEFT JOIN course_students cs ON c.course_id = cs.course_id
    WHERE ct.teacher_id = ?
    GROUP BY c.course_id
    ORDER BY c.semester, c.course_name
";

$courses_stmt = $conn->prepare($courses_sql);
if (!$courses_stmt) {
    die("Courses query failed: " . $conn->error);
}

$courses_stmt->bind_param("i", $teacher_id);
$courses_stmt->execute();
$courses_result = $courses_stmt->get_result();

/* ======================================================
   TODAY'S CLASSES
====================================================== */
$today_day = date('l');

$today_classes_sql = "
    SELECT sch.*, c.course_name, c.course_code
    FROM class_schedule sch
    JOIN courses c ON sch.course_id = c.course_id
    JOIN course_teachers ct ON c.course_id = ct.course_id
    WHERE ct.teacher_id = ?
      AND sch.day_of_week = ?
      AND sch.is_active = 1
    ORDER BY sch.start_time
";

$today_classes_stmt = $conn->prepare($today_classes_sql);
if (!$today_classes_stmt) {
    die("Today's classes query failed: " . $conn->error);
}

$today_classes_stmt->bind_param("is", $teacher_id, $today_day);
$today_classes_stmt->execute();
$today_classes_result = $today_classes_stmt->get_result();

/* ======================================================
   UPCOMING CLASSES (NEXT 7 DAYS)
====================================================== */
$upcoming_sql = "
    SELECT sch.*, c.course_name, c.course_code
    FROM class_schedule sch
    JOIN courses c ON sch.course_id = c.course_id
    JOIN course_teachers ct ON c.course_id = ct.course_id
    WHERE ct.teacher_id = ?
      AND sch.is_active = 1
    ORDER BY sch.day_of_week, sch.start_time
    LIMIT 10
";

$upcoming_stmt = $conn->prepare($upcoming_sql);
if (!$upcoming_stmt) {
    die("Upcoming classes query failed: " . $conn->error);
}

$upcoming_stmt->bind_param("i", $teacher_id);
$upcoming_stmt->execute();
$upcoming_result = $upcoming_stmt->get_result();

/* ======================================================
   PENDING ATTENDANCE
====================================================== */
$pending_sql = "
    SELECT COUNT(*) AS pending_count
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    JOIN course_teachers ct ON c.course_id = ct.course_id
    WHERE ct.teacher_id = ?
      AND a.status = 'pending'
";

$pending_stmt = $conn->prepare($pending_sql);
if (!$pending_stmt) {
    die("Pending attendance query failed: " . $conn->error);
}

$pending_stmt->bind_param("i", $teacher_id);
$pending_stmt->execute();
$pending_result = $pending_stmt->get_result();
$pending = $pending_result->fetch_assoc();

/* ======================================================
   TOTAL STUDENTS
====================================================== */
$total_students_sql = "
    SELECT COUNT(DISTINCT cs.student_id) AS total
    FROM course_teachers ct
    JOIN courses c ON ct.course_id = c.course_id
    JOIN course_students cs ON c.course_id = cs.course_id
    WHERE ct.teacher_id = ?
";

$total_stmt = $conn->prepare($total_students_sql);
if (!$total_stmt) {
    die("Total students query failed: " . $conn->error);
}

$total_stmt->bind_param("i", $teacher_id);
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total = $total_result->fetch_assoc();

/* ======================================================
   RECENT ACTIVITY (AGGREGATED VERSION)
====================================================== */
$activity_sql = "
    SELECT 
        a.attendance_date,
        a.course_id,
        c.course_name,
        c.course_code,
        COUNT(*) as total_students,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as total_present,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as total_absent,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as total_late,
        MAX(a.marked_at) as last_updated
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    JOIN course_teachers ct ON c.course_id = ct.course_id
    WHERE ct.teacher_id = ?
    GROUP BY a.attendance_date, a.course_id
    ORDER BY a.attendance_date DESC
    LIMIT 10
";

$activity_stmt = $conn->prepare($activity_sql);
if (!$activity_stmt) {
    die("Activity query failed: " . $conn->error);
}

$activity_stmt->bind_param("i", $teacher_id);
$activity_stmt->execute();
$activity_result = $activity_stmt->get_result();

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "Teacher Dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - DDU Attendance System</title>
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
        
        .bg-courses { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .bg-today { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-pending { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .bg-students { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
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
            <a href="dashboard.php" class="nav-link active">
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
            <a href="profile.php" class="nav-link">
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
                    <i class="fas fa-tachometer-alt"></i> Teacher Dashboard
                </h4>
                <small class="text-muted">Welcome back, <?= htmlspecialchars($teacher['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-4" style="color: var(--ddu-blue);"></i>
                        <?php if ($pending['pending_count'] > 0): ?>
                            <span class="notification-badge"><?= $pending['pending_count'] > 9 ? '9+' : $pending['pending_count'] ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Notifications</h6>
                        <?php if ($pending['pending_count'] > 0): ?>
                            <a class="dropdown-item" href="mark_attendance.php">
                                <i class="fas fa-clock text-warning"></i> <?= $pending['pending_count'] ?> pending attendance
                            </a>
                        <?php endif; ?>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-calendar-check text-info"></i> <?= $today_classes_result->num_rows ?> classes today
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="notifications.php">View All</a>
                    </div>
                </div>
                
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <img src="https://ui-avatars.com/api/?name=<?= urlencode($teacher['full_name']) ?>&background=003366&color=fff" 
                             class="rounded-circle" width="40" height="40">
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
        
        <!-- Statistics Cards -->
        <div class="row mt-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($courses_result->num_rows) ?></h2>
                                <p class="mb-0">Assigned Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-today">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($today_classes_result->num_rows) ?></h2>
                                <p class="mb-0">Today's Classes</p>
                            </div>
                            <i class="fas fa-calendar-day stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-pending">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($pending['pending_count'] ?? 0) ?></h2>
                                <p class="mb-0">Pending Attendance</p>
                            </div>
                            <i class="fas fa-clock stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-students">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($total['total'] ?? 0) ?></h2>
                                <p class="mb-0">Total Students</p>
                            </div>
                            <i class="fas fa-user-graduate stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Today's Classes -->
        <div class="row mt-4">
            <div class="col-lg-8">
                <div class="chart-container">
                    <h5>Today's Classes (<?= date('l, F j, Y') ?>)</h5>
                    <?php if ($today_classes_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Time</th>
                                        <th>Room</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $today_classes_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($row['course_name']) ?></strong><br>
                                                <small><?= htmlspecialchars($row['course_code']) ?></small>
                                            </td>
                                            <td>
                                                <?= date('h:i A', strtotime($row['start_time'])) ?> -
                                                <?= date('h:i A', strtotime($row['end_time'])) ?>
                                            </td>
                                            <td><?= htmlspecialchars($row['room_number'] ?? 'N/A') ?></td>
                                            <td>
                                                <a href="mark_attendance.php?course_id=<?= $row['course_id'] ?>" 
                                                   class="btn btn-success btn-sm">
                                                   <i class="fas fa-clipboard-check"></i> Mark
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center">No classes scheduled for today</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="chart-container">
                    <h5>Upcoming Classes</h5>
                    <?php if ($upcoming_result->num_rows > 0): ?>
                        <div class="list-group">
                            <?php while ($row = $upcoming_result->fetch_assoc()): ?>
                                <div class="list-group-item border-0">
                                    <div class="d-flex w-100 justify-content-between">
                                        <h6 class="mb-1"><?= htmlspecialchars($row['course_name']) ?></h6>
                                        <small><?= $row['day_of_week'] ?></small>
                                    </div>
                                    <p class="mb-1">
                                        <i class="far fa-clock"></i> 
                                        <?= date('h:i A', strtotime($row['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($row['end_time'])) ?>
                                    </p>
                                    <small>Code: <?= htmlspecialchars($row['course_code']) ?></small>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center">No upcoming classes</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <h5>Recent Attendance Activity</h5>
                    <?php if ($activity_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Status</th>
                                        <th>Present/Absent</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $activity_result->fetch_assoc()): ?>
<!-- Change the table rows -->
<tr>
    <td>
        <strong><?= htmlspecialchars($row['course_name']) ?></strong><br>
        <small><?= htmlspecialchars($row['course_code']) ?></small>
    </td>
    <td><?= date('F j, Y', strtotime($row['attendance_date'])) ?></td>
    <td>
        <span class="text-success"><?= $row['total_present'] ?> Present</span><br>
        <span class="text-danger"><?= $row['total_absent'] ?> Absent</span><br>
        <span class="text-warning"><?= $row['total_late'] ?> Late</span>
    </td>
    <td><?= $row['total_students'] ?></td>
    <td>
        <a href="attendance_details.php?course_id=<?= $row['course_id'] ?>&date=<?= $row['attendance_date'] ?>" 
           class="btn btn-sm btn-outline-primary">
           View Details
        </a>
    </td>
</tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted text-center">No recent activity</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <h5>Quick Actions</h5>
                    <div class="row">
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="mark_attendance.php" class="btn btn-primary w-100">
                                <i class="fas fa-clipboard-check"></i><br>Mark Attendance
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="courses.php" class="btn btn-success w-100">
                                <i class="fas fa-book"></i><br>View Courses
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="attendance_reports.php" class="btn btn-info w-100">
                                <i class="fas fa-chart-bar"></i><br>View Reports
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="students.php" class="btn btn-warning w-100">
                                <i class="fas fa-user-graduate"></i><br>My Students
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="schedule.php" class="btn btn-dark w-100">
                                <i class="fas fa-calendar-alt"></i><br>Schedule
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="profile.php" class="btn btn-secondary w-100">
                                <i class="fas fa-user"></i><br>My Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
    </script>
</body>
</html>