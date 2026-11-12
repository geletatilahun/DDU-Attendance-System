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
   GET FILTER PARAMETERS
====================================================== */
$course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$status = isset($_GET['status']) ? $_GET['status'] : '';

/* ======================================================
   FETCH TEACHER'S COURSES FOR FILTER
====================================================== */
$courses_sql = "
    SELECT c.course_id, c.course_code, c.course_name
    FROM course_teachers ct
    JOIN courses c ON ct.course_id = c.course_id
    WHERE ct.teacher_id = ?
    ORDER BY c.course_name
";

$courses_stmt = $conn->prepare($courses_sql);
if ($courses_stmt) {
    $courses_stmt->bind_param("i", $teacher_id);
    $courses_stmt->execute();
    $courses_result = $courses_stmt->get_result();
} else {
    $courses_result = null;
}

/* ======================================================
   BUILD ATTENDANCE QUERY WITH ERROR HANDLING
====================================================== */
$where_conditions = ["a.teacher_id = ?"];
$params = [$teacher_id];
$param_types = "i";

// Build WHERE conditions based on filters
if ($course_id > 0) {
    $where_conditions[] = "a.course_id = ?";
    $params[] = $course_id;
    $param_types .= "i";
}

if ($month) {
    $where_conditions[] = "DATE_FORMAT(a.attendance_date, '%Y-%m') = ?";
    $params[] = $month;
    $param_types .= "s";
}

if ($status) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status;
    $param_types .= "s";
}

// Build the WHERE clause
$where_clause = "";
if (!empty($where_conditions)) {
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
}

// Try different queries until one works
$queries = [
    // Query with course names
    "SELECT a.*, c.course_code, c.course_name
     FROM attendance a
     JOIN courses c ON a.course_id = c.course_id
     $where_clause
     ORDER BY a.attendance_date DESC
     LIMIT 50",
    
    // Simple query without filters
    "SELECT a.*, c.course_code, c.course_name
     FROM attendance a
     JOIN courses c ON a.course_id = c.course_id
     WHERE a.teacher_id = ?
     ORDER BY a.attendance_date DESC
     LIMIT 50",
    
    // Most basic query
    "SELECT * FROM attendance WHERE teacher_id = ? ORDER BY attendance_date DESC LIMIT 50"
];

$attendance_result = null;
$last_error = "";

foreach ($queries as $sql) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // Bind parameters based on the number of placeholders
        $placeholders = substr_count($sql, '?');
        if ($placeholders == count($params)) {
            $stmt->bind_param($param_types, ...$params);
        } else if ($placeholders == 1) {
            $stmt->bind_param("i", $teacher_id);
        }
        
        if ($stmt->execute()) {
            $attendance_result = $stmt->get_result();
            break;
        } else {
            $last_error = $stmt->error;
        }
    } else {
        $last_error = $conn->error;
    }
}

if (!$attendance_result) {
    // Create empty result set
    $attendance_result = new class {
        public $num_rows = 0;
        public function fetch_assoc() { return false; }
        public function data_seek($offset) { return false; }
    };
}

/* ======================================================
   CALCULATE STATISTICS
====================================================== */
$stats = [
    'total_records' => 0,
    'total_present' => 0,
    'total_absent' => 0,
    'total_courses' => 0
];

// Try to get statistics
$stats_sql = "
    SELECT 
        COUNT(*) as total_records,
        SUM(total_present) as total_present,
        SUM(total_absent) as total_absent,
        COUNT(DISTINCT course_id) as total_courses
    FROM attendance
    WHERE teacher_id = ?
";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $teacher_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    if ($stats_result) {
        $stats_row = $stats_result->fetch_assoc();
        if ($stats_row) {
            $stats = $stats_row;
        }
    }
}

