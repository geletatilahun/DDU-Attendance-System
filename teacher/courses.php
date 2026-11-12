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
   FETCH ASSIGNED COURSES - WITH ERROR HANDLING
====================================================== */
// Try multiple queries until one works
$queries = [
    // Full query
    "SELECT c.course_id, c.course_code, c.course_name, c.credits, c.semester, 
            d.department_name, COUNT(DISTINCT cs.student_id) as student_count
     FROM course_teachers ct
     JOIN courses c ON ct.course_id = c.course_id
     LEFT JOIN departments d ON c.department_id = d.department_id
     LEFT JOIN course_students cs ON c.course_id = cs.course_id
     WHERE ct.teacher_id = ?
     GROUP BY c.course_id
     ORDER BY c.semester, c.course_name",
    
    // Without departments
    "SELECT c.course_id, c.course_code, c.course_name, c.credits, c.semester,
            COUNT(DISTINCT cs.student_id) as student_count
     FROM course_teachers ct
     JOIN courses c ON ct.course_id = c.course_id
     LEFT JOIN course_students cs ON c.course_id = cs.course_id
     WHERE ct.teacher_id = ?
     GROUP BY c.course_id
     ORDER BY c.semester, c.course_name",
    
    // Without student count
    "SELECT c.course_id, c.course_code, c.course_name, c.credits, c.semester
     FROM course_teachers ct
     JOIN courses c ON ct.course_id = c.course_id
     WHERE ct.teacher_id = ?
     ORDER BY c.semester, c.course_name",
    
    // Simplest query
    "SELECT c.course_id, c.course_code, c.course_name
     FROM course_teachers ct
     JOIN courses c ON ct.course_id = c.course_id
     WHERE ct.teacher_id = ?"
];

$courses_result = null;
$courses_stmt = null;

foreach ($queries as $sql) {
    $courses_stmt = $conn->prepare($sql);
    if ($courses_stmt) {
        $courses_stmt->bind_param("i", $teacher_id);
        if ($courses_stmt->execute()) {
            $courses_result = $courses_stmt->get_result();
            break;
        }
    }
}

if (!$courses_result) {
    // Create empty result set
    $courses_result = new class {
        public $num_rows = 0;
        public function fetch_assoc() { return false; }
        public function data_seek($offset) { return false; }
    };
}

/* ======================================================
   CALCULATE STATISTICS
====================================================== */
$course_count = $courses_result->num_rows;

// Get total students
$total_students_sql = "
    SELECT COUNT(DISTINCT cs.student_id) as total
    FROM course_teachers ct
    JOIN course_students cs ON ct.course_id = cs.course_id
    WHERE ct.teacher_id = ?
";

