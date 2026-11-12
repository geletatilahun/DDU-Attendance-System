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
   HANDLE CLASS REPRESENTATIVE ACTIONS
====================================================== */
if (isset($_POST['action']) && $_POST['action'] === 'set_cr') {
    $student_id = (int) $_POST['student_id'];
    $course_id = (int) $_POST['course_id'];
    
    // Verify the teacher teaches this course
    $verify_sql = "SELECT 1 FROM course_teachers WHERE teacher_id = ? AND course_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $teacher_id, $course_id);
    $verify_stmt->execute();
    $verify_stmt->store_result();
    
    if ($verify_stmt->num_rows > 0) {
        // Verify student is enrolled in the course
        $check_sql = "SELECT 1 FROM course_students WHERE student_id = ? AND course_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $student_id, $course_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            // First, remove any existing CR for this course
            $remove_sql = "UPDATE course_students SET is_class_rep = 0 WHERE course_id = ?";
            $remove_stmt = $conn->prepare($remove_sql);
            $remove_stmt->bind_param("i", $course_id);
            $remove_stmt->execute();
            
            // Set new CR
            $set_sql = "UPDATE course_students SET is_class_rep = 1 WHERE course_id = ? AND student_id = ?";
            $set_stmt = $conn->prepare($set_sql);
            $set_stmt->bind_param("ii", $course_id, $student_id);
            
            if ($set_stmt->execute()) {
                $_SESSION['success_message'] = "Class Representative assigned successfully!";
            } else {
                $_SESSION['error_message'] = "Failed to assign Class Representative.";
            }
        } else {
            $_SESSION['error_message'] = "Student is not enrolled in the selected course.";
        }
    } else {
        $_SESSION['error_message'] = "You are not authorized to manage this course.";
    }
    
    header("Location: students.php");
    exit;
}

/* ======================================================
   GET FILTER PARAMETERS
====================================================== */
$course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

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
   SIMPLE STUDENTS QUERY - FIXED VERSION
====================================================== */
// First, let's get student IDs from teacher's courses
$student_ids_sql = "
    SELECT DISTINCT cs.student_id
    FROM course_students cs
    JOIN course_teachers ct ON cs.course_id = ct.course_id
    WHERE ct.teacher_id = ?
";

$student_ids_stmt = $conn->prepare($student_ids_sql);
if ($student_ids_stmt) {
    $student_ids_stmt->bind_param("i", $teacher_id);
    $student_ids_stmt->execute();
    $student_ids_result = $student_ids_stmt->get_result();
    
    $student_ids = [];
    while ($row = $student_ids_result->fetch_assoc()) {
        $student_ids[] = $row['student_id'];
    }
} else {
    $student_ids = [];
    // Log error for debugging
    error_log("Failed to prepare student_ids_sql: " . $conn->error);
}

