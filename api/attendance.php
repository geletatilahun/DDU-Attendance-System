<?php
/**
 * Attendance API Endpoint
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Authenticate API key
function authenticateAPI() {
    $headers = getallheaders();
    
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(['error' => 'API key required']);
        exit();
    }
    
    $api_key = str_replace('Bearer ', '', $headers['Authorization']);
    
    // Verify API key in database
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM api_keys WHERE api_key = ? AND is_active = 1 AND expires_at > NOW()");
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired API key']);
        exit();
    }
    
    $api_data = $result->fetch_assoc();
    
    // Check rate limiting
    checkRateLimit($api_data['api_key']);
    
    return $api_data;
}

// Check rate limiting
function checkRateLimit($api_key) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $now = time();
    $window = 3600; // 1 hour window
    
    // This is a simplified rate limiting - implement Redis or database-based in production
    $cache_file = "api_cache/{$api_key}_{$ip}.json";
    
    if (file_exists($cache_file)) {
        $cache = json_decode(file_get_contents($cache_file), true);
        
        if ($now - $cache['timestamp'] < $window) {
            if ($cache['requests'] >= 100) { // 100 requests per hour
                http_response_code(429);
                echo json_encode(['error' => 'Rate limit exceeded']);
                exit();
            }
            $cache['requests']++;
        } else {
            $cache = ['timestamp' => $now, 'requests' => 1];
        }
    } else {
        $cache = ['timestamp' => $now, 'requests' => 1];
    }
    
    file_put_contents($cache_file, json_encode($cache));
}

// Main API routing
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';
$action = $_GET['action'] ?? '';

// Authenticate for all endpoints except health check
if ($endpoint !== 'health') {
    $api_data = authenticateAPI();
}

switch ($endpoint) {
    case 'health':
        handleHealthCheck();
        break;
    case 'attendance':
        handleAttendance($method, $action);
        break;
    case 'students':
        handleStudents($method, $action);
        break;
    case 'courses':
        handleCourses($method, $action);
        break;
    case 'reports':
        handleReports($method, $action);
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint not found']);
        break;
}

// Health check endpoint
function handleHealthCheck() {
    global $conn;
    
    $response = [
        'status' => 'online',
        'timestamp' => date('c'),
        'database' => $conn->ping() ? 'connected' : 'disconnected',
        'version' => '1.0.0'
    ];
    
    echo json_encode($response);
}

// Attendance endpoints
function handleAttendance($method, $action) {
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                getAttendanceList();
            } elseif ($action === 'details') {
                getAttendanceDetails();
            } else {
                getAttendanceSummary();
            }
            break;
        case 'POST':
            if ($action === 'mark') {
                markAttendance();
            } elseif ($action === 'bulk') {
                markBulkAttendance();
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;
        case 'PUT':
            updateAttendance();
            break;
        case 'DELETE':
            deleteAttendance();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

// Get attendance list
function getAttendanceList() {
    global $conn;
    
    $filters = [];
    $where = "WHERE 1=1";
    
    // Apply filters
    if (isset($_GET['course_id'])) {
        $course_id = intval($_GET['course_id']);
        $where .= " AND a.course_id = $course_id";
        $filters[] = "course_id=$course_id";
    }
    
    if (isset($_GET['student_id'])) {
        $student_id = intval($_GET['student_id']);
        $where .= " AND a.student_id = $student_id";
        $filters[] = "student_id=$student_id";
    }
    
    if (isset($_GET['teacher_id'])) {
        $teacher_id = intval($_GET['teacher_id']);
        $where .= " AND a.teacher_id = $teacher_id";
        $filters[] = "teacher_id=$teacher_id";
    }
    
    if (isset($_GET['start_date'])) {
        $start_date = $conn->real_escape_string($_GET['start_date']);
        $where .= " AND a.attendance_date >= '$start_date'";
        $filters[] = "start_date=$start_date";
    }
    
    if (isset($_GET['end_date'])) {
        $end_date = $conn->real_escape_string($_GET['end_date']);
        $where .= " AND a.attendance_date <= '$end_date'";
        $filters[] = "end_date=$end_date";
    }
    
    if (isset($_GET['status'])) {
        $status = $conn->real_escape_string($_GET['status']);
        $where .= " AND a.status = '$status'";
        $filters[] = "status=$status";
    }
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    
    // Get total count
    $count_result = $conn->query("SELECT COUNT(*) as total FROM attendance a $where");
    $total = $count_result->fetch_assoc()['total'];
    
    // Get data
    $sql = "
        SELECT 
            a.attendance_id,
            a.course_id,
            c.course_code,
            c.course_name,
            a.student_id,
            s.student_id_no,
            s.full_name as student_name,
            a.teacher_id,
            u.full_name as teacher_name,
            a.attendance_date,
            a.session_type,
            a.status,
            a.check_in_time,
            a.check_out_time,
            a.notes,
            a.marked_at
        FROM attendance a
        JOIN courses c ON a.course_id = c.course_id
        JOIN students s ON a.student_id = s.student_id
        LEFT JOIN users u ON a.teacher_id = u.user_id
        $where
        ORDER BY a.attendance_date DESC, a.marked_at DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $result = $conn->query($sql);
    $data = [];
    
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    // Response
    $response = [
        'success' => true,
        'data' => $data,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ],
        'filters' => $filters,
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
}

// Get attendance summary
function getAttendanceSummary() {
    global $conn;
    
    $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : null;
    $student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    
    $where = "WHERE a.attendance_date BETWEEN '$start_date' AND '$end_date'";
    
    if ($course_id) {
        $where .= " AND a.course_id = $course_id";
    }
    
    if ($student_id) {
        $where .= " AND a.student_id = $student_id";
    }
    
    $sql = "
        SELECT 
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'excused' THEN 1 ELSE 0 END) as excused_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage,
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(DISTINCT a.course_id) as total_courses,
            COUNT(DISTINCT a.teacher_id) as total_teachers
        FROM attendance a
        $where
    ";
    
    $result = $conn->query($sql);
    $summary = $result->fetch_assoc();
    
    // Daily trend
    $trend_sql = "
        SELECT 
            DATE(a.attendance_date) as date,
            COUNT(*) as total,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as percentage
        FROM attendance a
        $where
        GROUP BY DATE(a.attendance_date)
        ORDER BY date
    ";
    
    $trend_result = $conn->query($trend_sql);
    $trend_data = [];
    
    while ($row = $trend_result->fetch_assoc()) {
        $trend_data[] = $row;
    }
    
    $response = [
        'success' => true,
        'summary' => $summary,
        'trend' => $trend_data,
        'period' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ],
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
}

// Mark attendance
function markAttendance() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required = ['course_id', 'student_id', 'attendance_date', 'status'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            http_response_code(400);
            echo json_encode(['error' => "Missing required field: $field"]);
            return;
        }
    }
    
    $course_id = intval($data['course_id']);
    $student_id = intval($data['student_id']);
    $attendance_date = $conn->real_escape_string($data['attendance_date']);
    $status = $conn->real_escape_string($data['status']);
    $session_type = $conn->real_escape_string($data['session_type'] ?? 'lecture');
    $teacher_id = intval($data['teacher_id'] ?? 0);
    $notes = $conn->real_escape_string($data['notes'] ?? '');
    $check_in_time = $data['check_in_time'] ?? null;
    $check_out_time = $data['check_out_time'] ?? null;
    
    // Validate status
    $valid_statuses = ['present', 'absent', 'late', 'excused'];
    if (!in_array($status, $valid_statuses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid status value']);
        return;
    }
    
    // Check if attendance already exists
    $check_sql = "
        SELECT attendance_id FROM attendance 
        WHERE course_id = ? AND student_id = ? AND attendance_date = ? AND session_type = ?
    ";
    
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("iiss", $course_id, $student_id, $attendance_date, $session_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Update existing record
        $attendance = $result->fetch_assoc();
        $attendance_id = $attendance['attendance_id'];
        
        $update_sql = "
            UPDATE attendance SET 
                status = ?,
                teacher_id = ?,
                notes = ?,
                check_in_time = ?,
                check_out_time = ?,
                marked_by = ?,
                marked_at = NOW()
            WHERE attendance_id = ?
        ";
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sissii", 
            $status, 
            $teacher_id, 
            $notes, 
            $check_in_time, 
            $check_out_time,
            $teacher_id,
            $attendance_id
        );
    } else {
        // Insert new record
        $insert_sql = "
            INSERT INTO attendance (
                course_id, student_id, teacher_id, attendance_date, 
                session_type, status, check_in_time, check_out_time, 
                notes, marked_by, marked_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ";
        
        $stmt = $conn->prepare($insert_sql);
        $stmt->bind_param("iiissssssi",
            $course_id, $student_id, $teacher_id, $attendance_date,
            $session_type, $status, $check_in_time, $check_out_time,
            $notes, $teacher_id
        );
    }
    
    if ($stmt->execute()) {
        $attendance_id = $stmt->insert_id ?? $attendance_id;
        
        // Update attendance summary
        updateAttendanceSummary($student_id, $course_id);
        
        // Log activity
        logActivity($teacher_id, 'attendance_marked', "Marked attendance for student $student_id");
        
        $response = [
            'success' => true,
            'message' => 'Attendance marked successfully',
            'attendance_id' => $attendance_id,
            'timestamp' => date('c')
        ];
        
        echo json_encode($response);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to mark attendance', 'details' => $stmt->error]);
    }
    
    $stmt->close();
}

// Mark bulk attendance
function markBulkAttendance() {
    global $conn;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['records']) || !is_array($data['records'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing or invalid records array']);
        return;
    }
    
    $course_id = intval($data['course_id'] ?? 0);
    $attendance_date = $conn->real_escape_string($data['attendance_date'] ?? date('Y-m-d'));
    $session_type = $conn->real_escape_string($data['session_type'] ?? 'lecture');
    $teacher_id = intval($data['teacher_id'] ?? 0);
    
    if (!$course_id || !$teacher_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing course_id or teacher_id']);
        return;
    }
    
    $conn->begin_transaction();
    
    try {
        // Create attendance session
        $session_sql = "
            INSERT INTO attendance_sessions (course_id, teacher_id, session_date, session_type, created_by)
            VALUES (?, ?, ?, ?, ?)
        ";
        
        $stmt = $conn->prepare($session_sql);
        $stmt->bind_param("iissi", $course_id, $teacher_id, $attendance_date, $session_type, $teacher_id);
        $stmt->execute();
        $session_id = $conn->insert_id;
        $stmt->close();
        
        // Process each attendance record
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        foreach ($data['records'] as $index => $record) {
            $student_id = intval($record['student_id'] ?? 0);
            $status = $conn->real_escape_string($record['status'] ?? 'absent');
            
            if (!$student_id) {
                $error_count++;
                $errors[] = "Record $index: Missing student_id";
                continue;
            }
            
            // Check if attendance already exists
            $check_sql = "
                SELECT attendance_id FROM attendance 
                WHERE course_id = ? AND student_id = ? AND attendance_date = ? AND session_type = ?
            ";
            
            $stmt = $conn->prepare($check_sql);
            $stmt->bind_param("iiss", $course_id, $student_id, $attendance_date, $session_type);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing
                $attendance = $result->fetch_assoc();
                $attendance_id = $attendance['attendance_id'];
                
                $update_sql = "
                    UPDATE attendance SET 
                        status = ?,
                        teacher_id = ?,
                        marked_by = ?,
                        marked_at = NOW()
                    WHERE attendance_id = ?
                ";
                
                $stmt = $conn->prepare($update_sql);
                $stmt->bind_param("siii", $status, $teacher_id, $teacher_id, $attendance_id);
            } else {
                // Insert new
                $insert_sql = "
                    INSERT INTO attendance (
                        course_id, student_id, teacher_id, attendance_date, 
                        session_type, status, marked_by, marked_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ";
                
                $stmt = $conn->prepare($insert_sql);
                $stmt->bind_param("iiisssi",
                    $course_id, $student_id, $teacher_id, $attendance_date,
                    $session_type, $status, $teacher_id
                );
            }
            
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = "Record $index: " . $stmt->error;
            }
            
            $stmt->close();
        }
        
        // Update all attendance summaries for this course
        updateAllAttendanceSummaries($course_id);
        
        $conn->commit();
        
        $response = [
            'success' => true,
            'message' => 'Bulk attendance processed',
            'summary' => [
                'total' => count($data['records']),
                'success' => $success_count,
                'errors' => $error_count,
                'session_id' => $session_id
            ],
            'errors' => $errors,
            'timestamp' => date('c')
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        $conn->rollback();
        
        http_response_code(500);
        echo json_encode(['error' => 'Bulk attendance failed', 'details' => $e->getMessage()]);
    }
}

// Update attendance summary
function updateAttendanceSummary($student_id, $course_id) {
    global $conn;
    
    $sql = "
        INSERT INTO attendance_summary (student_id, course_id, academic_year, semester, 
            total_sessions, present_count, attendance_percentage)
        SELECT 
            a.student_id,
            a.course_id,
            YEAR(CURDATE()),
            (SELECT semester FROM courses WHERE course_id = a.course_id),
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        WHERE a.student_id = ? AND a.course_id = ?
        GROUP BY a.student_id, a.course_id
        ON DUPLICATE KEY UPDATE
            total_sessions = VALUES(total_sessions),
            present_count = VALUES(present_count),
            attendance_percentage = VALUES(attendance_percentage),
            last_updated = CURRENT_TIMESTAMP
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $student_id, $course_id);
    $stmt->execute();
    $stmt->close();
}

// Update all attendance summaries for a course
function updateAllAttendanceSummaries($course_id) {
    global $conn;
    
    $sql = "
        INSERT INTO attendance_summary (student_id, course_id, academic_year, semester, 
            total_sessions, present_count, attendance_percentage)
        SELECT 
            a.student_id,
            a.course_id,
            YEAR(CURDATE()),
            (SELECT semester FROM courses WHERE course_id = a.course_id),
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        WHERE a.course_id = ?
        GROUP BY a.student_id, a.course_id
        ON DUPLICATE KEY UPDATE
            total_sessions = VALUES(total_sessions),
            present_count = VALUES(present_count),
            attendance_percentage = VALUES(attendance_percentage),
            last_updated = CURRENT_TIMESTAMP
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $stmt->close();
}

// Students endpoints
function handleStudents($method, $action) {
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                getStudentList();
            } elseif ($action === 'details') {
                getStudentDetails();
            } elseif ($action === 'attendance') {
                getStudentAttendance();
            } else {
                getAllStudents();
            }
            break;
        case 'POST':
            createStudent();
            break;
        case 'PUT':
            updateStudent();
            break;
        case 'DELETE':
            deleteStudent();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

// Get all students
function getAllStudents() {
    global $conn;
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : 50;
    $offset = ($page - 1) * $limit;
    
    // Filters
    $where = "WHERE s.is_active = 1";
    $filters = [];
    
    if (isset($_GET['department_id'])) {
        $dept_id = intval($_GET['department_id']);
        $where .= " AND s.department_id = $dept_id";
        $filters[] = "department_id=$dept_id";
    }
    
    if (isset($_GET['year_of_study'])) {
        $year = intval($_GET['year_of_study']);
        $where .= " AND s.year_of_study = $year";
        $filters[] = "year_of_study=$year";
    }
    
    if (isset($_GET['program'])) {
        $program = $conn->real_escape_string($_GET['program']);
        $where .= " AND s.program = '$program'";
        $filters[] = "program=$program";
    }
    
    // Search
    if (isset($_GET['search'])) {
        $search = $conn->real_escape_string($_GET['search']);
        $where .= " AND (s.student_id_no LIKE '%$search%' OR s.full_name LIKE '%$search%' OR s.email LIKE '%$search%')";
        $filters[] = "search=$search";
    }
    
    // Total count
    $count_sql = "SELECT COUNT(*) as total FROM students s $where";
    $count_result = $conn->query($count_sql);
    $total = $count_result->fetch_assoc()['total'];
    
    // Get students
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
            s.program,
            s.year_of_study,
            s.semester,
            s.profile_image,
            s.created_at,
            s.is_active
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        $where
        ORDER BY s.full_name
        LIMIT $limit OFFSET $offset
    ";
    
    $result = $conn->query($sql);
    $students = [];
    
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    $response = [
        'success' => true,
        'data' => $students,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ],
        'filters' => $filters,
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
}

// Reports endpoints
function handleReports($method, $action) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        return;
    }
    
    switch ($action) {
        case 'summary':
            generateSummaryReport();
            break;
        case 'student':
            generateStudentReport();
            break;
        case 'course':
            generateCourseReport();
            break;
        case 'department':
            generateDepartmentReport();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid report type']);
    }
}

// Generate summary report
function generateSummaryReport() {
    global $conn;
    
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $department_id = $_GET['department_id'] ?? null;
    $course_id = $_GET['course_id'] ?? null;
    
    $where = "WHERE a.attendance_date BETWEEN '$start_date' AND '$end_date'";
    
    if ($department_id) {
        $where .= " AND s.department_id = $department_id";
    }
    
    if ($course_id) {
        $where .= " AND a.course_id = $course_id";
    }
    
    $sql = "
        SELECT 
            COUNT(DISTINCT a.student_id) as total_students,
            COUNT(DISTINCT a.course_id) as total_courses,
            COUNT(DISTINCT a.teacher_id) as total_teachers,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as overall_percentage,
            
            -- Daily average
            COUNT(DISTINCT a.attendance_date) as total_days,
            ROUND(COUNT(*) / COUNT(DISTINCT a.attendance_date), 2) as avg_sessions_per_day,
            
            -- Student averages
            ROUND(AVG(student_percentage), 2) as avg_student_percentage,
            COUNT(CASE WHEN student_percentage >= 75 THEN 1 END) as satisfactory_students,
            COUNT(CASE WHEN student_percentage < 75 AND student_percentage >= 50 THEN 1 END) as warning_students,
            COUNT(CASE WHEN student_percentage < 50 THEN 1 END) as critical_students
            
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN (
            SELECT 
                student_id,
                ROUND((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as student_percentage
            FROM attendance
            WHERE attendance_date BETWEEN '$start_date' AND '$end_date'
            GROUP BY student_id
        ) as student_stats ON a.student_id = student_stats.student_id
        $where
    ";
    
    $result = $conn->query($sql);
    $summary = $result->fetch_assoc();
    
    // Top and bottom performers
    $performers_sql = "
        SELECT 
            s.student_id,
            s.student_id_no,
            s.full_name,
            d.department_name,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        LEFT JOIN departments d ON s.department_id = d.department_id
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
    
    // Bottom performers (reverse order)
    $bottom_result = $conn->query(str_replace('DESC', 'ASC', $performers_sql));
    $bottom_performers = [];
    
    while ($row = $bottom_result->fetch_assoc()) {
        $bottom_performers[] = $row;
    }
    
    $response = [
        'success' => true,
        'summary' => $summary,
        'top_performers' => $top_performers,
        'bottom_performers' => $bottom_performers,
        'period' => [
            'start_date' => $start_date,
            'end_date' => $end_date
        ],
        'timestamp' => date('c')
    ];
    
    echo json_encode($response);
}

// Generate CSV export
function generateCSVReport($data, $filename) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        
        // Add data rows
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

// Log API request
function logAPIRequest($api_key, $endpoint, $method, $status, $response_time) {
    global $conn;
    
    $sql = "
        INSERT INTO api_logs (api_key, endpoint, method, status, response_time, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssiiss", 
        $api_key,
        $endpoint,
        $method,
        $status,
        $response_time,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    );
    
    $stmt->execute();
    $stmt->close();
}

// Log response at the end
register_shutdown_function(function() {
    $response_time = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
    $status = http_response_code();
    
    if (isset($api_data)) {
        logAPIRequest($api_data['api_key'], $endpoint, $method, $status, $response_time);
    }
});
?>