// If statistics query failed, calculate from results
if ($stats['total_records'] == 0 && $attendance_result->num_rows > 0) {
    $stats['total_records'] = $attendance_result->num_rows;
    $courses = [];
    $attendance_result->data_seek(0); // Reset pointer
    while ($row = $attendance_result->fetch_assoc()) {
        $stats['total_present'] += $row['total_present'] ?? 0;
        $stats['total_absent'] += $row['total_absent'] ?? 0;
        $courses[$row['course_id']] = true;
    }
    $stats['total_courses'] = count($courses);
    $attendance_result->data_seek(0); // Reset pointer again
}

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "Attendance Reports";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - DDU Attendance System</title>
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
        
        .bg-records { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-present { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .bg-absent { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .bg-courses { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }
        
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
        
        .attendance-row:hover {
            background-color: #f8f9fa;
            cursor: pointer;
        }
        
        .progress-sm {
            height: 20px;
            border-radius: 10px;
        }
        
        .filter-card {
            border-left: 4px solid var(--ddu-blue);
        }
        
        .export-btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
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
            <a href="attendance_reports.php" class="nav-link active">
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
                    <i class="fas fa-chart-bar"></i> Attendance Reports
                </h4>
                <small class="text-muted">Welcome back, <?= htmlspecialchars($teacher['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-4" style="color: var(--ddu-blue);"></i>
                        <?php if ($stats['total_records'] > 0): ?>
                            <span class="notification-badge"><?= $stats['total_records'] > 9 ? '9+' : $stats['total_records'] ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Reports Summary</h6>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-file-alt text-primary"></i> <?= $stats['total_records'] ?> attendance records
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-user-check text-success"></i> <?= $stats['total_present'] ?> total present
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-user-times text-danger"></i> <?= $stats['total_absent'] ?> total absent
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
        
        <!-- Filter Section -->
        <div class="chart-container filter-card">
            <h5 class="mb-4">
                <i class="fas fa-filter me-2" style="color: var(--ddu-blue);"></i>
                Filter Reports
            </h5>
            
            <form method="get" action="attendance_reports.php" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Course</label>
                    <select name="course_id" class="form-select">
                        <option value="">All Courses</option>
                        <?php if ($courses_result && $courses_result->num_rows > 0): ?>
                            <?php while ($course = $courses_result->fetch_assoc()): ?>
                                <option value="<?= $course['course_id'] ?>" 
                                    <?= $course['course_id'] == $course_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($course['course_code']) ?> - <?= htmlspecialchars($course['course_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($month) ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="marked" <?= $status == 'marked' ? 'selected' : '' ?>>Marked</option>
                        <option value="pending" <?= $status == 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i> Apply
                    </button>
                </div>
            </form>
            
            <div class="mt-3">
                <a href="attendance_reports.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-redo me-1"></i> Clear Filters
                </a>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mt-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-records">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_records']) ?></h2>
                                <p class="mb-0">Total Records</p>
                            </div>
                            <i class="fas fa-file-alt stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-present">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_present']) ?></h2>
                                <p class="mb-0">Total Present</p>
                            </div>
                            <i class="fas fa-user-check stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-absent">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_absent']) ?></h2>
                                <p class="mb-0">Total Absent</p>
                            </div>
                            <i class="fas fa-user-times stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-courses">
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
        </div>
        
        <!-- Attendance Reports Table -->
        <div class="chart-container mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-clipboard-list me-2" style="color: var(--ddu-blue);"></i>
                    Attendance Records
                    <span class="badge bg-primary ms-2"><?= $attendance_result->num_rows ?></span>
                </h5>
                
                <?php if ($attendance_result->num_rows > 0): ?>
                <div class="btn-group">
                    <button class="btn btn-outline-primary btn-sm" onclick="sortTable('date')">
                        <i class="fas fa-calendar-alt me-1"></i> Sort by Date
                    </button>
                    <button class="btn btn-outline-primary btn-sm" onclick="sortTable('course')">
                        <i class="fas fa-book me-1"></i> Sort by Course
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($attendance_result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="attendanceTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Course</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($record = $attendance_result->fetch_assoc()): 
                                $present_count = $record['total_present'] ?? 0;
                                $absent_count = $record['total_absent'] ?? 0;
                                $total_students = $present_count + $absent_count;
                                $present_percentage = $total_students > 0 ? round(($present_count / $total_students) * 100, 1) : 0;
                                
                                // Determine progress bar color
                                if ($present_percentage >= 80) {
                                    $progress_class = 'bg-success';
                                } elseif ($present_percentage >= 60) {
                                    $progress_class = 'bg-warning';
                                } else {
                                    $progress_class = 'bg-danger';
                                }
                            ?>
                                <tr class="attendance-row">
                                    <td>
                                        <strong><?= date('M j, Y', strtotime($record['attendance_date'])) ?></strong><br>
                                        <small class="text-muted"><?= date('l', strtotime($record['attendance_date'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if (isset($record['course_name'])): ?>
                                            <strong><?= htmlspecialchars($record['course_name']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($record['course_code'] ?? 'N/A') ?></small>
                                        <?php else: ?>
                                            <strong>Course ID: <?= $record['course_id'] ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?= $present_count ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger"><?= $absent_count ?></span>
                                    </td>
                                    <td>
                                        <div class="progress progress-sm">
                                            <div class="progress-bar <?= $progress_class ?>" 
                                                 style="width: <?= $present_percentage ?>%">
                                                <?= $present_percentage ?>%
                                            </div>
                                        </div>
                                        <small class="text-muted"><?= $total_students ?> students</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= ($record['status'] ?? 'marked') == 'marked' ? 'success' : 'warning' ?>">
                                            <i class="fas fa-<?= ($record['status'] ?? 'marked') == 'marked' ? 'check' : 'clock' ?> me-1"></i>
                                            <?= ucfirst($record['status'] ?? 'marked') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="viewDetails(<?= $record['attendance_id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-outline-success" 
                                                    onclick="printReport(<?= $record['attendance_id'] ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item disabled">
                            <a class="page-link" href="#" tabindex="-1">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        </li>
                        <li class="page-item active"><a class="page-link" href="#">1</a></li>
                        <li class="page-item"><a class="page-link" href="#">2</a></li>
                        <li class="page-item"><a class="page-link" href="#">3</a></li>
                        <li class="page-item">
                            <a class="page-link" href="#">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <i class="fas fa-chart-bar fa-4x text-muted mb-4"></i>
                    <h4>No Attendance Records Found</h4>
                    <p class="text-muted mb-4">
                        <?php if ($course_id > 0 || $month || $status): ?>
                            No records match your filter criteria. Try changing your filters.
                        <?php else: ?>
                            No attendance records found. Start by marking attendance for your classes.
                        <?php endif; ?>
                    </p>
                    
                    <div class="row mt-4">
                        <div class="col-md-4 offset-md-2 mb-3">
                            <a href="mark_attendance.php" class="btn btn-primary w-100">
                                <i class="fas fa-clipboard-check me-2"></i> Mark Attendance
                            </a>
                        </div>
                        <div class="col-md-4 mb-3">
                            <a href="attendance_reports.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-redo me-2"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Export Options -->
        <?php if ($attendance_result->num_rows > 0): ?>
        <div class="chart-container mt-4">
            <h5 class="mb-4">
                <i class="fas fa-download me-2" style="color: var(--ddu-blue);"></i>
                Export Reports
            </h5>
            
            <div class="row">
                <div class="col-md-3 mb-3">
                    <a href="#" class="btn btn-outline-primary export-btn w-100" onclick="exportReport('csv')">
                        <i class="fas fa-file-csv fa-2x mb-2 d-block"></i>
                        Export as CSV
                    </a>
                </div>
                
                <div class="col-md-3 mb-3">
                    <a href="#" class="btn btn-outline-success export-btn w-100" onclick="exportReport('excel')">
                        <i class="fas fa-file-excel fa-2x mb-2 d-block"></i>
                        Export as Excel
                    </a>
                </div>
                
                <div class="col-md-3 mb-3">
                    <a href="#" class="btn btn-outline-danger export-btn w-100" onclick="exportReport('pdf')">
                        <i class="fas fa-file-pdf fa-2x mb-2 d-block"></i>
                        Export as PDF
                    </a>
                </div>
                
                <div class="col-md-3 mb-3">
                    <button class="btn btn-outline-secondary export-btn w-100" onclick="window.print()">
                        <i class="fas fa-print fa-2x mb-2 d-block"></i>
                        Print Report
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Summary Charts (Placeholder) -->
        <?php if ($attendance_result->num_rows > 0): ?>
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="chart-container">
                    <h5 class="mb-4">
                        <i class="fas fa-chart-line me-2" style="color: var(--ddu-blue);"></i>
                        Monthly Overview
                    </h5>
                    <div id="monthlyChart" style="height: 250px;">
                        <div class="text-center py-5">
                            <i class="fas fa-chart-bar fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Monthly chart would appear here</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="chart-container">
                    <h5 class="mb-4">
                        <i class="fas fa-chart-pie me-2" style="color: var(--ddu-blue);"></i>
                        Course Distribution
                    </h5>
                    <div id="courseChart" style="height: 250px;">
                        <div class="text-center py-5">
                            <i class="fas fa-pie-chart fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Course distribution chart would appear here</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
        
        // View attendance details
        function viewDetails(attendanceId) {
            alert('Viewing details for attendance ID: ' + attendanceId);
            // In real implementation: window.open('attendance_details.php?id=' + attendanceId, '_blank');
        }
        
        // Print report
        function printReport(attendanceId) {
            if (confirm('Print attendance report?')) {
                alert('Printing report for attendance ID: ' + attendanceId);
                // In real implementation: window.open('print_attendance.php?id=' + attendanceId, '_blank');
            }
        }
        
        // Export report
        function exportReport(format) {
            const courseId = <?= $course_id ?>;
            const month = "<?= $month ?>";
            const status = "<?= $status ?>";
            
            alert('Exporting report as ' + format.toUpperCase());
            // In real implementation: window.location.href = 'export_report.php?format=' + format + '&course_id=' + courseId + '&month=' + month + '&status=' + status;
        }
        
        // Sort table
        function sortTable(column) {
            const table = document.getElementById('attendanceTable');
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            rows.sort((a, b) => {
                let aValue, bValue;
                
                if (column === 'date') {
                    aValue = new Date(a.cells[0].querySelector('strong').textContent);
                    bValue = new Date(b.cells[0].querySelector('strong').textContent);
                    return aValue - bValue;
                } else if (column === 'course') {
                    aValue = a.cells[1].querySelector('strong').textContent.toLowerCase();
                    bValue = b.cells[1].querySelector('strong').textContent.toLowerCase();
                    return aValue.localeCompare(bValue);
                }
                return 0;
            });
            
            // Reorder rows
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Add search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchBox = document.createElement('input');
            searchBox.type = 'text';
            searchBox.className = 'form-control mb-3';
            searchBox.placeholder = 'Search by course name or date...';
            searchBox.id = 'reportSearch';
            
            const tableContainer = document.querySelector('.table-responsive');
            if (tableContainer) {
                tableContainer.parentElement.insertBefore(searchBox, tableContainer);
                
                searchBox.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const rowText = row.textContent.toLowerCase();
                        if (rowText.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>