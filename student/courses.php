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
$current_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$filter_department = isset($_GET['department']) ? $_GET['department'] : null;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

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
   FETCH ENROLLED COURSES
====================================================== */
$enrolled_courses_sql = "
    SELECT 
        c.course_id,
        c.course_code,
        c.course_name,
        c.course_description,
        c.credits,
        c.semester,
        c.course_type,
        c.course_color,
        c.total_hours,
        c.prerequisites,
        c.learning_outcomes,
        c.reference_books,
        u.full_name as teacher_name,
        u.email as teacher_email,
        u.profile_image as teacher_image,
        d.department_name,
        d.department_code,
        cs.enrollment_date,
        cs.status as enrollment_status,
        COUNT(DISTINCT cos.student_id) as total_students,
        AVG(a.marks) as avg_grade,
        COUNT(DISTINCT a.assignment_id) as total_assignments
    FROM course_students cs
    JOIN courses c ON cs.course_id = c.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN course_students cos ON c.course_id = cos.course_id
    LEFT JOIN assignments a ON c.course_id = a.course_id
    WHERE cs.student_id = ?
";

$params = [$student_id];
$param_types = "i";

// Apply filters
if ($current_semester) {
    $enrolled_courses_sql .= " AND c.semester = ?";
    $params[] = $current_semester;
    $param_types .= "i";
}

if ($filter_department) {
    $enrolled_courses_sql .= " AND c.department_id = ?";
    $params[] = $filter_department;
    $param_types .= "i";
}

if ($search_query) {
    $enrolled_courses_sql .= " AND (c.course_code LIKE ? OR c.course_name LIKE ? OR c.course_description LIKE ?)";
    $search_term = "%$search_query%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= "sss";
}

$enrolled_courses_sql .= "
    GROUP BY c.course_id, u.user_id
    ORDER BY c.semester, c.course_code
";

$enrolled_stmt = $conn->prepare($enrolled_courses_sql);
if ($enrolled_stmt) {
    if (count($params) > 1) {
        $enrolled_stmt->bind_param($param_types, ...$params);
    } else {
        $enrolled_stmt->bind_param($param_types, $params[0]);
    }
    $enrolled_stmt->execute();
    $enrolled_result = $enrolled_stmt->get_result();
    $total_enrolled = $enrolled_result->num_rows;
} else {
    $enrolled_result = null;
    $total_enrolled = 0;
}

/* ======================================================
   FETCH COURSE STATISTICS
====================================================== */
$stats_sql = "
    SELECT 
        COUNT(DISTINCT c.course_id) as total_courses,
        SUM(c.credits) as total_credits,
        AVG(c.credits) as avg_credits,
        c.semester,
        COUNT(DISTINCT c.course_id) as courses_per_semester
    FROM course_students cs
    JOIN courses c ON cs.course_id = c.course_id
    WHERE cs.student_id = ?
    GROUP BY c.semester
    ORDER BY c.semester
";