// If no students found in courses, show empty
if (empty($student_ids)) {
    $students_result = new class {
        public $num_rows = 0;
        public function fetch_assoc() { return false; }
        public function data_seek($offset) { return false; }
    };
} else {
    // Now fetch student details
    $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
    $student_sql = "
        SELECT 
            s.student_id,
            s.roll_number,
            s.full_name,
            s.email,
            s.phone,
            d.department_name,
            s.created_at,
            GROUP_CONCAT(DISTINCT c.course_code) as course_codes,
            GROUP_CONCAT(DISTINCT CASE WHEN cs.is_class_rep = 1 THEN c.course_code END) as cr_courses
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN course_students cs ON s.student_id = cs.student_id
        LEFT JOIN courses c ON cs.course_id = c.course_id
        WHERE s.student_id IN ($placeholders)
    ";
    
    // Add filters
    $where_conditions = [];
    $filter_params = $student_ids; // Start with student_ids as parameters
    
    if ($course_id > 0) {
        $student_sql .= " AND cs.course_id = ?";
        $filter_params[] = $course_id;
    }
    
    if ($search) {
        $student_sql .= " AND (s.full_name LIKE ? OR s.roll_number LIKE ? OR s.email LIKE ?)";
        $search_term = "%$search%";
        $filter_params[] = $search_term;
        $filter_params[] = $search_term;
        $filter_params[] = $search_term;
    }
    
    $student_sql .= " GROUP BY s.student_id ORDER BY s.roll_number";
    
    $students_stmt = $conn->prepare($student_sql);
    if ($students_stmt) {
        // Build types string - all student_ids are integers, then add filter types
        $types = str_repeat('i', count($student_ids)); // All student IDs are integers
        
        if ($course_id > 0) {
            $types .= 'i'; // course_id is integer
        }
        
        if ($search) {
            $types .= 'sss'; // Three search terms are strings
        }
        
        $students_stmt->bind_param($types, ...$filter_params);
        if ($students_stmt->execute()) {
            $students_result = $students_stmt->get_result();
        } else {
            // If complex query fails, use simple query
            error_log("Complex query failed: " . $students_stmt->error);
            $simple_sql = "
                SELECT s.student_id, s.roll_number, s.full_name, s.email
                FROM students s
                WHERE s.student_id IN ($placeholders)
                ORDER BY s.roll_number
            ";
            $simple_stmt = $conn->prepare($simple_sql);
            if ($simple_stmt) {
                $simple_stmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
                if ($simple_stmt->execute()) {
                    $students_result = $simple_stmt->get_result();
                } else {
                    // Create empty result set
                    $students_result = new class {
                        public $num_rows = 0;
                        public function fetch_assoc() { return false; }
                        public function data_seek($offset) { return false; }
                    };
                }
            } else {
                // Create empty result set
                $students_result = new class {
                    public $num_rows = 0;
                    public function fetch_assoc() { return false; }
                    public function data_seek($offset) { return false; }
                };
            }
        }
    } else {
        // If query preparation fails, use simple query
        error_log("Failed to prepare student_sql: " . $conn->error);
        $simple_sql = "
            SELECT s.student_id, s.roll_number, s.full_name, s.email
            FROM students s
            WHERE s.student_id IN ($placeholders)
            ORDER BY s.roll_number
        ";
        $simple_stmt = $conn->prepare($simple_sql);
        if ($simple_stmt) {
            $simple_stmt->bind_param(str_repeat('i', count($student_ids)), ...$student_ids);
            if ($simple_stmt->execute()) {
                $students_result = $simple_stmt->get_result();
            } else {
                // Create empty result set
                $students_result = new class {
                    public $num_rows = 0;
                    public function fetch_assoc() { return false; }
                    public function data_seek($offset) { return false; }
                };
            }
        } else {
            // Create empty result set
            $students_result = new class {
                public $num_rows = 0;
                public function fetch_assoc() { return false; }
                public function data_seek($offset) { return false; }
            };
        }
    }
}
/* ======================================================
   CALCULATE STATISTICS
====================================================== */
$stats = [
    'total_students' => 0,
    'total_courses' => 0,
    'total_departments' => 0,
    'total_cr_count' => 0
];

// Get total students in teacher's courses
$total_students_sql = "
    SELECT COUNT(DISTINCT cs.student_id) as total_students
    FROM course_students cs
    JOIN course_teachers ct ON cs.course_id = ct.course_id
    WHERE ct.teacher_id = ?
";

