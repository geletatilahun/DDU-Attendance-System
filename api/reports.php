<?php
/**
 * Reports API Endpoint
 * This file handles all report generation API operations
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Authenticate API key
$api_data = authenticateAPI();

// Main API routing
$method = $_SERVER['REQUEST_METHOD'];
$report_type = $_GET['type'] ?? 'summary';
$format = $_GET['format'] ?? 'json';

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Route report requests
switch ($report_type) {
    case 'summary':
        generateSummaryReport($format);
        break;
    case 'attendance':
        generateAttendanceReport($format);
        break;
    case 'student':
        generateStudentReport($format);
        break;
    case 'course':
        generateCourseReport($format);
        break;
    case 'department':
        generateDepartmentReport($format);
        break;
    case 'teacher':
        generateTeacherReport($format);
        break;
    case 'daily':
        generateDailyReport($format);
        break;
    case 'weekly':
        generateWeeklyReport($format);
        break;
    case 'monthly':
        generateMonthlyReport($format);
        break;
    case 'semester':
        generateSemesterReport($format);
        break;
    case 'annual':
        generateAnnualReport($format);
        break;
    case 'export':
        exportReport($format);
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid report type']);
        break;
}

// Generate summary report
function generateSummaryReport($format) {
    global $conn;
    
    // Get filters
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $department_id = $_GET['department_id'] ?? null;
    $course_id = $_GET['course_id'] ?? null;
    $year_of_study = $_GET['year_of_study'] ?? null;
    
    // Build WHERE clause
    $where = "WHERE a.attendance_date BETWEEN '$start_date' AND '$end_date'";
    $filters = [];
    
    if ($department_id && is_numeric($department_id)) {
        $where .= " AND s.department_id = " . intval($department_id);
        $filters['department_id'] = $department_id;
    }
    
    if ($course_id && is_numeric($course_id)) {
        $where .= " AND a.course_id = " . intval($course_id);
        $filters['course_id'] = $course_id;
    }
    
    if ($year_of_study && is_numeric($year_of_study)) {
        $where .= " AND s.year_of_study = " . intval($year_of_study);
        $filters['year_of_study'] = $year_of_study;
    }
    
    // Overall statistics
    $overall_sql = "
        SELECT 
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(DISTINCT a.course_id) as total_courses,
            COUNT(DISTINCT a.teacher_id) as total_teachers,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as overall_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        $where
    ";
    
    $overall_result = $conn->query($overall_sql);
    $overall_stats = $overall_result->fetch_assoc();
    
    // Daily trend
    $trend_sql = "
        SELECT 
            DATE(a.attendance_date) as date,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        $where
        GROUP BY DATE(a.attendance_date)
        ORDER BY date
    ";
    
    $trend_result = $conn->query($trend_sql);
    $daily_trend = [];
    
    while ($row = $trend_result->fetch_assoc()) {
        $daily_trend[] = $row;
    }
    
    // Department-wise breakdown
    $dept_sql = "
        SELECT 
            d.department_id,
            d.department_code,
            d.department_name,
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN departments d ON s.department_id = d.department_id
        $where
        GROUP BY d.department_id
        ORDER BY attendance_percentage DESC
    ";
    
    $dept_result = $conn->query($dept_sql);
    $department_breakdown = [];
    
    while ($row = $dept_result->fetch_assoc()) {
        $department_breakdown[] = $row;
    }
    
    // Course-wise breakdown
    $course_sql = "
        SELECT 
            c.course_id,
            c.course_code,
            c.course_name,
            d.department_name,
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN courses c ON a.course_id = c.course_id
        JOIN departments d ON c.department_id = d.department_id
        $where
        GROUP BY c.course_id
        ORDER BY attendance_percentage DESC
        LIMIT 10
    ";
    
    $course_result = $conn->query($course_sql);
    $course_breakdown = [];
    
    while ($row = $course_result->fetch_assoc()) {
        $course_breakdown[] = $row;
    }
    
    // Top and bottom performers
    $performers_sql = "
        SELECT 
            s.student_id,
            s.student_id_no,
            s.full_name,
            d.department_name,
            s.year_of_study,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN departments d ON s.department_id = d.department_id
        $where
        GROUP BY s.student_id
        HAVING total_sessions > 0
        ORDER BY attendance_percentage DESC
        LIMIT 10
    ";
    
    $top_result = $conn->query($performers_sql);
    $top_performers = [];
    
    while ($row = $top_result->fetch_assoc()) {
        $top_performers[] = $row;
    }
    
    $bottom_result = $conn->query(str_replace('DESC', 'ASC', $performers_sql));
    $bottom_performers = [];
    
    while ($row = $bottom_result->fetch_assoc()) {
        $bottom_performers[] = $row;
    }
    
    // Response data
    $report_data = [
        'report_type' => 'summary',
        'period' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ],
        'filters' => $filters,
        'overall_statistics' => $overall_stats,
        'daily_trend' => $daily_trend,
        'department_breakdown' => $department_breakdown,
        'course_breakdown' => $course_breakdown,
        'top_performers' => $top_performers,
        'bottom_performers' => $bottom_performers,
        'generated_at' => date('c'),
        'metadata' => [
            'total_records' => $overall_stats['total_sessions'] ?? 0,
            'time_range' => "$start_date to $end_date",
            'data_source' => 'DDU Attendance System'
        ]
    ];
    
    // Output based on format
    outputReport($report_data, $format, 'summary_report');
}

// Generate attendance report
function generateAttendanceReport($format) {
    global $conn;
    
    // Get filters
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $department_id = $_GET['department_id'] ?? null;
    $course_id = $_GET['course_id'] ?? null;
    $student_id = $_GET['student_id'] ?? null;
    $teacher_id = $_GET['teacher_id'] ?? null;
    $status = $_GET['status'] ?? null;
    
    // Build WHERE clause
    $where = "WHERE a.attendance_date BETWEEN '$start_date' AND '$end_date'";
    $filters = [];
    
    if ($department_id && is_numeric($department_id)) {
        $where .= " AND s.department_id = " . intval($department_id);
        $filters['department_id'] = $department_id;
    }
    
    if ($course_id && is_numeric($course_id)) {
        $where .= " AND a.course_id = " . intval($course_id);
        $filters['course_id'] = $course_id;
    }
    
    if ($student_id && is_numeric($student_id)) {
        $where .= " AND a.student_id = " . intval($student_id);
        $filters['student_id'] = $student_id;
    }
    
    if ($teacher_id && is_numeric($teacher_id)) {
        $where .= " AND a.teacher_id = " . intval($teacher_id);
        $filters['teacher_id'] = $teacher_id;
    }
    
    if ($status && in_array($status, ['present', 'absent', 'late', 'excused'])) {
        $where .= " AND a.status = '$status'";
        $filters['status'] = $status;
    }
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(500, max(1, intval($_GET['limit']))) : 100;
    $offset = ($page - 1) * $limit;
    
    // Get total count
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        $where
    ";
    
    $count_result = $conn->query($count_sql);
    $total = $count_result->fetch_assoc()['total'];
    
    // Get attendance records
    $sql = "
        SELECT 
            a.attendance_id,
            a.course_id,
            c.course_code,
            c.course_name,
            a.student_id,
            s.student_id_no,
            s.full_name as student_name,
            d.department_name,
            s.year_of_study,
            a.teacher_id,
            u.full_name as teacher_name,
            a.attendance_date,
            a.session_type,
            a.status,
            a.check_in_time,
            a.check_out_time,
            a.notes,
            a.marked_by,
            mu.full_name as marked_by_name,
            a.marked_at
        FROM attendance a
        JOIN courses c ON a.course_id = c.course_id
        JOIN students s ON a.student_id = s.student_id
        JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN users u ON a.teacher_id = u.user_id
        LEFT JOIN users mu ON a.marked_by = mu.user_id
        $where
        ORDER BY a.attendance_date DESC, a.marked_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $result = $conn->query($sql);
    $attendance_records = [];
    
    while ($row = $result->fetch_assoc()) {
        // Format times
        $row['check_in_time'] = $row['check_in_time'] ? date('H:i', strtotime($row['check_in_time'])) : null;
        $row['check_out_time'] = $row['check_out_time'] ? date('H:i', strtotime($row['check_out_time'])) : null;
        $row['marked_at'] = date('c', strtotime($row['marked_at']));
        
        $attendance_records[] = $row;
    }
    
    // Summary statistics
    $summary_sql = "
        SELECT 
            COUNT(*) as total_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage,
            COUNT(DISTINCT a.student_id) as unique_students,
            COUNT(DISTINCT a.course_id) as unique_courses,
            COUNT(DISTINCT a.teacher_id) as unique_teachers
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        $where
    ";
    
    $summary_result = $conn->query($summary_sql);
    $summary = $summary_result->fetch_assoc();
    
    // Daily summary
    $daily_sql = "
        SELECT 
            DATE(a.attendance_date) as date,
            DAYNAME(a.attendance_date) as day_name,
            COUNT(*) as total_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        $where
        GROUP BY DATE(a.attendance_date)
        ORDER BY date DESC
    ";
    
    $daily_result = $conn->query($daily_sql);
    $daily_summary = [];
    
    while ($row = $daily_result->fetch_assoc()) {
        $daily_summary[] = $row;
    }
    
    // Status distribution
    $status_sql = "
        SELECT 
            a.status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM attendance a2 JOIN students s2 ON a2.student_id = s2.student_id $where)), 2) as percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        $where
        GROUP BY a.status
        ORDER BY count DESC
    ";
    
    $status_result = $conn->query($status_sql);
    $status_distribution = [];
    
    while ($row = $status_result->fetch_assoc()) {
        $status_distribution[] = $row;
    }
    
    // Response data
    $report_data = [
        'report_type' => 'attendance',
        'period' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ],
        'filters' => $filters,
        'summary' => $summary,
        'attendance_records' => $attendance_records,
        'daily_summary' => $daily_summary,
        'status_distribution' => $status_distribution,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ],
        'generated_at' => date('c'),
        'metadata' => [
            'total_records' => $total,
            'time_range' => "$start_date to $end_date",
            'data_source' => 'DDU Attendance System'
        ]
    ];
    
    // Output based on format
    outputReport($report_data, $format, 'attendance_report');
}

// Generate student report
function generateStudentReport($format) {
    global $conn;
    
    // Get filters
    $department_id = $_GET['department_id'] ?? null;
    $year_of_study = $_GET['year_of_study'] ?? null;
    $program = $_GET['program'] ?? null;
    $status = $_GET['status'] ?? null; // active, inactive, etc.
    $attendance_threshold = $_GET['threshold'] ?? 75;
    
    // Build WHERE clause
    $where = "WHERE s.is_active = 1";
    $filters = [];
    
    if ($department_id && is_numeric($department_id)) {
        $where .= " AND s.department_id = " . intval($department_id);
        $filters['department_id'] = $department_id;
    }
    
    if ($year_of_study && is_numeric($year_of_study)) {
        $where .= " AND s.year_of_study = " . intval($year_of_study);
        $filters['year_of_study'] = $year_of_study;
    }
    
    if ($program && !empty($program)) {
        $where .= " AND s.program = '" . $conn->real_escape_string($program) . "'";
        $filters['program'] = $program;
    }
    
    if ($status && in_array($status, ['active', 'inactive', 'suspended'])) {
        $where .= " AND s.status = '$status'";
        $filters['status'] = $status;
    }
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(500, max(1, intval($_GET['limit']))) : 100;
    $offset = ($page - 1) * $limit;
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM students s $where";
    $count_result = $conn->query($count_sql);
    $total = $count_result->fetch_assoc()['total'];
    
    // Get students with attendance data
    $sql = "
        SELECT 
            s.student_id,
            s.student_id_no,
            s.full_name,
            s.email,
            s.phone,
            s.gender,
            s.date_of_birth,
            s.department_id,
            d.department_name,
            d.department_code,
            s.program,
            s.year_of_study,
            s.semester,
            s.profile_image,
            s.is_active,
            s.created_at,
            
            -- Attendance summary
            COALESCE(SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END), 0) as present_count,
            COALESCE(COUNT(a.attendance_id), 0) as total_sessions,
            CASE 
                WHEN COUNT(a.attendance_id) > 0 
                THEN ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)), 2)
                ELSE 0 
            END as attendance_percentage,
            
            -- Course enrollment
            (SELECT COUNT(*) FROM student_enrollments se WHERE se.student_id = s.student_id AND se.enrollment_status = 'active') as enrolled_courses,
            
            -- Recent attendance date
            MAX(a.attendance_date) as last_attendance_date
            
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN attendance a ON s.student_id = a.student_id
        $where
        GROUP BY s.student_id
        ORDER BY s.full_name
        LIMIT $limit OFFSET $offset
    ";
    
    $result = $conn->query($sql);
    $students = [];
    
    while ($row = $result->fetch_assoc()) {
        // Calculate age
        if ($row['date_of_birth']) {
            $birth_date = new DateTime($row['date_of_birth']);
            $today = new DateTime();
            $row['age'] = $birth_date->diff($today)->y;
        }
        
        // Determine attendance status
        $row['attendance_status'] = getAttendanceStatusLabel($row['attendance_percentage']);
        $row['meets_threshold'] = $row['attendance_percentage'] >= $attendance_threshold;
        
        // Get course details
        $course_sql = "
            SELECT 
                c.course_code,
                c.course_name,
                COALESCE(asum.attendance_percentage, 0) as course_attendance
            FROM student_enrollments se
            JOIN courses c ON se.course_id = c.course_id
            LEFT JOIN attendance_summary asum ON se.student_id = asum.student_id AND se.course_id = asum.course_id
            WHERE se.student_id = {$row['student_id']}
            AND se.enrollment_status = 'active'
            LIMIT 3
        ";
        
        $course_result = $conn->query($course_sql);
        $row['current_courses'] = [];
        
        while ($course = $course_result->fetch_assoc()) {
            $row['current_courses'][] = $course;
        }
        
        $students[] = $row;
    }
    
    // Summary statistics
    $summary_sql = "
        SELECT 
            COUNT(*) as total_students,
            AVG(attendance_percentage) as avg_attendance,
            SUM(CASE WHEN attendance_percentage >= $attendance_threshold THEN 1 ELSE 0 END) as satisfactory_count,
            SUM(CASE WHEN attendance_percentage < $attendance_threshold AND attendance_percentage >= 50 THEN 1 ELSE 0 END) as warning_count,
            SUM(CASE WHEN attendance_percentage < 50 THEN 1 ELSE 0 END) as critical_count,
            ROUND((SUM(CASE WHEN attendance_percentage >= $attendance_threshold THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as satisfactory_rate
        FROM (
            SELECT 
                s.student_id,
                CASE 
                    WHEN COUNT(a.attendance_id) > 0 
                    THEN ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(a.attendance_id)), 2)
                    ELSE 0 
                END as attendance_percentage
            FROM students s
            LEFT JOIN attendance a ON s.student_id = a.student_id
            $where
            GROUP BY s.student_id
        ) as student_stats
    ";
    
    $summary_result = $conn->query($summary_sql);
    $summary = $summary_result->fetch_assoc();
    
    // Department distribution
    $dept_sql = "
        SELECT 
            d.department_name,
            COUNT(*) as student_count,
            ROUND(AVG(student_stats.attendance_percentage), 2) as avg_attendance
        FROM students s
        JOIN departments d ON s.department_id = d.department_id
        JOIN (
            SELECT 
                student_id,
                CASE 
                    WHEN COUNT(attendance_id) > 0 
                    THEN ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(attendance_id)), 2)
                    ELSE 0 
                END as attendance_percentage
            FROM attendance
            GROUP BY student_id
        ) as student_stats ON s.student_id = student_stats.student_id
        $where
        GROUP BY d.department_id
        ORDER BY student_count DESC
    ";
    
    $dept_result = $conn->query($dept_sql);
    $department_distribution = [];
    
    while ($row = $dept_result->fetch_assoc()) {
        $department_distribution[] = $row;
    }
    
    // Year of study distribution
    $year_sql = "
        SELECT 
            s.year_of_study,
            COUNT(*) as student_count,
            ROUND(AVG(student_stats.attendance_percentage), 2) as avg_attendance
        FROM students s
        JOIN (
            SELECT 
                student_id,
                CASE 
                    WHEN COUNT(attendance_id) > 0 
                    THEN ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(attendance_id)), 2)
                    ELSE 0 
                END as attendance_percentage
            FROM attendance
            GROUP BY student_id
        ) as student_stats ON s.student_id = student_stats.student_id
        $where
        GROUP BY s.year_of_study
        ORDER BY s.year_of_study
    ";
    
    $year_result = $conn->query($year_sql);
    $year_distribution = [];
    
    while ($row = $year_result->fetch_assoc()) {
        $year_distribution[] = $row;
    }
    
    // Response data
    $report_data = [
        'report_type' => 'student',
        'filters' => $filters,
        'summary' => $summary,
        'students' => $students,
        'department_distribution' => $department_distribution,
        'year_distribution' => $year_distribution,
        'attendance_threshold' => $attendance_threshold,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ],
        'generated_at' => date('c'),
        'metadata' => [
            'total_students' => $total,
            'data_source' => 'DDU Attendance System',
            'threshold_used' => $attendance_threshold
        ]
    ];
    
    // Output based on format
    outputReport($report_data, $format, 'student_report');
}

// Output report in different formats
function outputReport($data, $format, $filename) {
    switch ($format) {
        case 'json':
            echo json_encode($data, JSON_PRETTY_PRINT);
            break;
            
        case 'csv':
            outputCSV($data, $filename);
            break;
            
        case 'excel':
            outputExcel($data, $filename);
            break;
            
        case 'pdf':
            outputPDF($data, $filename);
            break;
            
        case 'xml':
            outputXML($data, $filename);
            break;
            
        default:
            echo json_encode($data, JSON_PRETTY_PRINT);
            break;
    }
}

// Output CSV format
function outputCSV($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Flatten data structure for CSV
    $flat_data = flattenDataForCSV($data);
    
    if (!empty($flat_data)) {
        // Write headers
        fputcsv($output, array_keys($flat_data[0]));
        
        // Write data rows
        foreach ($flat_data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

// Flatten data for CSV export
function flattenDataForCSV($data) {
    $flat_data = [];
    
    if (isset($data['students'])) {
        // Student report
        foreach ($data['students'] as $student) {
            $flat_row = [
                'Student ID' => $student['student_id_no'],
                'Full Name' => $student['full_name'],
                'Email' => $student['email'],
                'Department' => $student['department_name'],
                'Program' => $student['program'],
                'Year of Study' => $student['year_of_study'],
                'Total Sessions' => $student['total_sessions'],
                'Present Count' => $student['present_count'],
                'Attendance Percentage' => $student['attendance_percentage'] . '%',
                'Attendance Status' => $student['attendance_status'],
                'Meets Threshold' => $student['meets_threshold'] ? 'Yes' : 'No',
                'Enrolled Courses' => $student['enrolled_courses'],
                'Last Attendance' => $student['last_attendance_date'] ?? 'N/A'
            ];
            $flat_data[] = $flat_row;
        }
    } elseif (isset($data['attendance_records'])) {
        // Attendance report
        foreach ($data['attendance_records'] as $record) {
            $flat_row = [
                'Date' => $record['attendance_date'],
                'Course Code' => $record['course_code'],
                'Course Name' => $record['course_name'],
                'Student ID' => $record['student_id_no'],
                'Student Name' => $record['student_name'],
                'Department' => $record['department_name'],
                'Year' => $record['year_of_study'],
                'Teacher' => $record['teacher_name'] ?? 'N/A',
                'Session Type' => $record['session_type'],
                'Status' => $record['status'],
                'Check-in Time' => $record['
                check_in_time'] ?? 'N/A',
                'Check-out Time' => $record['check_out_time'] ?? 'N/A',
                'Notes' => $record['notes'] ?? '',
                'Marked By' => $record['marked_by_name'] ?? 'N/A',
                'Marked At' => date('Y-m-d H:i', strtotime($record['marked_at']))
            ];
            $flat_data[] = $flat_row;
        }
    } elseif (isset($data['daily_trend'])) {
        // Summary report - daily trend
        foreach ($data['daily_trend'] as $trend) {
            $flat_row = [
                'Date' => $trend['date'],
                'Total Sessions' => $trend['total_sessions'],
                'Present Count' => $trend['present_count'],
                'Attendance Percentage' => $trend['attendance_percentage'] . '%'
            ];
            $flat_data[] = $flat_row;
        }
    }
    
    return $flat_data;
}

// Output Excel format
function outputExcel($data, $filename) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xls"');
    
    echo '<html><body>';
    echo '<table border="1">';
    
    $flat_data = flattenDataForCSV($data);
    
    if (!empty($flat_data)) {
        // Write headers
        echo '<tr>';
        foreach (array_keys($flat_data[0]) as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
        
        // Write data rows
        foreach ($flat_data as $row) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell) . '</td>';
            }
            echo '</tr>';
        }
    }
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

// Output PDF format (simplified - in production, use a library like TCPDF or Dompdf)
function outputPDF($data, $filename) {
    // For now, return JSON with a message
    // In production, implement PDF generation here
    $data['export_note'] = 'PDF export is currently unavailable. Please use JSON, CSV, or Excel format.';
    echo json_encode($data, JSON_PRETTY_PRINT);
}

// Output XML format
function outputXML($data, $filename) {
    header('Content-Type: application/xml');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xml"');
    
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><report></report>');
    
    // Convert array to XML
    arrayToXml($data, $xml);
    
    echo $xml->asXML();
    exit;
}

// Convert array to XML
function arrayToXml($data, &$xml) {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if (is_numeric($key)) {
                $key = 'item_' . $key;
            }
            $subnode = $xml->addChild($key);
            arrayToXml($value, $subnode);
        } else {
            if (is_numeric($key)) {
                $key = 'item_' . $key;
            }
            $xml->addChild($key, htmlspecialchars($value));
        }
    }
}

// Get attendance status label
function getAttendanceStatusLabel($percentage) {
    if ($percentage >= 90) return 'Excellent';
    if ($percentage >= 75) return 'Good';
    if ($percentage >= 60) return 'Fair';
    if ($percentage >= 50) return 'Poor';
    return 'Critical';
}

// Placeholder functions for other report types - implement as needed
function generateCourseReport($format) {
    global $conn;
    
    // Implement course report logic
    $report_data = [
        'report_type' => 'course',
        'message' => 'Course report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'course_report');
}

function generateDepartmentReport($format) {
    global $conn;
    
    // Implement department report logic
    $report_data = [
        'report_type' => 'department',
        'message' => 'Department report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'department_report');
}

function generateTeacherReport($format) {
    global $conn;
    
    // Implement teacher report logic
    $report_data = [
        'report_type' => 'teacher',
        'message' => 'Teacher report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'teacher_report');
}

function generateDailyReport($format) {
    global $conn;
    
    $date = $_GET['date'] ?? date('Y-m-d');
    
    $report_data = [
        'report_type' => 'daily',
        'date' => $date,
        'message' => 'Daily report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'daily_report_' . $date);
}

function generateWeeklyReport($format) {
    global $conn;
    
    $week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
    
    $report_data = [
        'report_type' => 'weekly',
        'week_start' => $week_start,
        'message' => 'Weekly report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'weekly_report_' . $week_start);
}

function generateMonthlyReport($format) {
    global $conn;
    
    $month = $_GET['month'] ?? date('Y-m');
    
    $report_data = [
        'report_type' => 'monthly',
        'month' => $month,
        'message' => 'Monthly report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'monthly_report_' . $month);
}

function generateSemesterReport($format) {
    global $conn;
    
    $semester = $_GET['semester'] ?? date('Y') . '-S1';
    
    $report_data = [
        'report_type' => 'semester',
        'semester' => $semester,
        'message' => 'Semester report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'semester_report_' . $semester);
}

function generateAnnualReport($format) {
    global $conn;
    
    $year = $_GET['year'] ?? date('Y');
    
    $report_data = [
        'report_type' => 'annual',
        'year' => $year,
        'message' => 'Annual report functionality is under development',
        'generated_at' => date('c')
    ];
    
    outputReport($report_data, $format, 'annual_report_' . $year);
}

function exportReport($format) {
    $export_type = $_GET['export_type'] ?? 'summary';
    $data = json_decode($_GET['data'] ?? '{}', true);
    
    if (empty($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'No data provided for export']);
        exit;
    }
    
    $filename = $export_type . '_export_' . date('Y-m-d_H-i-s');
    outputReport($data, $format, $filename);
}

// Close database connection
$conn->close();
?>