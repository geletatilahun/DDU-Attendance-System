<?php
/**
 * Department Report - Dean Module
 */

session_start();
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check dean role
checkRole(['dean']);

$dean_id = $_SESSION['user_id'];

// Get dean's department
$dean_dept = $conn->query("SELECT department_id FROM users WHERE user_id = $dean_id")->fetch_assoc();
$department_id = $dean_dept['department_id'] ?? 0;

// Get department info
$department = $conn->query("SELECT * FROM departments WHERE department_id = $department_id")->fetch_assoc();

// Report filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$year_of_study = $_GET['year_of_study'] ?? '';
$course_id = $_GET['course_id'] ?? '';
$report_type = $_GET['type'] ?? 'overview';

// Get courses in department
$courses = $conn->query("
    SELECT course_id, course_code, course_name 
    FROM courses 
    WHERE department_id = $department_id AND is_active = 1
    ORDER BY course_code
");

// Generate department overview report
function generateOverviewReport($conn, $dept_id, $start_date, $end_date) {
    $report = [];
    
    // Overall statistics
    $stats = $conn->query("
        SELECT 
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(DISTINCT u.user_id) as total_teachers,
            COUNT(DISTINCT c.course_id) as total_courses,
            COUNT(DISTINCT a.student_id, a.course_id, a.attendance_date) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as overall_percentage
        FROM departments d
        LEFT JOIN students s ON d.department_id = s.department_id AND s.is_active = 1
        LEFT JOIN users u ON d.department_id = u.department_id AND u.role = 'teacher' AND u.is_active = 1
        LEFT JOIN courses c ON d.department_id = c.department_id AND c.is_active = 1
        LEFT JOIN attendance a ON s.student_id = a.student_id 
            AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
        WHERE d.department_id = $dept_id
    ")->fetch_assoc();
    
    $report['overall'] = $stats;
    
    // Attendance by year of study
    $report['by_year'] = $conn->query("
        SELECT 
            s.year_of_study,
            COUNT(DISTINCT s.student_id) as total_students,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id 
            AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
        WHERE s.department_id = $dept_id AND s.is_active = 1
        GROUP BY s.year_of_study
        ORDER BY s.year_of_study
    ");
    
    // Attendance by course
    $report['by_course'] = $conn->query("
        SELECT 
            c.course_code,
            c.course_name,
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM courses c
        LEFT JOIN attendance a ON c.course_id = a.course_id 
            AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
        WHERE c.department_id = $dept_id AND c.is_active = 1
        GROUP BY c.course_id
        ORDER BY attendance_percentage DESC
    ");
    
    // Daily attendance trend
    $report['daily_trend'] = $conn->query("
        SELECT 
            DATE(a.attendance_date) as date,
            COUNT(DISTINCT a.student_id) as total_students,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        WHERE s.department_id = $dept_id
            AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
        GROUP BY DATE(a.attendance_date)
        ORDER BY a.attendance_date
    ");
    
    return $report;
}

// Generate detailed student report
function generateStudentReport($conn, $dept_id, $start_date, $end_date, $year_filter = '') {
    $where = "WHERE s.department_id = $dept_id AND s.is_active = 1";
    if ($year_filter) {
        $where .= " AND s.year_of_study = $year_filter";
    }
    
    return $conn->query("
        SELECT 
            s.student_id_no,
            s.full_name,
            s.year_of_study,
            s.program,
            COUNT(DISTINCT a.course_id) as courses_taken,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage,
            CASE 
                WHEN (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) >= 75 THEN 'Satisfactory'
                WHEN (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) >= 50 THEN 'Warning'
                ELSE 'Critical'
            END as status
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id 
            AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
        $where
        GROUP BY s.student_id
        HAVING total_sessions > 0
        ORDER BY attendance_percentage ASC
    ");
}

// Generate course-wise report
function generateCourseReport($conn, $dept_id, $start_date, $end_date, $course_filter = '') {
    $where = "WHERE c.department_id = $dept_id AND c.is_active = 1";
    if ($course_filter) {
        $where .= " AND c.course_id = $course_filter";
    }
    
    return $conn->query("
        SELECT 
            c.course_code,
            c.course_name,
            c.credit_hours,
            u.full_name as teacher_name,
            COUNT(DISTINCT a.student_id) as enrolled_students,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage,
            COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.student_id END) as regular_students,
            COUNT(DISTINCT CASE WHEN 
                (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) < 75 
                THEN a.student_id END) as low_attendance_students
        FROM courses c
        LEFT JOIN attendance a ON c.course_id = a.course_id 
            AND a.attendance_date BETWEEN '$start_date' AND '$end_date'
        LEFT JOIN course_assignments ca ON c.course_id = ca.course_id AND ca.is_active = 1
        LEFT JOIN users u ON ca.teacher_id = u.user_id
        $where
        GROUP BY c.course_id
        ORDER BY attendance_percentage DESC
    ");
}

// Generate report based on type
$report_data = [];
switch ($report_type) {
    case 'students':
        $report_data = generateStudentReport($conn, $department_id, $start_date, $end_date, $year_of_study);
        $report_title = 'Student-wise Attendance Report';
        break;
    case 'courses':
        $report_data = generateCourseReport($conn, $department_id, $start_date, $end_date, $course_id);
        $report_title = 'Course-wise Attendance Report';
        break;
    default:
        $report_data = generateOverviewReport($conn, $department_id, $start_date, $end_date);
        $report_title = 'Department Overview Report';
}

// Export functionality
if (isset($_GET['export'])) {
    if ($_GET['export'] == 'pdf') {
        // Redirect to PDF generation script
        header("Location: generate_pdf.php?" . $_SERVER['QUERY_STRING']);
        exit;
    } elseif ($_GET['export'] == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="dept_report_' . date('Y-m-d') . '.xls"');
        
        echo generateExcelReport($report_data, $report_type, $department, $start_date, $end_date);
        exit;
    }
}

function generateExcelReport($data, $type, $dept, $start, $end) {
    $output = '<table border="1">';
    $output .= '<tr><th colspan="10" style="background:#003366;color:white;padding:10px;">' . 
               htmlspecialchars($dept['department_name']) . ' - Attendance Report</th></tr>';
    $output .= '<tr><th colspan="10">Period: ' . date('F j, Y', strtotime($start)) . ' to ' . 
               date('F j, Y', strtotime($end)) . '</th></tr>';
    
    if ($type == 'overview' && is_array($data)) {
        // Overview report Excel format
        $output .= '<tr><th colspan="5" style="background:#f2f2f2;">Overall Statistics</th></tr>';
        $output .= '<tr>
            <th>Total Students</th>
            <th>Total Teachers</th>
            <th>Total Courses</th>
            <th>Total Sessions</th>
            <th>Overall Attendance %</th>
        </tr>';
        $output .= '<tr>
            <td>' . ($data['overall']['total_students'] ?? 0) . '</td>
            <td>' . ($data['overall']['total_teachers'] ?? 0) . '</td>
            <td>' . ($data['overall']['total_courses'] ?? 0) . '</td>
            <td>' . ($data['overall']['total_sessions'] ?? 0) . '</td>
            <td>' . ($data['overall']['overall_percentage'] ?? 0) . '%</td>
        </tr>';
    }
    
    $output .= '</table>';
    return $output;
}

$page_title = "Department Report - " . ($department['department_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/header.php'; ?>
    <style>
        .report-filters {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #003366;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .chart-container {
            height: 300px;
            position: relative;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <h2 class="page-title"><?php echo htmlspecialchars($report_title); ?></h2>
        <p class="text-muted">Department: <?php echo htmlspecialchars($department['department_name'] ?? ''); ?></p>
        
        <!-- Filters -->
        <div class="report-filters">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Report Type</label>
                    <select name="type" class="form-select" onchange="this.form.submit()">
                        <option value="overview" <?= $report_type == 'overview' ? 'selected' : '' ?>>Overview</option>
                        <option value="students" <?= $report_type == 'students' ? 'selected' : '' ?>>Student-wise</option>
                        <option value="courses" <?= $report_type == 'courses' ? 'selected' : '' ?>>Course-wise</option>
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
                
                <?php if ($report_type == 'students'): ?>
                <div class="col-md-2">
                    <label class="form-label">Year of Study</label>
                    <select name="year_of_study" class="form-select">
                        <option value="">All Years</option>
                        <?php for($i=1; $i<=5; $i++): ?>
                        <option value="<?= $i ?>" <?= $year_of_study == $i ? 'selected' : '' ?>>Year <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php elseif ($report_type == 'courses'): ?>
                <div class="col-md-2">
                    <label class="form-label">Course</label>
                    <select name="course_id" class="form-select">
                        <option value="">All Courses</option>
                        <?php while($course = $courses->fetch_assoc()): ?>
                        <option value="<?= $course['course_id'] ?>" <?= $course_id == $course['course_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['course_code']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Generate Report</button>
                    <div class="btn-group">
                        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" 
                           class="btn btn-success">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" 
                           class="btn btn-danger">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                        <button type="button" class="btn btn-info" onclick="window.print()">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Report Content -->
        <div class="card">
            <div class="card-body">
                <?php if ($report_type == 'overview'): ?>
                    <?php include 'report_overview.php'; ?>
                <?php elseif ($report_type == 'students'): ?>
                    <?php include 'report_students.php'; ?>
                <?php elseif ($report_type == 'courses'): ?>
                    <?php include 'report_courses.php'; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Initialize charts if they exist
        document.addEventListener('DOMContentLoaded', function() {
            // Attendance trend chart
            const trendCtx = document.getElementById('attendanceTrendChart');
            if (trendCtx) {
                new Chart(trendCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($trend_labels ?? []) ?>,
                        datasets: [{
                            label: 'Attendance %',
                            data: <?= json_encode($trend_data ?? []) ?>,
                            borderColor: '#003366',
                            backgroundColor: 'rgba(0, 51, 102, 0.1)',
                            tension: 0.4
                        }]
                    }
                });
            }
            
            // Attendance by year chart
            const yearCtx = document.getElementById('attendanceByYearChart');
            if (yearCtx) {
                new Chart(yearCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($year_labels ?? []) ?>,
                        datasets: [{
                            label: 'Attendance %',
                            data: <?= json_encode($year_data ?? []) ?>,
                            backgroundColor: '#003366'
                        }]
                    }
                });
            }
        });
    </script>
</body>
</html>