$total_stmt = $conn->prepare($total_students_sql);
if ($total_stmt) {
    $total_stmt->bind_param("i", $teacher_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_students = $total_result->fetch_assoc()['total'] ?? 0;
} else {
    $total_students = 0;
}

// Count semesters
$semesters = [];
if ($courses_result->num_rows > 0) {
    $courses_result->data_seek(0);
    while ($course = $courses_result->fetch_assoc()) {
        if (isset($course['semester'])) {
            $semesters[$course['semester']] = true;
        }
    }
    $courses_result->data_seek(0); // Reset pointer
}
$semester_count = count($semesters);

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "My Courses";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Courses - DDU Attendance System</title>
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
        
        .bg-courses { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-students { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .bg-semester { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333; }
        
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
        
        .course-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.12);
        }
        
        .course-code-badge {
            background: var(--ddu-blue);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .semester-badge {
            background: var(--ddu-gold);
            color: var(--ddu-blue);
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: 600;
            float: right;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: #e0e0e0;
            margin-bottom: 20px;
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
            <a href="courses.php" class="nav-link active">
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
                    <i class="fas fa-book"></i> My Courses
                </h4>
                <small class="text-muted">Welcome back, <?= htmlspecialchars($teacher['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-4" style="color: var(--ddu-blue);"></i>
                        <?php if ($course_count > 0): ?>
                            <span class="notification-badge"><?= $course_count ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Notifications</h6>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-book text-primary"></i> <?= $course_count ?> courses assigned
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-user-graduate text-success"></i> <?= $total_students ?> students total
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
        
        <!-- Statistics Cards -->
        <div class="row mt-4">
            <div class="col-xl-4 col-md-6">
                <div class="card stat-card text-white bg-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($course_count) ?></h2>
                                <p class="mb-0">Assigned Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-4 col-md-6">
                <div class="card stat-card text-white bg-students">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($total_students) ?></h2>
                                <p class="mb-0">Total Students</p>
                            </div>
                            <i class="fas fa-user-graduate stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-4 col-md-6">
                <div class="card stat-card bg-semester">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($semester_count) ?></h2>
                                <p class="mb-0">Semesters</p>
                            </div>
                            <i class="fas fa-layer-group stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Courses List -->
        <div class="chart-container mt-4">
            <h5 class="mb-4">
                <i class="fas fa-list-check me-2" style="color: var(--ddu-blue);"></i>
                Assigned Courses
                <span class="badge bg-primary ms-2"><?= $course_count ?></span>
            </h5>
            
            <?php if ($course_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <?php 
                                // Check what columns we have
                                $sample = $courses_result->fetch_assoc();
                                $courses_result->data_seek(0);
                                
                                if (isset($sample['credits'])): ?>
                                    <th>Credits</th>
                                <?php endif; ?>
                                <?php if (isset($sample['semester'])): ?>
                                    <th>Semester</th>
                                <?php endif; ?>
                                <?php if (isset($sample['department_name'])): ?>
                                    <th>Department</th>
                                <?php endif; ?>
                                <?php if (isset($sample['student_count'])): ?>
                                    <th>Students</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($course = $courses_result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary"><?= htmlspecialchars($course['course_code']) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($course['course_name']) ?></strong>
                                    </td>
                                    
                                    <?php if (isset($course['credits'])): ?>
                                        <td><?= htmlspecialchars($course['credits']) ?> credits</td>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($course['semester'])): ?>
                                        <td>
                                            <span class="badge bg-warning text-dark">
                                                Semester <?= htmlspecialchars($course['semester']) ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($course['department_name'])): ?>
                                        <td><?= htmlspecialchars($course['department_name']) ?></td>
                                    <?php endif; ?>
                                    
                                    <?php if (isset($course['student_count'])): ?>
                                        <td>
                                            <span class="badge bg-info">
                                                <?= htmlspecialchars($course['student_count']) ?> students
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="mark_attendance.php?course_id=<?= $course['course_id'] ?>" 
                                               class="btn btn-success btn-sm">
                                               <i class="fas fa-clipboard-check"></i>
                                            </a>
                                            <a href="course_details.php?id=<?= $course['course_id'] ?>" 
                                               class="btn btn-primary btn-sm">
                                               <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="attendance_reports.php?course_id=<?= $course['course_id'] ?>" 
                                               class="btn btn-info btn-sm">
                                               <i class="fas fa-chart-bar"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h4>No Courses Assigned</h4>
                    <p class="text-muted mb-4">
                        You haven't been assigned to any courses yet. Courses will appear here once they are assigned to you by the administration.
                    </p>
                    
                    <div class="row mt-4">
                        <div class="col-md-4 mb-3">
                            <div class="card text-center h-100">
                                <div class="card-body">
                                    <i class="fas fa-envelope fa-2x text-primary mb-3"></i>
                                    <h6>Contact Admin</h6>
                                    <p class="small text-muted">Request course assignments</p>
                                    <button class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-paper-plane me-1"></i> Send Request
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="card text-center h-100">
                                <div class="card-body">
                                    <i class="fas fa-sync-alt fa-2x text-success mb-3"></i>
                                    <h6>Refresh Status</h6>
                                    <p class="small text-muted">Check for new assignments</p>
                                    <button class="btn btn-outline-success btn-sm" onclick="location.reload()">
                                        <i class="fas fa-redo me-1"></i> Refresh Now
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="card text-center h-100">
                                <div class="card-body">
                                    <i class="fas fa-question-circle fa-2x text-info mb-3"></i>
                                    <h6>Need Help?</h6>
                                    <p class="small text-muted">Get assistance with courses</p>
                                    <button class="btn btn-outline-info btn-sm">
                                        <i class="fas fa-life-ring me-1"></i> Get Help
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Actions -->
        <?php if ($course_count > 0): ?>
        <div class="chart-container mt-4">
            <h5 class="mb-4">
                <i class="fas fa-bolt me-2" style="color: var(--ddu-gold);"></i>
                Quick Actions
            </h5>
            <div class="row">
                <div class="col-md-2 col-sm-4 mb-3">
                    <a href="mark_attendance.php" class="btn btn-success w-100">
                        <i class="fas fa-clipboard-check"></i><br>Mark Attendance
                    </a>
                </div>
                <div class="col-md-2 col-sm-4 mb-3">
                    <a href="attendance_reports.php" class="btn btn-primary w-100">
                        <i class="fas fa-chart-bar"></i><br>View Reports
                    </a>
                </div>
                <div class="col-md-2 col-sm-4 mb-3">
                    <a href="students.php" class="btn btn-info w-100">
                        <i class="fas fa-user-graduate"></i><br>View Students
                    </a>
                </div>
                <div class="col-md-2 col-sm-4 mb-3">
                    <a href="schedule.php" class="btn btn-warning w-100">
                        <i class="fas fa-calendar-alt"></i><br>View Schedule
                    </a>
                </div>
                <div class="col-md-2 col-sm-4 mb-3">
                    <button class="btn btn-secondary w-100" onclick="window.print()">
                        <i class="fas fa-print"></i><br>Print List
                    </button>
                </div>
                <div class="col-md-2 col-sm-4 mb-3">
                    <a href="profile.php" class="btn btn-dark w-100">
                        <i class="fas fa-cog"></i><br>Settings
                    </a>
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
        
        // Add course search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'form-control mb-3';
            searchInput.placeholder = 'Search courses by name or code...';
            searchInput.id = 'courseSearch';
            
            const courseTable = document.querySelector('table');
            if (courseTable) {
                courseTable.parentElement.insertBefore(searchInput, courseTable);
                
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const rows = document.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const courseName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                        const courseCode = row.querySelector('td:nth-child(1)').textContent.toLowerCase();
                        
                        if (courseName.includes(searchTerm) || courseCode.includes(searchTerm)) {
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