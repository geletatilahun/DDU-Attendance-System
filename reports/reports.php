<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

// Check appropriate roles
checkRole(['admin', 'teacher', 'dean', 'registrar']);

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Default filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$department_id = $_GET['department_id'] ?? '';
$course_id = $_GET['course_id'] ?? '';
$year_of_study = $_GET['year_of_study'] ?? '';

// Generate report based on type
$report_type = $_GET['type'] ?? 'summary';

// Get departments for filter
$departments = $conn->query("SELECT * FROM departments ORDER BY department_name");

// Get courses for filter
$courses_query = "SELECT * FROM courses WHERE is_active = 1 ORDER BY course_name";
if ($role == 'teacher') {
    $courses_query = "SELECT c.* FROM courses c 
                     JOIN course_assignments ca ON c.course_id = ca.course_id
                     WHERE ca.teacher_id = $user_id AND ca.is_active = 1
                     ORDER BY c.course_name";
}
$courses = $conn->query($courses_query);

// Generate attendance summary report
function generateSummaryReport($conn, $filters) {
    $where_clause = "WHERE 1=1";
    
    if (!empty($filters['start_date'])) {
        $where_clause .= " AND a.attendance_date >= '{$filters['start_date']}'";
    }
    if (!empty($filters['end_date'])) {
        $where_clause .= " AND a.attendance_date <= '{$filters['end_date']}'";
    }
    if (!empty($filters['department_id'])) {
        $where_clause .= " AND s.department_id = {$filters['department_id']}";
    }
    if (!empty($filters['course_id'])) {
        $where_clause .= " AND a.course_id = {$filters['course_id']}";
    }
    if (!empty($filters['year_of_study'])) {
        $where_clause .= " AND s.year_of_study = {$filters['year_of_study']}";
    }
    
    $sql = "
        SELECT 
            c.course_code,
            c.course_name,
            d.department_name,
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN courses c ON a.course_id = c.course_id
        JOIN students s ON a.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        $where_clause
        GROUP BY a.course_id, c.course_code, c.course_name, d.department_name
        ORDER BY attendance_percentage DESC
    ";
    
    return $conn->query($sql);
}

// Generate student-wise report
function generateStudentReport($conn, $filters) {
    $where_clause = "WHERE 1=1";
    
    if (!empty($filters['start_date'])) {
        $where_clause .= " AND a.attendance_date >= '{$filters['start_date']}'";
    }
    if (!empty($filters['end_date'])) {
        $where_clause .= " AND a.attendance_date <= '{$filters['end_date']}'";
    }
    if (!empty($filters['department_id'])) {
        $where_clause .= " AND s.department_id = {$filters['department_id']}";
    }
    if (!empty($filters['course_id'])) {
        $where_clause .= " AND a.course_id = {$filters['course_id']}";
    }
    if (!empty($filters['year_of_study'])) {
        $where_clause .= " AND s.year_of_study = {$filters['year_of_study']}";
    }
    
    $sql = "
        SELECT 
            s.student_id_no,
            s.full_name,
            d.department_name,
            s.program,
            s.year_of_study,
            c.course_code,
            c.course_name,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage,
            CASE 
                WHEN (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) >= 75 THEN 'Satisfactory'
                WHEN (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) >= 50 THEN 'Warning'
                ELSE 'Critical'
            END as status
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN courses c ON a.course_id = c.course_id
        LEFT JOIN departments d ON s.department_id = d.department_id
        $where_clause
        GROUP BY s.student_id, s.student_id_no, s.full_name, d.department_name, s.program, s.year_of_study, c.course_id
        HAVING total_sessions > 0
        ORDER BY attendance_percentage ASC
    ";
    
    return $conn->query($sql);
}

// Generate daily attendance report
function generateDailyReport($conn, $filters) {
    $where_clause = "WHERE a.attendance_date BETWEEN '{$filters['start_date']}' AND '{$filters['end_date']}'";
    
    if (!empty($filters['department_id'])) {
        $where_clause .= " AND s.department_id = {$filters['department_id']}";
    }
    if (!empty($filters['course_id'])) {
        $where_clause .= " AND a.course_id = {$filters['course_id']}";
    }
    
    $sql = "
        SELECT 
            DATE(a.attendance_date) as date,
            DAYNAME(a.attendance_date) as day_name,
            c.course_code,
            c.course_name,
            COUNT(DISTINCT a.student_id) as total_students,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN courses c ON a.course_id = c.course_id
        JOIN students s ON a.student_id = s.student_id
        $where_clause
        GROUP BY DATE(a.attendance_date), a.course_id, c.course_code, c.course_name
        ORDER BY a.attendance_date DESC
    ";
    
    return $conn->query($sql);
}

