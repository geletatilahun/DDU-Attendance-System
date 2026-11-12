<?php
// attendance_reports.php - Simple working version

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Check user permissions
$allowed_roles = ['admin', 'teacher', 'department_head'];
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die('<div class="alert alert-danger">Access denied. You do not have permission to view reports.</div>');
}

// Simple database connection (no config file dependency)
function connectDB() {
    $host = 'localhost';
    $dbname = 'ddu_attendance_system';
    $username = 'root';
    $password = '';
    
    try {
        $conn = new mysqli($host, $username, $password, $dbname);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        $conn->set_charset('utf8mb4');
        return $conn;
    } catch (Exception $e) {
        die('<div class="alert alert-danger">Database connection failed: ' . $e->getMessage() . '</div>');
    }
}

$conn = connectDB();

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$report_type = $_GET['report_type'] ?? 'daily_summary';
$course_id = $_GET['course_id'] ?? 'all';
$department_id = $_GET['department_id'] ?? 'all';

// Helper functions
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

function formatTime($time) {
    return $time ? date('h:i A', strtotime($time)) : 'N/A';
}

// Fetch dropdown data
function getCourses($conn) {
    $sql = "SELECT id, course_code, course_name FROM courses WHERE status = 'active' ORDER BY course_code";
    $result = $conn->query($sql);
    $courses = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $courses[] = $row;
        }
    }
    
    return $courses;
}

function getDepartments($conn) {
    $sql = "SELECT id, department_code, department_name FROM departments ORDER BY department_name";
    $result = $conn->query($sql);
    $departments = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
    }
    
    return $departments;
}

// Generate report based on type
$report_data = [];
$summary_stats = [];

switch($report_type) {
    case 'daily_summary':
        $report_data = generateDailySummary($conn, $start_date, $end_date, $course_id, $department_id);
        $summary_stats = calculateSummaryStats($report_data);
        break;
    case 'student_attendance':
        $report_data = generateStudentAttendance($conn, $start_date, $end_date, $course_id);
        break;
    case 'course_summary':
        $report_data = generateCourseSummary($conn, $start_date, $end_date, $department_id);
        break;
}

$courses = getCourses($conn);
$departments = getDepartments($conn);