$stats_stmt = $conn->prepare($stats_sql);
if ($stats_stmt) {
    $stats_stmt->bind_param("i", $student_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->get_result();
    $semester_stats = [];
    $total_credits = 0;
    
    while ($stat = $stats_result->fetch_assoc()) {
        $semester_stats[$stat['semester']] = $stat;
        $total_credits += $stat['total_credits'];
    }
}

/* ======================================================
   FETCH AVAILABLE COURSES (FOR ENROLLMENT)
====================================================== */
$available_courses_sql = "
    SELECT 
        c.*,
        d.department_name,
        d.department_code,
        COUNT(DISTINCT cs.student_id) as enrolled_students,
        u.full_name as teacher_name
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    LEFT JOIN course_students cs ON c.course_id = cs.course_id
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE c.is_active = 1
      AND c.course_id NOT IN (
          SELECT course_id FROM course_students WHERE student_id = ?
      )
";

$available_params = [$student_id];
$available_param_types = "i";

if ($current_semester) {
    $available_courses_sql .= " AND c.semester = ?";
    $available_params[] = $current_semester;
    $available_param_types .= "i";
}

if ($filter_department) {
    $available_courses_sql .= " AND c.department_id = ?";
    $available_params[] = $filter_department;
    $available_param_types .= "i";
}

if ($search_query) {
    $available_courses_sql .= " AND (c.course_code LIKE ? OR c.course_name LIKE ?)";
    $search_term = "%$search_query%";
    $available_params[] = $search_term;
    $available_params[] = $search_term;
    $available_param_types .= "ss";
}

$available_courses_sql .= "
    GROUP BY c.course_id
    ORDER BY c.semester, c.course_code
    LIMIT 10
";

$available_stmt = $conn->prepare($available_courses_sql);
if ($available_stmt) {
    if (count($available_params) > 1) {
        $available_stmt->bind_param($available_param_types, ...$available_params);
    } else {
        $available_stmt->bind_param($available_param_types, $available_params[0]);
    }
    $available_stmt->execute();
    $available_result = $available_stmt->get_result();
}

/* ======================================================
   FETCH DEPARTMENTS FOR FILTER
====================================================== */
$departments_sql = "SELECT department_id, department_name, department_code FROM departments ORDER BY department_name";
$departments_result = $conn->query($departments_sql);

/* ======================================================
   FETCH RECENT GRADES
====================================================== */
$recent_grades_sql = "
    SELECT 
        a.*,
        c.course_code,
        c.course_name,
        c.course_color,
        DATE_FORMAT(a.submission_date, '%b %d, %Y') as submission_date_formatted,
        CASE 
            WHEN a.marks >= 90 THEN 'A+'
            WHEN a.marks >= 80 THEN 'A'
            WHEN a.marks >= 70 THEN 'B+'
            WHEN a.marks >= 60 THEN 'B'
            WHEN a.marks >= 50 THEN 'C'
            WHEN a.marks >= 40 THEN 'D'
            ELSE 'F'
        END as grade_letter,
        CASE 
            WHEN a.marks >= 90 THEN 'success'
            WHEN a.marks >= 70 THEN 'info'
            WHEN a.marks >= 50 THEN 'warning'
            ELSE 'danger'
        END as grade_color
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    WHERE a.student_id = ?
      AND a.marks IS NOT NULL
    ORDER BY a.submission_date DESC
    LIMIT 5
";

$grades_stmt = $conn->prepare($recent_grades_sql);
if ($grades_stmt) {
    $grades_stmt->bind_param("i", $student_id);
    $grades_stmt->execute();
    $grades_result = $grades_stmt->get_result();
}

/* ======================================================
   FETCH UPCOMING COURSE DEADLINES
====================================================== */
$deadlines_sql = "
    SELECT 
        a.*,
        c.course_code,
        c.course_name,
        c.course_color,
        DATE_FORMAT(a.due_date, '%a, %b %d') as due_date_formatted,
        DATEDIFF(a.due_date, CURDATE()) as days_left
    FROM assignments a
    JOIN courses c ON a.course_id = c.course_id
    JOIN course_students cs ON c.course_id = cs.course_id
    WHERE cs.student_id = ?
      AND a.due_date >= CURDATE()
      AND a.is_active = 1
    ORDER BY a.due_date
    LIMIT 5
";

$deadlines_stmt = $conn->prepare($deadlines_sql);
if ($deadlines_stmt) {
    $deadlines_stmt->bind_param("i", $student_id);
    $deadlines_stmt->execute();
    $deadlines_result = $deadlines_stmt->get_result();
}

/* ======================================================
   FETCH COURSE RESOURCES
====================================================== */
$resources_sql = "
    SELECT 
        r.*,
        c.course_code,
        c.course_name,
        u.full_name as uploaded_by,
        DATE_FORMAT(r.upload_date, '%b %d, %Y') as upload_date_formatted,
        CASE 
            WHEN r.file_type LIKE 'pdf%' THEN 'pdf'
            WHEN r.file_type LIKE 'doc%' THEN 'word'
            WHEN r.file_type LIKE 'ppt%' THEN 'powerpoint'
            WHEN r.file_type LIKE 'image%' THEN 'image'
            WHEN r.file_type LIKE 'video%' THEN 'video'
            ELSE 'file'
        END as file_category
    FROM course_resources r
    JOIN courses c ON r.course_id = c.course_id
    LEFT JOIN users u ON r.uploaded_by = u.user_id
    WHERE r.course_id IN (
        SELECT course_id FROM course_students WHERE student_id = ?
    )
      AND r.is_active = 1
    ORDER BY r.upload_date DESC
    LIMIT 5
";

$resources_stmt = $conn->prepare($resources_sql);
if ($resources_stmt) {
    $resources_stmt->bind_param("i", $student_id);
    $resources_stmt->execute();
    $resources_result = $resources_stmt->get_result();
}

$page_title = "My Courses";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - DDU Student Portal</title>
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
        
        .stat-card {
            border-radius: 15px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 25px;
            overflow: hidden;
            position: relative;
            cursor: pointer;
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
        
        .course-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            border: 1px solid #f0f0f0;
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .course-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        .course-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--dept-color);
        }
        
        .course-badge {
            background: var(--dept-color);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .course-color-indicator {
            width: 10px;
            height: 100%;
            position: absolute;
            left: 0;
            top: 0;
            border-radius: 20px 0 0 20px;
        }
        
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .resource-card {
            background: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--dept-color);
            transition: all 0.3s;
        }
        
        .resource-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .file-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .file-icon.pdf { background: #FF6B6B; }
        .file-icon.word { background: #2B579A; }
        .file-icon.powerpoint { background: #D24726; }
        .file-icon.image { background: #4CAF50; }
        .file-icon.video { background: #9C27B0; }
        .file-icon.file { background: #607D8B; }
        
        .semester-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .semester-badge:hover {
            transform: scale(1.05);
        }
        
        .semester-badge.active {
            background: var(--dept-color);
            color: white;
            box-shadow: 0 5px 15px rgba(0,51,102,0.3);
        }
        
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .progress-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: conic-gradient(var(--ddu-green) 0% 75%, #e9ecef 75% 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .progress-circle::before {
            content: '';
            position: absolute;
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 50%;
        }
        
        .progress-circle span {
            position: relative;
            z-index: 1;
            font-weight: bold;
            font-size: 1.2rem;
        }
        
        .teacher-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .credit-badge {
            background: linear-gradient(135deg, var(--ddu-green), #20c997);
            color: white;
            padding: 5px 15px;
            border-radius: 15px;
            font-weight: 600;
        }
        
        .course-meta {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px;
            margin-top: 15px;
        }
        
        .action-btn {
            padding: 8px 20px;
            border-radius: 20px;
            border: 2px solid var(--dept-color);
            background: white;
            color: var(--ddu-blue);
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .action-btn:hover {
            background: var(--dept-color);
            color: white;
            transform: translateY(-2px);
        }
        
        .modal-course {
            max-width: 800px;
        }
        
        .course-details-modal .modal-header {
            background: linear-gradient(135deg, var(--dept-color), #0055aa);
            color: white;
        }
        
        .tab-content {
            padding: 20px 0;
        }
        
        .nav-tabs .nav-link {
            color: var(--ddu-blue);
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            color: var(--dept-color);
            border-bottom: 3px solid var(--dept-color);
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
    </style>
</head>
<body>
    <!-- Sidebar (Same as dashboard) -->
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
            <a href="courses.php" class="nav-link active">
                <i class="fas fa-book"></i> Courses
            </a>
            <a href="schedule.php" class="nav-link">
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
                    <i class="fas fa-book me-2"></i> My Courses
                </h3>
                <p class="text-muted mb-0">
                    Manage and explore your academic courses
                </p>
            </div>
            
            <div class="d-flex align-items-center">
                <a href="dashboard.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollModal">
                    <i class="fas fa-plus me-1"></i> Enroll in Course
                </button>
            </div>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h1 class="fw-bold mb-3">Course Management</h1>
                    <p class="mb-0">
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-book-open me-1"></i> <?= $total_enrolled ?> Enrolled Courses
                        </span>
                        <span class="badge bg-light text-dark me-2 p-2">
                            <i class="fas fa-graduation-cap me-1"></i> <?= $total_credits ?? 0 ?> Total Credits
                        </span>
                        <span class="badge bg-light text-dark p-2">
                            <i class="fas fa-university me-1"></i> <?= htmlspecialchars($student['department_name'] ?? 'Not assigned') ?>
                        </span>
                    </p>
                </div>
                <div class="col-md-4 text-center">
                    <div class="progress-circle">
                        <span>
                            <?= $total_enrolled > 0 ? round(($total_enrolled / ($total_enrolled + ($available_result ? $available_result->num_rows : 0))) * 100) : 0 ?>%
                        </span>
                    </div>
                    <small class="text-white-50 mt-2 d-block">Course Completion Progress</small>
                </div>
            </div>
        </div>
        
        <!-- Semester Filter -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i> Filter Courses</h5>
            
            <!-- Search Bar -->
            <form method="GET" action="" class="mb-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <span class="input-group-text bg-light border-end-0">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" name="search" class="form-control border-start-0" 
                                   placeholder="Search courses by code, name or description..." 
                                   value="<?= htmlspecialchars($search_query) ?>">
                            <?php if ($search_query): ?>
                                <a href="courses.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="semester" class="form-select" onchange="this.form.submit()">
                            <option value="">All Semesters</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?= $i ?>" <?= $current_semester == $i ? 'selected' : '' ?>>
                                    Semester <?= $i ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="department" class="form-select" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php while ($dept = $departments_result->fetch_assoc()): ?>
                                <option value="<?= $dept['department_id'] ?>" 
                                        <?= $filter_department == $dept['department_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['department_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </form>
            
            <!-- Semester Badges -->
            <div class="mb-3">
                <small class="text-muted mb-2 d-block">Filter by Semester:</small>
                <div class="d-flex flex-wrap gap-2">
                    <a href="courses.php<?= $search_query ? '?search=' . urlencode($search_query) : '' ?><?= $filter_department ? ($search_query ? '&' : '?') . 'department=' . $filter_department : '' ?>"
                       class="semester-badge <?= !$current_semester ? 'active' : '' ?> bg-light text-dark">
                        All Semesters
                    </a>
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <a href="courses.php?semester=<?= $i ?><?= $search_query ? '&search=' . urlencode($search_query) : '' ?><?= $filter_department ? '&department=' . $filter_department : '' ?>"
                           class="semester-badge <?= $current_semester == $i ? 'active' : '' ?> bg-light text-dark">
                            Semester <?= $i ?>
                            <?php if (isset($semester_stats[$i])): ?>
                                <span class="badge bg-primary ms-1"><?= $semester_stats[$i]['courses_per_semester'] ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-attendance" onclick="showEnrolledCourses()">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $total_enrolled ?></h2>
                                <p class="mb-0">Enrolled Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            <?= $total_credits ?? 0 ?> total credits
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= isset($semester_stats) ? count($semester_stats) : 0 ?></h2>
                                <p class="mb-0">Active Semesters</p>
                            </div>
                            <i class="fas fa-calendar-alt stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            Currently in <?= max(array_keys($semester_stats ?? [])) ? max(array_keys($semester_stats ?? [])) : 0 ?> semesters
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-present">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $deadlines_result ? $deadlines_result->num_rows : 0 ?></h2>
                                <p class="mb-0">Upcoming Deadlines</p>
                            </div>
                            <i class="fas fa-tasks stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            Assignments & projects
                        </small>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card text-white bg-percentage">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= $resources_result ? $resources_result->num_rows : 0 ?></h2>
                                <p class="mb-0">Course Resources</p>
                            </div>
                            <i class="fas fa-folder stat-icon"></i>
                        </div>
                        <small class="opacity-75">
                            Notes, slides & materials
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Tabs -->
        <ul class="nav nav-tabs mb-4" id="coursesTab" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="enrolled-tab" data-bs-toggle="tab" data-bs-target="#enrolled" type="button" role="tab">
                    <i class="fas fa-bookmark me-2"></i> Enrolled Courses (<?= $total_enrolled ?>)
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="available-tab" data-bs-toggle="tab" data-bs-target="#available" type="button" role="tab">
                    <i class="fas fa-plus-circle me-2"></i> Available Courses
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="grades-tab" data-bs-toggle="tab" data-bs-target="#grades" type="button" role="tab">
                    <i class="fas fa-chart-line me-2"></i> Recent Grades
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="resources-tab" data-bs-toggle="tab" data-bs-target="#resources" type="button" role="tab">
                    <i class="fas fa-folder me-2"></i> Resources
                </button>
            </li>
        </ul>
        
        <div class="tab-content" id="coursesTabContent">
            <!-- Enrolled Courses Tab -->
            <div class="tab-pane fade show active" id="enrolled" role="tabpanel">
                <?php if ($enrolled_result && $total_enrolled > 0): ?>
                    <div class="row g-4">
                        <?php while ($course = $enrolled_result->fetch_assoc()): 
                            $course_color = $course['course_color'] ?? $dept_color;
                        ?>
                            <div class="col-lg-6">
                                <div class="course-card" onclick="viewCourseDetails(<?= $course['course_id'] ?>)">
                                    <div class="course-color-indicator" style="background: <?= $course_color ?>"></div>
                                    
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <span class="course-badge" style="background: <?= $course_color ?>">
                                                <?= htmlspecialchars($course['course_code']) ?>
                                            </span>
                                            <span class="badge bg-light text-dark ms-2">
                                                <?= $course['credits'] ?> Credits
                                            </span>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?= $course['enrollment_status'] == 'active' ? 'success' : 'warning' ?>">
                                                <?= ucfirst($course['enrollment_status']) ?>
                                            </span>
                                            <small class="text-muted d-block">Sem <?= $course['semester'] ?></small>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mb-3"><?= htmlspecialchars($course['course_name']) ?></h5>
                                    
                                    <p class="text-muted mb-3" style="min-height: 60px;">
                                        <?= htmlspecialchars(substr($course['course_description'] ?? 'No description available', 0, 150)) ?>...
                                    </p>
                                    
                                    <div class="course-meta">
                                        <div class="row">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Instructor</small>
                                                <div class="d-flex align-items-center mt-1">
                                                    <?php if (!empty($course['teacher_image'])): ?>
                                                        <img src="../uploads/profiles/<?= htmlspecialchars($course['teacher_image']) ?>" 
                                                             class="teacher-avatar me-2">
                                                    <?php endif; ?>
                                                    <span class="fw-medium"><?= htmlspecialchars($course['teacher_name'] ?? 'TBA') ?></span>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Department</small>
                                                <span class="fw-medium d-block mt-1"><?= htmlspecialchars($course['department_name']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <div>
                                            <small class="text-muted">
                                                <i class="fas fa-users me-1"></i>
                                                <?= $course['total_students'] ?> students
                                            </small>
                                            <small class="text-muted ms-3">
                                                <i class="fas fa-tasks me-1"></i>
                                                <?= $course['total_assignments'] ?? 0 ?> assignments
                                            </small>
                                        </div>
                                        <div>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="event.stopPropagation(); viewCourseSyllabus(<?= $course['course_id'] ?>)">
                                                <i class="fas fa-file-alt me-1"></i> Syllabus
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book-open fa-3x mb-3"></i>
                        <h4>No Enrolled Courses</h4>
                        <p class="text-muted mb-4">You haven't enrolled in any courses yet.</p>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollModal">
                            <i class="fas fa-plus me-1"></i> Enroll in Your First Course
                        </button>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Available Courses Tab -->
            <div class="tab-pane fade" id="available" role="tabpanel">
                <?php if ($available_result && $available_result->num_rows > 0): ?>
                    <div class="row g-4">
                        <?php while ($course = $available_result->fetch_assoc()): 
                            $course_color = $course['course_color'] ?? $dept_color;
                        ?>
                            <div class="col-lg-6">
                                <div class="course-card">
                                    <div class="course-color-indicator" style="background: <?= $course_color ?>"></div>
                                    
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <span class="course-badge" style="background: <?= $course_color ?>">
                                                <?= htmlspecialchars($course['course_code']) ?>
                                            </span>
                                            <span class="badge bg-light text-dark ms-2">
                                                <?= $course['credits'] ?> Credits
                                            </span>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-info">Available</span>
                                            <small class="text-muted d-block">Sem <?= $course['semester'] ?></small>
                                        </div>
                                    </div>
                                    
                                    <h5 class="mb-3"><?= htmlspecialchars($course['course_name']) ?></h5>
                                    
                                    <p class="text-muted mb-3" style="min-height: 60px;">
                                        <?= htmlspecialchars(substr($course['course_description'] ?? 'No description available', 0, 150)) ?>...
                                    </p>
                                    
                                    <div class="course-meta">
                                        <div class="row">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Instructor</small>
                                                <span class="fw-medium d-block mt-1"><?= htmlspecialchars($course['teacher_name'] ?? 'TBA') ?></span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Department</small>
                                                <span class="fw-medium d-block mt-1"><?= htmlspecialchars($course['department_name']) ?></span>
                                            </div>
                                        </div>
                                        <div class="row mt-2">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Enrolled Students</small>
                                                <span class="fw-medium d-block mt-1"><?= $course['enrolled_students'] ?></span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Type</small>
                                                <span class="badge bg-light text-dark"><?= ucfirst($course['course_type']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <div>
                                            <?php if ($course['prerequisites']): ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-exclamation-circle me-1"></i>
                                                    Prerequisites required
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <button class="btn btn-sm btn-success" 
                                                    onclick="enrollInCourse(<?= $course['course_id'] ?>)">
                                                <i class="fas fa-user-plus me-1"></i> Enroll Now
                                            </button>
                                            <button class="btn btn-sm btn-outline-secondary ms-2"
                                                    onclick="viewCourseDetails(<?= $course['course_id'] ?>)">
                                                <i class="fas fa-info-circle me-1"></i> Details
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-search fa-3x mb-3"></i>
                        <h4>No Available Courses</h4>
                        <p class="text-muted mb-4">
                            <?php if ($current_semester || $filter_department || $search_query): ?>
                                No courses found matching your criteria. Try changing your filters.
                            <?php else: ?>
                                All courses are currently enrolled or not available for enrollment.
                            <?php endif; ?>
                        </p>
                        <?php if ($current_semester || $filter_department || $search_query): ?>
                            <a href="courses.php" class="btn btn-primary">
                                <i class="fas fa-times me-1"></i> Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Recent Grades Tab -->
            <div class="tab-pane fade" id="grades" role="tabpanel">
                <?php if ($grades_result && $grades_result->num_rows > 0): ?>
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Assignment</th>
                                            <th>Course</th>
                                            <th>Marks</th>
                                            <th>Grade</th>
                                            <th>Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($grade = $grades_result->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($grade['title']) ?></strong>
                                                    <br>
                                                    <small class="text-muted">Max: <?= $grade['max_marks'] ?> marks</small>
                                                </td>
                                                <td>
                                                    <span class="badge" style="background: <?= $grade['course_color'] ?>">
                                                        <?= htmlspecialchars($grade['course_code']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold"><?= $grade['marks'] ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $grade['grade_color'] ?>">
                                                        <?= $grade['grade_letter'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= $grade['submission_date_formatted'] ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary"
                                                            onclick="viewAssignmentDetails(<?= $grade['assignment_id'] ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Grade Distribution</h6>
                                    <div id="gradeChartContainer" style="height: 250px;"></div>
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Based on recent 5 assignments
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chart-line fa-3x mb-3"></i>
                        <h4>No Grade Records</h4>
                        <p class="text-muted mb-4">Your assignment grades will appear here once evaluated.</p>
                        <a href="assignments.php" class="btn btn-primary">
                            <i class="fas fa-tasks me-1"></i> View Assignments
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Resources Tab -->
            <div class="tab-pane fade" id="resources" role="tabpanel">
                <?php if ($resources_result && $resources_result->num_rows > 0): ?>
                    <div class="row">
                        <div class="col-lg-8">
                            <?php while ($resource = $resources_result->fetch_assoc()): ?>
                                <div class="resource-card">
                                    <div class="d-flex align-items-center">
                                        <div class="file-icon <?= $resource['file_category'] ?> me-3">
                                            <i class="fas fa-file-<?= $resource['file_category'] == 'word' ? 'word' : 
                                                                   ($resource['file_category'] == 'pdf' ? 'pdf' : 
                                                                   ($resource['file_category'] == 'powerpoint' ? 'presentation' : 
                                                                   ($resource['file_category'] == 'image' ? 'image' : 
                                                                   ($resource['file_category'] == 'video' ? 'video' : 'file')))) ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1"><?= htmlspecialchars($resource['title']) ?></h6>
                                            <div class="d-flex align-items-center">
                                                <small class="text-muted me-3">
                                                    <i class="fas fa-book me-1"></i>
                                                    <?= htmlspecialchars($resource['course_name']) ?> (<?= htmlspecialchars($resource['course_code']) ?>)
                                                </small>
                                                <small class="text-muted me-3">
                                                    <i class="fas fa-user me-1"></i>
                                                    <?= htmlspecialchars($resource['uploaded_by'] ?? 'System') ?>
                                                </small>
                                                <small class="text-muted">
                                                    <i class="far fa-calendar me-1"></i>
                                                    <?= $resource['upload_date_formatted'] ?>
                                                </small>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <a href="../uploads/resources/<?= htmlspecialchars($resource['file_path']) ?>" 
                                               class="btn btn-sm btn-primary" download>
                                                <i class="fas fa-download me-1"></i> Download
                                            </a>
                                            <button class="btn btn-sm btn-outline-secondary ms-2"
                                                    onclick="viewResource(<?= $resource['resource_id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                            
                            <div class="text-center mt-4">
                                <a href="resources.php" class="btn btn-primary">
                                    <i class="fas fa-folder-open me-1"></i> View All Resources
                                </a>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Resource Types</h6>
                                    <div id="resourceChartContainer" style="height: 200px;"></div>
                                    <div class="mt-3">
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Latest course materials
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Upcoming Deadlines -->
                            <?php if ($deadlines_result && $deadlines_result->num_rows > 0): ?>
                                <div class="card mt-4">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="fas fa-clock me-2"></i> Upcoming Deadlines
                                        </h6>
                                        <?php while ($deadline = $deadlines_result->fetch_assoc()): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                                                <div>
                                                    <small class="d-block"><?= htmlspecialchars($deadline['course_code']) ?></small>
                                                    <span class="fw-medium"><?= htmlspecialchars(substr($deadline['title'], 0, 30)) ?>...</span>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-<?= $deadline['days_left'] <= 3 ? 'danger' : 'warning' ?>">
                                                        <?= $deadline['due_date_formatted'] ?>
                                                    </span>
                                                    <small class="d-block text-muted"><?= $deadline['days_left'] ?> days left</small>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                        <a href="assignments.php" class="btn btn-sm btn-outline-primary w-100 mt-2">
                                            View All Assignments
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-folder fa-3x mb-3"></i>
                        <h4>No Course Resources</h4>
                        <p class="text-muted mb-4">No resources have been uploaded for your courses yet.</p>
                        <a href="courses.php" class="btn btn-primary">
                            <i class="fas fa-sync me-1"></i> Check Back Later
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Enroll Modal -->
    <div class="modal fade" id="enrollModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i> Enroll in New Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="enrollForm" onsubmit="submitEnrollment(event)">
                        <div class="mb-4">
                            <label class="form-label">Select Course</label>
                            <select class="form-select" id="courseSelect" required>
                                <option value="">Choose a course...</option>
                                <?php 
                                if ($available_result) {
                                    $available_result->data_seek(0);
                                    while ($course = $available_result->fetch_assoc()): 
                                ?>
                                    <option value="<?= $course['course_id'] ?>">
                                        [<?= htmlspecialchars($course['course_code']) ?>] <?= htmlspecialchars($course['course_name']) ?>
                                        (Sem <?= $course['semester'] ?> • <?= $course['credits'] ?> Credits)
                                    </option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            You can enroll in up to 6 courses per semester. Please ensure you meet all prerequisites.
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="prerequisiteCheck" required>
                            <label class="form-check-label" for="prerequisiteCheck">
                                I confirm that I meet all course prerequisites and requirements
                            </label>
                        </div>
                        
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="scheduleCheck" required>
                            <label class="form-check-label" for="scheduleCheck">
                                I have checked that this course doesn't conflict with my current schedule
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="enrollForm" class="btn btn-primary">
                        <i class="fas fa-user-plus me-1"></i> Enroll Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Course Details Modal -->
    <div class="modal fade course-details-modal" id="courseDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-course">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Course Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="courseDetailsContent">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Grade Chart
            const gradeCtx = document.getElementById('gradeChartContainer');
            if (gradeCtx) {
                // This would be populated with actual data
                new Chart(gradeCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['A+', 'A', 'B+', 'B', 'C', 'D', 'F'],
                        datasets: [{
                            data: [1, 2, 1, 1, 0, 0, 0],
                            backgroundColor: [
                                '#28a745',
                                '#20c997',
                                '#17a2b8',
                                '#ffc107',
                                '#fd7e14',
                                '#dc3545',
                                '#6c757d'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
            
            // Resource Chart
            const resourceCtx = document.getElementById('resourceChartContainer');
            if (resourceCtx) {
                new Chart(resourceCtx, {
                    type: 'pie',
                    data: {
                        labels: ['PDF', 'Word', 'PowerPoint', 'Images', 'Videos'],
                        datasets: [{
                            data: [3, 2, 1, 1, 1],
                            backgroundColor: [
                                '#FF6B6B',
                                '#2B579A',
                                '#D24726',
                                '#4CAF50',
                                '#9C27B0'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false
                    }
                });
            }
        });
        
        // Course Functions
        function viewCourseDetails(courseId) {
            // In a real application, this would fetch course details via AJAX
            const modal = new bootstrap.Modal(document.getElementById('courseDetailsModal'));
            const content = document.getElementById('courseDetailsContent');
            
            // Show loading
            content.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-primary mb-3"></div>
                    <p>Loading course details...</p>
                </div>
            `;
            
            modal.show();
            
            // Simulate AJAX call
            setTimeout(() => {
                content.innerHTML = `
                    <div class="row">
                        <div class="col-md-8">
                            <h4>Advanced Programming</h4>
                            <p class="text-muted">CS401 • 4 Credits • Semester 4</p>
                            <p>This course covers advanced programming concepts including data structures, algorithms, and software design patterns.</p>
                            
                            <h6 class="mt-4">Learning Outcomes</h6>
                            <ul>
                                <li>Understand advanced data structures</li>
                                <li>Implement complex algorithms</li>
                                <li>Apply design patterns</li>
                                <li>Develop efficient software solutions</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-body">
                                    <h6>Course Information</h6>
                                    <p><strong>Instructor:</strong> Dr. John Smith</p>
                                    <p><strong>Schedule:</strong> Mon/Wed 10:00-11:30</p>
                                    <p><strong>Room:</strong> CS-201</p>
                                    <p><strong>Students:</strong> 45 enrolled</p>
                                    <p><strong>Status:</strong> <span class="badge bg-success">Active</span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }, 500);
        }
        
        function enrollInCourse(courseId) {
            if (confirm('Are you sure you want to enroll in this course?')) {
                // In a real application, this would be an AJAX call
                fetch('enroll_course.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        course_id: courseId,
                        student_id: <?= $student_id ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Successfully enrolled in the course!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error enrolling in course. Please try again.');
                });
            }
        }
        
        function submitEnrollment(event) {
            event.preventDefault();
            const courseSelect = document.getElementById('courseSelect');
            const courseId = courseSelect.value;
            
            if (!courseId) {
                alert('Please select a course.');
                return;
            }
            
            // Submit enrollment
            enrollInCourse(courseId);
            
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('enrollModal'));
            modal.hide();
        }
        
        function viewCourseSyllabus(courseId) {
            alert('Opening syllabus for course ID: ' + courseId);
            // window.open('syllabus.php?course_id=' + courseId, '_blank');
        }
        
        function viewAssignmentDetails(assignmentId) {
            alert('Viewing assignment details for ID: ' + assignmentId);
            // window.location.href = 'assignment.php?id=' + assignmentId;
        }
        
        function viewResource(resourceId) {
            alert('Viewing resource ID: ' + resourceId);
            // window.location.href = 'resource.php?id=' + resourceId;
        }
        
        function showEnrolledCourses() {
            document.getElementById('enrolled-tab').click();
        }
        
        // Search functionality
        document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.form.submit();
            }
        });
        
        // Filter functionality
        function applyFilter(filterType, value) {
            const url = new URL(window.location.href);
            url.searchParams.set(filterType, value);
            window.location.href = url.toString();
        }
        
        // Clear all filters
        function clearFilters() {
            window.location.href = 'courses.php';
        }
        
        // Auto-refresh for new courses
        setInterval(() => {
            // Check for new available courses every 5 minutes
            fetch('check_new_courses.php?student_id=<?= $student_id ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.new_courses > 0) {
                        // Show notification
                        if (Notification.permission === "granted") {
                            new Notification("New Courses Available", {
                                body: data.new_courses + " new courses are available for enrollment",
                                icon: "/favicon.ico"
                            });
                        }
                    }
                });
        }, 300000); // 5 minutes
    </script>
</body>
</html>