// Generate data based on report type
$filters = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'department_id' => $department_id,
    'course_id' => $course_id,
    'year_of_study' => $year_of_study
];

switch ($report_type) {
    case 'student':
        $report_data = generateStudentReport($conn, $filters);
        $report_title = 'Student-wise Attendance Report';
        break;
    case 'daily':
        $report_data = generateDailyReport($conn, $filters);
        $report_title = 'Daily Attendance Report';
        break;
    default:
        $report_data = generateSummaryReport($conn, $filters);
        $report_title = 'Attendance Summary Report';
}

// Export to Excel
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="attendance_report_' . date('Y-m-d') . '.xls"');
    
    echo '<table border="1">';
    echo '<tr><th colspan="10" style="background:#003366;color:white;padding:10px;">DDU Attendance Report</th></tr>';
    echo '<tr><th colspan="10">Generated on: ' . date('F j, Y H:i:s') . '</th></tr>';
    echo '<tr><th colspan="10">Period: ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date)) . '</th></tr>';
    
    // Add headers based on report type
    if ($report_type == 'summary') {
        echo '<tr>
                <th>Course Code</th>
                <th>Course Name</th>
                <th>Department</th>
                <th>Students</th>
                <th>Sessions</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Late</th>
                <th>Attendance %</th>
                <th>Status</th>
              </tr>';
        
        while ($row = $report_data->fetch_assoc()) {
            $status = $row['attendance_percentage'] >= 75 ? 'Good' : ($row['attendance_percentage'] >= 50 ? 'Fair' : 'Poor');
            echo '<tr>
                    <td>' . $row['course_code'] . '</td>
                    <td>' . $row['course_name'] . '</td>
                    <td>' . $row['department_name'] . '</td>
                    <td>' . $row['total_students'] . '</td>
                    <td>' . $row['total_sessions'] . '</td>
                    <td>' . $row['present_count'] . '</td>
                    <td>' . $row['absent_count'] . '</td>
                    <td>' . $row['late_count'] . '</td>
                    <td>' . $row['attendance_percentage'] . '%</td>
                    <td>' . $status . '</td>
                  </tr>';
        }
    }
    
    echo '</table>';
    exit;
}

