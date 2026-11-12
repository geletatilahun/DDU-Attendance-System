<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

checkRole(['department_head']);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$department_head_id = (int) $_SESSION['user_id'];

// Fetch department head info (similar to create_schedule.php)
// ... (same code as above)

// Fetch schedules with filters
$filter_semester = $_GET['semester'] ?? '';
$filter_teacher = $_GET['teacher_id'] ?? '';
$filter_course = $_GET['course_id'] ?? '';
$filter_day = $_GET['day'] ?? '';
$filter_active = $_GET['active'] ?? '1';

$schedules_sql = "
    SELECT 
        cs.*,
        c.course_code,
        c.course_name,
        c.semester,
        u.full_name as teacher_name,
        u.email as teacher_email,
        CONCAT(cs.start_time, ' - ', cs.end_time) as time_slot,
        d.department_name
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN users u ON cs.teacher_id = u.user_id
    JOIN departments d ON c.department_id = d.department_id
    WHERE c.department_id = ?
";

$params = [$department_head['department_id']];
$types = "i";

if ($filter_semester) {
    $schedules_sql .= " AND c.semester = ?";
    $params[] = $filter_semester;
    $types .= "i";
}

if ($filter_teacher) {
    $schedules_sql .= " AND cs.teacher_id = ?";
    $params[] = $filter_teacher;
    $types .= "i";
}

if ($filter_course) {
    $schedules_sql .= " AND cs.course_id = ?";
    $params[] = $filter_course;
    $types .= "i";
}

if ($filter_day) {
    $schedules_sql .= " AND cs.day_of_week = ?";
    $params[] = $filter_day;
    $types .= "s";
}

if ($filter_active !== '') {
    $schedules_sql .= " AND cs.is_active = ?";
    $params[] = $filter_active;
    $types .= "i";
}

$schedules_sql .= " ORDER BY cs.day_of_week, cs.start_time";

$schedules_stmt = $conn->prepare($schedules_sql);
$schedules_stmt->bind_param($types, ...$params);
$schedules_stmt->execute();
$schedules_result = $schedules_stmt->get_result();

// ... (rest of the view page similar to create_schedule but focused on viewing)