$total_stmt = $conn->prepare($total_students_sql);
if ($total_stmt) {
    $total_stmt->bind_param("i", $teacher_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $stats['total_students'] = $total_result->fetch_assoc()['total_students'] ?? 0;
}

// Get total courses taught by teacher
$total_courses_sql = "
    SELECT COUNT(DISTINCT course_id) as total_courses
    FROM course_teachers
    WHERE teacher_id = ?
";

$courses_count_stmt = $conn->prepare($total_courses_sql);
if ($courses_count_stmt) {
    $courses_count_stmt->bind_param("i", $teacher_id);
    $courses_count_stmt->execute();
    $courses_count_result = $courses_count_stmt->get_result();
    $stats['total_courses'] = $courses_count_result->fetch_assoc()['total_courses'] ?? 0;
}

// Get total departments from teacher's courses
$dept_sql = "
    SELECT COUNT(DISTINCT s.department_id) as total_departments
    FROM students s
    JOIN course_students cs ON s.student_id = cs.student_id
    JOIN course_teachers ct ON cs.course_id = ct.course_id
    WHERE ct.teacher_id = ?
";

$dept_stmt = $conn->prepare($dept_sql);
if ($dept_stmt) {
    $dept_stmt->bind_param("i", $teacher_id);
    $dept_stmt->execute();
    $dept_result = $dept_stmt->get_result();
    $stats['total_departments'] = $dept_result->fetch_assoc()['total_departments'] ?? 0;
}

// Get total class representatives
$cr_sql = "
    SELECT COUNT(DISTINCT cs.student_id) as total_cr_count
    FROM course_students cs
    JOIN course_teachers ct ON cs.course_id = ct.course_id
    WHERE ct.teacher_id = ? AND cs.is_class_rep = 1
";

$cr_stmt = $conn->prepare($cr_sql);
if ($cr_stmt) {
    $cr_stmt->bind_param("i", $teacher_id);
    $cr_stmt->execute();
    $cr_result = $cr_stmt->get_result();
    $stats['total_cr_count'] = $cr_result->fetch_assoc()['total_cr_count'] ?? 0;
}

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "Students";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - DDU Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-green: #28a745;
            --ddu-red: #dc3545;
            --ddu-orange: #fd7e14;
        }
        
        .sidebar {
            background: var(--ddu-blue);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
            z-index: 1000;
        }
        
        .sidebar .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 12px 20px;
            border-left: 3px solid transparent;
            text-decoration: none;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--ddu-gold);
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .navbar-top {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 20px;
            margin: -20px -20px 20px -20px;
            border-radius: 0 0 10px 10px;
        }
        
        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .bg-students { 
            background: linear-gradient(135deg, #4b6cb7 0%, #182848 100%);
            color: white;
        }
        
        .bg-courses { 
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .bg-departments { 
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
            color: white;
        }
        
        .bg-cr { 
            background: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            color: #003366;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .student-avatar {
            width: 45px;
            height: 45px;
            background: var(--ddu-blue);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .cr-badge {
            background: var(--ddu-gold);
            color: var(--ddu-blue);
            padding: 4px 12px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .set-cr-btn {
            background: var(--ddu-gold);
            border: none;
            color: var(--ddu-blue);
            font-weight: 600;
        }
        
        .set-cr-btn:hover {
            background: #e6c200;
            color: var(--ddu-blue);
        }
        
        .course-badge {
            background: #e9ecef;
            color: #495057;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin: 2px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h5><i class="fas fa-university"></i> DDU Teacher</h5>
            <small>Attendance System</small>
        </div>
        
        <nav class="nav flex-column mt-3">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
            </a>
            <a href="courses.php" class="nav-link">
                <i class="fas fa-book me-2"></i> My Courses
            </a>
            <a href="mark_attendance.php" class="nav-link">
                <i class="fas fa-clipboard-check me-2"></i> Mark Attendance
            </a>
            <a href="attendance_reports.php" class="nav-link">
                <i class="fas fa-chart-bar me-2"></i> Reports
            </a>
            <a href="students.php" class="nav-link active">
                <i class="fas fa-user-graduate me-2"></i> Students
            </a>
            <a href="schedule.php" class="nav-link">
                <i class="fas fa-calendar-alt me-2"></i> Schedule
            </a>
            <a href="profile.php" class="nav-link">
                <i class="fas fa-user me-2"></i> Profile
            </a>
            
            <div class="mt-4 px-3">
                <div class="user-info">
                    <small>Logged in as:</small>
                    <h6><?= htmlspecialchars($teacher['full_name']) ?></h6>
                    <small class="text-muted"><?= htmlspecialchars($teacher['department_name'] ?? 'Department') ?></small>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-sm mt-3 w-100">
                    <i class="fas fa-sign-out-alt me-1"></i> Logout
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar-top">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="fas fa-user-graduate text-primary me-2"></i>
                        Students Management
                    </h4>
                    <p class="text-muted mb-0">
                        Welcome back, <?= htmlspecialchars($teacher['full_name']) ?>
                    </p>
                </div>
                <div class="d-flex align-items-center">
                    <div class="dropdown">
                        <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                            <?php if (!empty($teacher['profile_image'])): ?>
                                <img src="../uploads/profiles/<?= htmlspecialchars($teacher['profile_image']) ?>" 
                                     class="rounded-circle" width="40" height="40">
                            <?php else: ?>
                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($teacher['full_name']) ?>&background=003366&color=fff" 
                                     class="rounded-circle" width="40" height="40">
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Display Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?= $_SESSION['success_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= $_SESSION['error_message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <!-- Filter Section -->
        <div class="chart-container">
            <h5 class="mb-4">
                <i class="fas fa-filter text-primary me-2"></i>
                Filter Students
            </h5>
            
            <form method="get" action="students.php" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Course</label>
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
                
                <div class="col-md-5">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search by name, roll number, or email" 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2">
                        <i class="fas fa-search me-2"></i> Search
                    </button>
                    <a href="students.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i>
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mt-4">
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-students">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_students']) ?></h2>
                                <p class="mb-0">Total Students</p>
                            </div>
                            <i class="fas fa-user-graduate fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_courses']) ?></h2>
                                <p class="mb-0">Courses</p>
                            </div>
                            <i class="fas fa-book fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card text-white bg-departments">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_departments']) ?></h2>
                                <p class="mb-0">Departments</p>
                            </div>
                            <i class="fas fa-building fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="card stat-card bg-cr">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_cr_count']) ?></h2>
                                <p class="mb-0">Class Reps</p>
                            </div>
                            <i class="fas fa-crown fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Students Table -->
        <div class="chart-container mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h5 class="mb-0">
                    <i class="fas fa-list text-primary me-2"></i>
                    Student List
                    <span class="badge bg-primary ms-2"><?= $students_result->num_rows ?></span>
                </h5>
                
                <?php if ($students_result->num_rows > 0): ?>
                <div class="btn-group">
                    <button class="btn btn-outline-primary btn-sm" onclick="exportStudents()">
                        <i class="fas fa-download me-1"></i> Export
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($students_result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Roll No.</th>
                                <th>Student Name</th>
                                <th>Email</th>
                                <th>Department</th>
                                <th>Courses</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($student = $students_result->fetch_assoc()): 
                                $initials = strtoupper(substr($student['full_name'], 0, 1));
                                if (strlen($student['full_name']) > 1) {
                                    $name_parts = explode(' ', $student['full_name']);
                                    if (count($name_parts) > 1) {
                                        $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
                                    }
                                }
                                
                                $cr_courses = !empty($student['cr_courses']) ? array_filter(explode(',', $student['cr_courses'])) : [];
                                $course_codes = !empty($student['course_codes']) ? explode(',', $student['course_codes']) : [];
                            ?>
                                <tr>
                                    <td>
                                        <strong class="text-primary"><?= htmlspecialchars($student['roll_number'] ?? 'N/A') ?></strong>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="student-avatar me-3">
                                                <?= $initials ?>
                                            </div>
                                            <div>
                                                <strong><?= htmlspecialchars($student['full_name']) ?></strong><br>
                                                <small class="text-muted">ID: <?= $student['student_id'] ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($student['email'] ?? 'N/A') ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($student['department_name'] ?? 'Not Assigned') ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($course_codes) && $course_codes[0] !== ''): ?>
                                            <div class="d-flex flex-wrap">
                                                <?php foreach ($course_codes as $code): 
                                                    $is_cr = in_array($code, $cr_courses);
                                                ?>
                                                    <span class="course-badge <?= $is_cr ? 'bg-warning text-dark' : '' ?>">
                                                        <?= htmlspecialchars($code) ?>
                                                        <?php if ($is_cr): ?>
                                                            <i class="fas fa-crown ms-1"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No courses</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($cr_courses)): ?>
                                            <span class="cr-badge">
                                                <i class="fas fa-crown me-1"></i>
                                                Class Rep
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Student</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-outline-primary" onclick="viewStudent(<?= $student['student_id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn set-cr-btn" 
                                                    onclick="setClassRep(<?= $student['student_id'] ?>, '<?= htmlspecialchars(addslashes($student['full_name'])) ?>')"
                                                    title="Set as Class Representative">
                                                <i class="fas fa-user-crown"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- Debug Information (remove in production) -->
                <div class="alert alert-info mb-3">
                    <h6>Debug Information:</h6>
                    <p>Teacher ID: <?= $teacher_id ?></p>
                    <p>Courses Found: <?= $courses_result ? $courses_result->num_rows : 0 ?></p>
                    <p>Student IDs in Courses: <?= count($student_ids) ?></p>
                </div>
                
                <!-- Empty State -->
                <div class="text-center py-5">
                    <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No Students Found</h4>
                    <p class="text-muted mb-4">
                        No students are enrolled in your courses yet.
                    </p>
                    <a href="courses.php" class="btn btn-primary">
                        <i class="fas fa-book me-2"></i> View My Courses
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Set Class Representative Modal -->
    <div class="modal fade" id="setCRModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-user-crown me-2"></i>
                        Set Class Representative
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="crForm" method="POST">
                    <input type="hidden" name="action" value="set_cr">
                    <input type="hidden" id="cr_student_id" name="student_id">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Student:</label>
                            <h6 id="cr_student_name" class="text-primary"></h6>
                        </div>
                        
                        <div class="mb-3">
                            <label for="cr_course_id" class="form-label fw-bold">Select Course:</label>
                            <select class="form-control" id="cr_course_id" name="course_id" required>
                                <option value="">-- Select a Course --</option>
                                <?php 
                                if ($courses_result && $courses_result->num_rows > 0):
                                    $courses_result->data_seek(0);
                                    while ($course = $courses_result->fetch_assoc()): ?>
                                        <option value="<?= $course['course_id'] ?>">
                                            <?= htmlspecialchars($course['course_code']) ?> - <?= htmlspecialchars($course['course_name']) ?>
                                        </option>
                                <?php endwhile; endif; ?>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Any existing Class Representative for this course will be replaced.
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check me-1"></i> Set as Class Rep
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function setClassRep(studentId, studentName) {
            document.getElementById('cr_student_id').value = studentId;
            document.getElementById('cr_student_name').textContent = studentName;
            document.getElementById('cr_course_id').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('setCRModal'));
            modal.show();
        }
        
        function viewStudent(studentId) {
            alert('Viewing student ID: ' + studentId);
            // window.open('student_details.php?id=' + studentId, '_blank');
        }
        
        function exportStudents() {
            alert('Export feature would be implemented here');
        }
    </script>
</body>
</html>