// Export to PDF
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    require_once('../includes/tcpdf/tcpdf.php');
    
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('DDU Attendance System');
    $pdf->SetAuthor('DDU Admin');
    $pdf->SetTitle('Attendance Report');
    $pdf->SetHeaderData('', 0, 'Dambi Dollo University', 'Attendance Management System');
    $pdf->setHeaderFont(Array('helvetica', '', 10));
    $pdf->setFooterFont(Array('helvetica', '', 8));
    $pdf->SetDefaultMonospacedFont('courier');
    $pdf->SetMargins(15, 25, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 25);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->AddPage();
    
    // Add report content
    $html = '<h2 style="text-align:center;color:#003366;">' . $report_title . '</h2>';
    $html .= '<p style="text-align:center;">Period: ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>';
    $html .= '<p style="text-align:center;">Generated on: ' . date('F j, Y H:i:s') . '</p>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('attendance_report_' . date('Y-m-d') . '.pdf', 'D');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Reports - DDU Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">
    <style>
        .report-card {
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .filter-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }
        .table th {
            background: #003366;
            color: white;
        }
        .export-buttons {
            position: sticky;
            top: 70px;
            z-index: 100;
            background: white;
            padding: 10px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .badge-satisfactory { background: #28a745; }
        .badge-warning { background: #ffc107; color: #000; }
        .badge-critical { background: #dc3545; }
    </style>
</head>
<body>
    <?php 
    if ($role == 'admin') include('../includes/admin_header.php');
    elseif ($role == 'teacher') include('../includes/teacher_header.php');
    elseif ($role == 'dean') include('../includes/dean_header.php');
    ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="card report-card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-chart-bar"></i> Attendance Reports</h4>
                    </div>
                    
                    <div class="card-body">
                        <!-- Filters Section -->
                        <div class="filter-section">
                            <form method="GET" class="row g-3">
                                <div class="col-md-2">
                                    <label class="form-label">Report Type</label>
                                    <select class="form-select" name="type">
                                        <option value="summary" <?= $report_type == 'summary' ? 'selected' : '' ?>>Summary</option>
                                        <option value="student" <?= $report_type == 'student' ? 'selected' : '' ?>>Student-wise</option>
                                        <option value="daily" <?= $report_type == 'daily' ? 'selected' : '' ?>>Daily</option>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" class="form-control" name="start_date" value="<?= $start_date ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">End Date</label>
                                    <input type="date" class="form-control" name="end_date" value="<?= $end_date ?>">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Department</label>
                                    <select class="form-select" name="department_id">
                                        <option value="">All Departments</option>
                                        <?php while($dept = $departments->fetch_assoc()): ?>
                                        <option value="<?= $dept['department_id'] ?>" 
                                                <?= $department_id == $dept['department_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dept['department_name']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Course</label>
                                    <select class="form-select" name="course_id">
                                        <option value="">All Courses</option>
                                        <?php while($course = $courses->fetch_assoc()): ?>
                                        <option value="<?= $course['course_id'] ?>" 
                                                <?= $course_id == $course['course_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course['course_code']) ?>
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">Year of Study</label>
                                    <select class="form-select" name="year_of_study">
                                        <option value="">All Years</option>
                                        <option value="1" <?= $year_of_study == '1' ? 'selected' : '' ?>>Year 1</option>
                                        <option value="2" <?= $year_of_study == '2' ? 'selected' : '' ?>>Year 2</option>
                                        <option value="3" <?= $year_of_study == '3' ? 'selected' : '' ?>>Year 3</option>
                                        <option value="4" <?= $year_of_study == '4' ? 'selected' : '' ?>>Year 4</option>
                                        <option value="5" <?= $year_of_study == '5' ? 'selected' : '' ?>>Year 5</option>
                                    </select>
                                </div>
                                
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Apply Filters
                                    </button>
                                    <a href="reports.php" class="btn btn-secondary">
                                        <i class="fas fa-redo"></i> Reset
                                    </a>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Export Buttons -->
                        <div class="export-buttons mb-3">
                            <div class="btn-group" role="group">
                                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" 
                                   class="btn btn-success">
                                    <i class="fas fa-file-excel"></i> Export to Excel
                                </a>
                                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" 
                                   class="btn btn-danger">
                                    <i class="fas fa-file-pdf"></i> Export to PDF
                                </a>
                                <button class="btn btn-info" onclick="window.print()">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#emailModal">
                                    <i class="fas fa-envelope"></i> Email Report
                                </button>
                            </div>
                        </div>
                        
                        <!-- Report Statistics -->
                        <div class="row mb-4">
                            <?php
                            // Calculate overall statistics
                            $overall_stats = $conn->query("
                                SELECT 
                                    COUNT(DISTINCT a.student_id) as total_students,
                                    COUNT(DISTINCT a.course_id) as total_courses,
                                    COUNT(*) as total_sessions,
                                    SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                                    ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as overall_percentage
                                FROM attendance a
                                WHERE a.attendance_date BETWEEN '$start_date' AND '$end_date'
                            ")->fetch_assoc();
                            ?>
                            
                            <div class="col-md-3">
                                <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                    <i class="fas fa-users fa-2x mb-2"></i>
                                    <div class="stat-value"><?= number_format($overall_stats['total_students']) ?></div>
                                    <small>Total Students</small>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                                    <i class="fas fa-book fa-2x mb-2"></i>
                                    <div class="stat-value"><?= number_format($overall_stats['total_courses']) ?></div>
                                    <small>Total Courses</small>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white;">
                                    <i class="fas fa-clipboard-check fa-2x mb-2"></i>
                                    <div class="stat-value"><?= number_format($overall_stats['total_sessions']) ?></div>
                                    <small>Total Sessions</small>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: #333;">
                                    <i class="fas fa-chart-line fa-2x mb-2"></i>
                                    <div class="stat-value"><?= $overall_stats['overall_percentage'] ?>%</div>
                                    <small>Overall Attendance</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Report Table -->
                        <div class="table-responsive">
                            <table class="table table-hover table-striped" id="reportTable">
                                <thead>
                                    <?php if($report_type == 'summary'): ?>
                                    <tr>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Department</th>
                                        <th>Students</th>
                                        <th>Sessions</th>
                                        <th>Present</th>
                                        <th>Absent</th>
                                        <th>Late</th>
                                        <th>Attendance %</th>
                                        <th>Status</th>
                                    </tr>
                                    <?php elseif($report_type == 'student'): ?>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Department</th>
                                        <th>Program</th>
                                        <th>Year</th>
                                        <th>Course</th>
                                        <th>Sessions</th>
                                        <th>Present</th>
                                        <th>Absent</th>
                                        <th>Attendance %</th>
                                        <th>Status</th>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <th>Date</th>
                                        <th>Day</th>
                                        <th>Course Code</th>
                                        <th>Course Name</th>
                                        <th>Students</th>
                                        <th>Present</th>
                                        <th>Absent</th>
                                        <th>Attendance %</th>
                                    </tr>
                                    <?php endif; ?>
                                </thead>
                                <tbody>
                                    <?php 
                                    $report_data->data_seek(0);
                                    while($row = $report_data->fetch_assoc()): 
                                    ?>
                                    <?php if($report_type == 'summary'): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['course_code']) ?></td>
                                        <td><?= htmlspecialchars($row['course_name']) ?></td>
                                        <td><?= htmlspecialchars($row['department_name']) ?></td>
                                        <td class="text-center"><?= $row['total_students'] ?></td>
                                        <td class="text-center"><?= $row['total_sessions'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= $row['present_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= $row['absent_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-warning"><?= $row['late_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            $percentage = $row['attendance_percentage'];
                                            $color = $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?= $color ?>"><?= number_format($percentage, 1) ?>%</span>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $percentage >= 75 ? 'Satisfactory' : ($percentage >= 50 ? 'Warning' : 'Critical');
                                            $status_class = strtolower($status);
                                            ?>
                                            <span class="badge badge-<?= $status_class ?>"><?= $status ?></span>
                                        </td>
                                    </tr>
                                    <?php elseif($report_type == 'student'): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($row['student_id_no']) ?></td>
                                        <td><?= htmlspecialchars($row['full_name']) ?></td>
                                        <td><?= htmlspecialchars($row['department_name']) ?></td>
                                        <td><?= htmlspecialchars($row['program']) ?></td>
                                        <td class="text-center">Year <?= $row['year_of_study'] ?></td>
                                        <td><?= htmlspecialchars($row['course_code']) ?></td>
                                        <td class="text-center"><?= $row['total_sessions'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= $row['present_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= $row['absent_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            $percentage = $row['attendance_percentage'];
                                            $color = $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?= $color ?>"><?= number_format($percentage, 1) ?>%</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?= strtolower($row['status']) ?>">
                                                <?= $row['status'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($row['date'])) ?></td>
                                        <td><?= $row['day_name'] ?></td>
                                        <td><?= htmlspecialchars($row['course_code']) ?></td>
                                        <td><?= htmlspecialchars($row['course_name']) ?></td>
                                        <td class="text-center"><?= $row['total_students'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= $row['present_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-danger"><?= $row['absent_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            $percentage = $row['attendance_percentage'];
                                            $color = $percentage >= 75 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger');
                                            ?>
                                            <span class="badge bg-<?= $color ?>"><?= number_format($percentage, 1) ?>%</span>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Charts Section -->
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <div class="card report-card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Attendance Distribution</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="attendanceChart" height="250"></canvas>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card report-card">
                                    <div class="card-header">
                                        <h5 class="mb-0">Trend Analysis</h5>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="trendChart" height="250"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Email Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Email Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="send_report_email.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Recipient Email</label>
                            <input type="email" class="form-control" name="recipient_email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-control" name="subject" 
                                   value="DDU Attendance Report - <?= date('F j, Y') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" name="message" rows="4">
Please find attached the attendance report for the period <?= date('F j, Y', strtotime($start_date)) ?> to <?= date('F j, Y', strtotime($end_date)) ?>.

This report was generated by the DDU Attendance Management System.
                            </textarea>
                        </div>
                        <input type="hidden" name="report_type" value="<?= $report_type ?>">
                        <input type="hidden" name="start_date" value="<?= $start_date ?>">
                        <input type="hidden" name="end_date" value="<?= $end_date ?>">
                        <input type="hidden" name="department_id" value="<?= $department_id ?>">
                        <input type="hidden" name="course_id" value="<?= $course_id ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Email</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize DataTable with export buttons
            $('#reportTable').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    'copy', 'csv', 'excel', 'pdf', 'print'
                ],
                pageLength: 25,
                order: [[0, 'asc']]
            });
            
            // Attendance Distribution Chart
            const ctx1 = document.getElementById('attendanceChart').getContext('2d');
            const attendanceChart = new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['Satisfactory (≥75%)', 'Warning (50-74%)', 'Critical (<50%)'],
                    datasets: [{
                        data: [65, 25, 10],
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
            
            // Trend Analysis Chart
            const ctx2 = document.getElementById('trendChart').getContext('2d');
            const trendChart = new Chart(ctx2, {
                type: 'line',
                data: {
                    labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4'],
                    datasets: [{
                        label: 'Attendance %',
                        data: [85, 82, 88, 90],
                        borderColor: '#003366',
                        backgroundColor: 'rgba(0, 51, 102, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: false,
                            min: 70,
                            max: 100
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>