// Report generation functions
function generateDailySummary($conn, $start_date, $end_date, $course_id, $department_id) {
    $sql = "SELECT 
                DATE(a.attendance_date) as date,
                COUNT(DISTINCT a.student_id) as total_students,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                COUNT(DISTINCT a.session_id) as total_sessions
            FROM attendance a
            LEFT JOIN attendance_sessions s ON a.session_id = s.id
            LEFT JOIN courses c ON s.course_id = c.id
            WHERE a.attendance_date BETWEEN '$start_date' AND '$end_date'";
    
    if ($course_id != 'all') {
        $sql .= " AND s.course_id = '$course_id'";
    }
    
    if ($department_id != 'all') {
        $sql .= " AND c.department_id = '$department_id'";
    }
    
    $sql .= " GROUP BY DATE(a.attendance_date) ORDER BY a.attendance_date DESC";
    
    $result = $conn->query($sql);
    $data = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

function generateStudentAttendance($conn, $start_date, $end_date, $course_id) {
    $sql = "SELECT 
                s.student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                c.course_code,
                c.course_name,
                DATE(a.attendance_date) as date,
                TIME(a.attendance_date) as time,
                a.status,
                ses.session_name,
                a.remarks
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            JOIN attendance_sessions ses ON a.session_id = ses.id
            JOIN courses c ON ses.course_id = c.id
            WHERE DATE(a.attendance_date) BETWEEN '$start_date' AND '$end_date'";
    
    if ($course_id != 'all') {
        $sql .= " AND ses.course_id = '$course_id'";
    }
    
    $sql .= " ORDER BY a.attendance_date DESC, s.last_name, s.first_name";
    
    $result = $conn->query($sql);
    $data = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

function generateCourseSummary($conn, $start_date, $end_date, $department_id) {
    $sql = "SELECT 
                c.course_code,
                c.course_name,
                d.department_name,
                COUNT(DISTINCT se.student_id) as enrolled_students,
                COUNT(DISTINCT a.student_id) as attended_students,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
            FROM courses c
            LEFT JOIN departments d ON c.department_id = d.id
            LEFT JOIN student_enrollments se ON c.id = se.course_id AND se.status = 'active'
            LEFT JOIN attendance a ON se.student_id = a.student_id 
                AND DATE(a.attendance_date) BETWEEN '$start_date' AND '$end_date'
            WHERE c.status = 'active'";
    
    if ($department_id != 'all') {
        $sql .= " AND c.department_id = '$department_id'";
    }
    
    $sql .= " GROUP BY c.id ORDER BY c.course_code";
    
    $result = $conn->query($sql);
    $data = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    return $data;
}

function calculateSummaryStats($report_data) {
    $stats = [
        'total_present' => 0,
        'total_late' => 0,
        'total_absent' => 0,
        'total_sessions' => 0,
        'attendance_rate' => 0
    ];
    
    if (empty($report_data)) return $stats;
    
    $total_records = 0;
    $total_attended = 0;
    
    foreach ($report_data as $row) {
        $stats['total_present'] += $row['present_count'] ?? 0;
        $stats['total_late'] += $row['late_count'] ?? 0;
        $stats['total_absent'] += $row['absent_count'] ?? 0;
        $stats['total_sessions'] += $row['total_sessions'] ?? 0;
        
        $total_records += ($row['present_count'] ?? 0) + ($row['late_count'] ?? 0) + ($row['absent_count'] ?? 0);
        $total_attended += ($row['present_count'] ?? 0) + ($row['late_count'] ?? 0);
    }
    
    $stats['attendance_rate'] = $total_records > 0 ? round(($total_attended / $total_records) * 100, 2) : 0;
    
    return $stats;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - DDU Attendance System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
        }
        
        .stat-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            color: white;
            margin-bottom: 20px;
        }
        
        .bg-present { background: linear-gradient(45deg, #28a745, #20c997); }
        .bg-late { background: linear-gradient(45deg, #ffc107, #fd7e14); }
        .bg-absent { background: linear-gradient(45deg, #dc3545, #e83e8c); }
        .bg-rate { background: linear-gradient(45deg, #17a2b8, #20c997); }
        
        .table th {
            background-color: #f8f9fc;
            font-weight: 600;
            color: #4e73df;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #4e73df, #224abe);
            border: none;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <h1 class="mb-3">
                    <i class="fas fa-chart-bar"></i> Attendance Reports
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                        <li class="breadcrumb-item active">Attendance Reports</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-filter"></i> Report Filters
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" class="form-select">
                            <option value="daily_summary" <?= $report_type == 'daily_summary' ? 'selected' : '' ?>>Daily Summary</option>
                            <option value="student_attendance" <?= $report_type == 'student_attendance' ? 'selected' : '' ?>>Student Details</option>
                            <option value="course_summary" <?= $report_type == 'course_summary' ? 'selected' : '' ?>>Course Summary</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?= $start_date ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?= $end_date ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Course</label>
                        <select name="course_id" class="form-select">
                            <option value="all">All Courses</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['id'] ?>" <?= $course_id == $course['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($course['course_code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select">
                            <option value="all">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= $department_id == $dept['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['department_code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-12 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Generate Report
                        </button>
                        <button type="button" onclick="window.print()" class="btn btn-secondary">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Statistics -->
        <?php if (!empty($summary_stats) && $report_type == 'daily_summary'): ?>
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-present">
                    <i class="fas fa-user-check"></i>
                    <h3><?= $summary_stats['total_present'] ?></h3>
                    <p>Total Present</p>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-late">
                    <i class="fas fa-clock"></i>
                    <h3><?= $summary_stats['total_late'] ?></h3>
                    <p>Total Late</p>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-absent">
                    <i class="fas fa-user-times"></i>
                    <h3><?= $summary_stats['total_absent'] ?></h3>
                    <p>Total Absent</p>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-rate">
                    <i class="fas fa-chart-line"></i>
                    <h3><?= $summary_stats['attendance_rate'] ?>%</h3>
                    <p>Attendance Rate</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Report Data -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-table"></i> 
                <?php 
                if ($report_type == 'daily_summary') echo 'Daily Attendance Summary';
                elseif ($report_type == 'student_attendance') echo 'Student Attendance Details';
                elseif ($report_type == 'course_summary') echo 'Course Attendance Summary';
                else echo 'Attendance Report';
                ?>
                <span class="float-end">
                    <small class="text-light">
                        <?= formatDate($start_date) ?> - <?= formatDate($end_date) ?>
                    </small>
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($report_data)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        No attendance data found for the selected criteria.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <?php 
                                    switch($report_type) {
                                        case 'daily_summary':
                                            echo '<th>Date</th><th>Total Students</th><th>Present</th><th>Late</th><th>Absent</th><th>Sessions</th>';
                                            break;
                                        case 'student_attendance':
                                            echo '<th>Student ID</th><th>Name</th><th>Course</th><th>Date</th><th>Time</th><th>Status</th><th>Session</th><th>Remarks</th>';
                                            break;
                                        case 'course_summary':
                                            echo '<th>Course Code</th><th>Course Name</th><th>Department</th><th>Enrolled</th><th>Attended</th><th>Present</th><th>Late</th><th>Absent</th>';
                                            break;
                                    }
                                    ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <?php
                                        switch($report_type) {
                                            case 'daily_summary':
                                                echo '<td>' . formatDate($row['date']) . '</td>';
                                                echo '<td>' . $row['total_students'] . '</td>';
                                                echo '<td><span class="badge bg-success">' . $row['present_count'] . '</span></td>';
                                                echo '<td><span class="badge bg-warning">' . $row['late_count'] . '</span></td>';
                                                echo '<td><span class="badge bg-danger">' . $row['absent_count'] . '</span></td>';
                                                echo '<td>' . $row['total_sessions'] . '</td>';
                                                break;
                                                
                                            case 'student_attendance':
                                                echo '<td>' . $row['student_id'] . '</td>';
                                                echo '<td>' . $row['student_name'] . '</td>';
                                                echo '<td>' . $row['course_code'] . ' - ' . $row['course_name'] . '</td>';
                                                echo '<td>' . formatDate($row['date']) . '</td>';
                                                echo '<td>' . formatTime($row['time']) . '</td>';
                                                $status_badge = $row['status'] == 'present' ? 'success' : ($row['status'] == 'late' ? 'warning' : 'danger');
                                                echo '<td><span class="badge bg-' . $status_badge . '">' . ucfirst($row['status']) . '</span></td>';
                                                echo '<td>' . $row['session_name'] . '</td>';
                                                echo '<td>' . ($row['remarks'] ?: 'N/A') . '</td>';
                                                break;
                                                
                                            case 'course_summary':
                                                echo '<td>' . $row['course_code'] . '</td>';
                                                echo '<td>' . $row['course_name'] . '</td>';
                                                echo '<td>' . $row['department_name'] . '</td>';
                                                echo '<td>' . $row['enrolled_students'] . '</td>';
                                                echo '<td>' . $row['attended_students'] . '</td>';
                                                echo '<td><span class="badge bg-success">' . $row['present_count'] . '</span></td>';
                                                echo '<td><span class="badge bg-warning">' . $row['late_count'] . '</span></td>';
                                                echo '<td><span class="badge bg-danger">' . $row['absent_count'] . '</span></td>';
                                                break;
                                        }
                                        ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>