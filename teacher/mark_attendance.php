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
   GET COURSE ID FROM URL
====================================================== */
$course_id = isset($_GET['course_id']) ? (int) $_GET['course_id'] : 0;
$selected_course = null;
$students = [];
$today = date('Y-m-d');

/* ======================================================
   FETCH TEACHER'S COURSES FOR DROPDOWN
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
   IF COURSE IS SELECTED, FETCH COURSE AND STUDENT DETAILS
====================================================== */
if ($course_id > 0) {
    // Verify teacher teaches this course
    $verify_sql = "SELECT 1 FROM course_teachers WHERE teacher_id = ? AND course_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    if ($verify_stmt) {
        $verify_stmt->bind_param("ii", $teacher_id, $course_id);
        $verify_stmt->execute();
        $verify_stmt->store_result();
        
        if ($verify_stmt->num_rows === 0) {
            header("Location: mark_attendance.php?error=unauthorized");
            exit;
        }
    }
    
    // Fetch course details
    $course_sql = "SELECT * FROM courses WHERE course_id = ?";
    $course_stmt = $conn->prepare($course_sql);
    if ($course_stmt) {
        $course_stmt->bind_param("i", $course_id);
        $course_stmt->execute();
        $selected_course = $course_stmt->get_result()->fetch_assoc();
    }
    
    // Fetch students enrolled in this course
    $students_sql = "
        SELECT s.student_id, s.roll_number, s.full_name,
               (SELECT attendance_status FROM student_attendance 
                WHERE student_id = s.student_id AND course_id = ? AND attendance_date = ?) as previous_status
        FROM students s
        JOIN course_students cs ON s.student_id = cs.student_id
        WHERE cs.course_id = ?
        ORDER BY s.roll_number
    ";
    
    $students_stmt = $conn->prepare($students_sql);
    if ($students_stmt) {
        $students_stmt->bind_param("isi", $course_id, $today, $course_id);
        $students_stmt->execute();
        $students = $students_stmt->get_result();
    }
    
    // Check if attendance already marked today
    $attendance_check_sql = "
        SELECT * FROM attendance 
        WHERE course_id = ? AND attendance_date = ?
    ";
    $check_stmt = $conn->prepare($attendance_check_sql);
    if ($check_stmt) {
        $check_stmt->bind_param("is", $course_id, $today);
        $check_stmt->execute();
        $existing_attendance = $check_stmt->get_result()->fetch_assoc();
    }
}

/* ======================================================
   HANDLE ATTENDANCE SUBMISSION
====================================================== */
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_attendance'])) {
    $course_id = (int) $_POST['course_id'];
    $attendance_date = $_POST['attendance_date'];
    
    // Verify teacher teaches this course
    $verify_sql = "SELECT 1 FROM course_teachers WHERE teacher_id = ? AND course_id = ?";
    $verify_stmt = $conn->prepare($verify_sql);
    $verify_stmt->bind_param("ii", $teacher_id, $course_id);
    $verify_stmt->execute();
    $verify_stmt->store_result();
    
    if ($verify_stmt->num_rows === 0) {
        $error_message = "You are not authorized to mark attendance for this course.";
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Count present and absent
            $total_present = 0;
            $total_absent = 0;
            $total_late = 0;
            $total_leave = 0;
            
            if (isset($_POST['attendance'])) {
                foreach ($_POST['attendance'] as $student_id => $status) {
                    $student_id = (int) $student_id;
                    $remarks = isset($_POST['remarks'][$student_id]) ? $conn->real_escape_string($_POST['remarks'][$student_id]) : '';
                    
                    switch ($status) {
                        case 'present':
                            $total_present++;
                            break;
                        case 'absent':
                            $total_absent++;
                            break;
                        case 'late':
                            $total_late++;
                            break;
                        case 'leave':
                            $total_leave++;
                            break;
                    }
                    
                    // Insert or update student attendance record
                    $student_att_sql = "
                        INSERT INTO student_attendance 
                        (student_id, course_id, teacher_id, attendance_date, attendance_status, remarks)
                        VALUES (?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        attendance_status = VALUES(attendance_status),
                        remarks = VALUES(remarks),
                        marked_at = CURRENT_TIMESTAMP
                    ";
                    
                    $student_att_stmt = $conn->prepare($student_att_sql);
                    $student_att_stmt->bind_param("iiisss", 
                        $student_id, 
                        $course_id, 
                        $teacher_id, 
                        $attendance_date, 
                        $status, 
                        $remarks
                    );
                    $student_att_stmt->execute();
                }
            }
            
            $total_students = $total_present + $total_absent + $total_late + $total_leave;
            
            // Insert or update attendance summary record
            $insert_sql = "
                INSERT INTO attendance (
                    course_id, 
                    teacher_id, 
                    attendance_date, 
                    total_students,
                    total_present, 
                    total_absent,
                    total_late,
                    total_leave,
                    attendance_percentage,
                    status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'marked')
                ON DUPLICATE KEY UPDATE
                total_students = VALUES(total_students),
                total_present = VALUES(total_present),
                total_absent = VALUES(total_absent),
                total_late = VALUES(total_late),
                total_leave = VALUES(total_leave),
                attendance_percentage = VALUES(attendance_percentage),
                status = VALUES(status),
                updated_at = CURRENT_TIMESTAMP
            ";
            
            $attendance_percentage = $total_students > 0 ? 
                round((($total_present + $total_late) / $total_students) * 100, 2) : 0;
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("issiiiiid", 
                $course_id, 
                $teacher_id, 
                $attendance_date, 
                $total_students,
                $total_present, 
                $total_absent,
                $total_late,
                $total_leave,
                $attendance_percentage
            );
            
            if ($insert_stmt->execute()) {
                $conn->commit();
                $success_message = "Attendance marked successfully for " . date('F j, Y', strtotime($attendance_date));
                
                // Clear POST data to prevent resubmission
                unset($_POST);
                header("Location: mark_attendance.php?course_id=" . $course_id . "&success=1");
                exit;
            } else {
                throw new Exception("Error saving attendance summary: " . $conn->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "Mark Attendance";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance - DDU Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-green: #28a745;
            --ddu-red: #dc3545;
            --ddu-orange: #fd7e14;
            --ddu-purple: #6f42c1;
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
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.15);
        }
        
        .bg-select { 
            background: linear-gradient(135deg, #4b6cb7 0%, #182848 100%);
            color: white;
        }
        
        .bg-date { 
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .bg-teacher { 
            background: linear-gradient(135deg, #fc466b 0%, #3f5efb 100%);
            color: white;
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .attendance-btn {
            padding: 6px 15px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-present {
            background-color: var(--ddu-green);
            color: white;
        }
        
        .btn-absent {
            background-color: var(--ddu-red);
            color: white;
        }
        
        .btn-late {
            background-color: var(--ddu-orange);
            color: white;
        }
        
        .btn-leave {
            background-color: var(--ddu-purple);
            color: white;
        }
        
        .attendance-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        .attendance-btn.active {
            box-shadow: 0 0 0 3px rgba(0,0,0,0.2);
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            background: var(--ddu-blue);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
        }
        
        .attendance-status-badge {
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-present { background: #d4edda; color: #155724; }
        .status-absent { background: #f8d7da; color: #721c24; }
        .status-late { background: #fff3cd; color: #856404; }
        .status-leave { background: #e2e3e5; color: #383d41; }
        
        .attendance-summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
        }
        
        .progress-bar-custom {
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .previous-attendance {
            font-size: 0.8rem;
            padding: 2px 8px;
            border-radius: 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
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
            <a href="mark_attendance.php" class="nav-link active">
                <i class="fas fa-clipboard-check me-2"></i> Mark Attendance
            </a>
            <a href="attendance_reports.php" class="nav-link">
                <i class="fas fa-chart-bar me-2"></i> Reports
            </a>
            <a href="students.php" class="nav-link">
                <i class="fas fa-user-graduate me-2"></i> Students
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
                        <i class="fas fa-clipboard-check text-primary me-2"></i>
                        Mark Attendance
                    </h4>
                    <p class="text-muted mb-0">
                        Welcome back, <?= htmlspecialchars($teacher['full_name']) ?>
                    </p>
                </div>
                <div class="d-flex align-items-center">
                    <?php if (isset($_GET['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show me-3 mb-0" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            Attendance marked successfully!
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
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
        
        <!-- Error Message -->
        <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= $error_message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <!-- Course Selection -->
        <div class="chart-container">
            <h5 class="mb-4">
                <i class="fas fa-book text-primary me-2"></i>
                Select Course
            </h5>
            
            <form method="get" action="mark_attendance.php" class="row g-3">
                <div class="col-md-8">
                    <select name="course_id" class="form-select" required onchange="this.form.submit()">
                        <option value="">-- Select a Course --</option>
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
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i> Load Course
                    </button>
                </div>
            </form>
        </div>
        
        <?php if (isset($existing_attendance) && $existing_attendance): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            Attendance for this course has already been marked today. You can update it below.
        </div>
        <?php endif; ?>
        
        <?php if ($course_id > 0 && $selected_course): ?>
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-xl-4 col-md-6">
                    <div class="card stat-card text-white bg-select">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h2 class="mb-0"><?= $students ? $students->num_rows : 0 ?></h2>
                                    <p class="mb-0">Total Students</p>
                                </div>
                                <i class="fas fa-user-graduate fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-4 col-md-6">
                    <div class="card stat-card text-white bg-date">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h2 class="mb-0"><?= date('d') ?></h2>
                                    <p class="mb-0"><?= date('F Y') ?></p>
                                </div>
                                <i class="fas fa-calendar-day fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-4 col-md-6">
                    <div class="card stat-card text-white bg-teacher">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0"><?= htmlspecialchars($teacher['full_name']) ?></h5>
                                    <p class="mb-0">Teacher</p>
                                </div>
                                <i class="fas fa-chalkboard-teacher fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Attendance Form -->
            <div class="chart-container">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="mb-0">
                            <i class="fas fa-users text-primary me-2"></i>
                            Mark Attendance for 
                            <strong><?= htmlspecialchars($selected_course['course_name']) ?></strong>
                            <small class="text-muted">(<?= htmlspecialchars($selected_course['course_code']) ?>)</small>
                        </h5>
                    </div>
                    <div class="badge bg-primary fs-6">
                        <i class="fas fa-calendar me-1"></i> <?= date('l, F j, Y') ?>
                    </div>
                </div>
                
                <form method="post" action="mark_attendance.php" id="attendanceForm">
                    <input type="hidden" name="course_id" value="<?= $course_id ?>">
                    <input type="hidden" name="attendance_date" value="<?= date('Y-m-d') ?>">
                    
                    <?php if ($students && $students->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th width="5%">#</th>
                                        <th width="15%">Roll No.</th>
                                        <th width="30%">Student Name</th>
                                        <th width="30%">Attendance Status</th>
                                        <th width="20%">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $counter = 1;
                                    $students->data_seek(0);
                                    while ($student = $students->fetch_assoc()): 
                                        $initials = strtoupper(substr($student['full_name'], 0, 1));
                                        if (strlen($student['full_name']) > 1) {
                                            $name_parts = explode(' ', $student['full_name']);
                                            if (count($name_parts) > 1) {
                                                $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[count($name_parts)-1], 0, 1));
                                            }
                                        }
                                        
                                        // Set default status based on previous attendance
                                        $default_status = $student['previous_status'] ?: 'present';
                                    ?>
                                        <tr>
                                            <td><?= $counter++ ?></td>
                                            <td>
                                                <strong class="text-primary"><?= htmlspecialchars($student['roll_number']) ?></strong>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="student-avatar me-3">
                                                        <?= $initials ?>
                                                    </div>
                                                    <div>
                                                        <strong><?= htmlspecialchars($student['full_name']) ?></strong>
                                                        <?php if ($student['previous_status']): ?>
                                                            <br>
                                                            <small class="previous-attendance">
                                                                Previous: 
                                                                <span class="status-<?= $student['previous_status'] ?>">
                                                                    <?= ucfirst($student['previous_status']) ?>
                                                                </span>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <?php 
                                                    $statuses = [
                                                        'present' => ['Present', 'btn-present', 'fas fa-check'],
                                                        'absent' => ['Absent', 'btn-absent', 'fas fa-times'],
                                                        'late' => ['Late', 'btn-late', 'fas fa-clock'],
                                                        'leave' => ['Leave', 'btn-leave', 'fas fa-umbrella-beach']
                                                    ];
                                                    
                                                    foreach ($statuses as $status => $info):
                                                    ?>
                                                        <input type="radio" 
                                                               class="btn-check" 
                                                               name="attendance[<?= $student['student_id'] ?>]" 
                                                               id="<?= $status ?>_<?= $student['student_id'] ?>" 
                                                               value="<?= $status ?>"
                                                               <?= $default_status == $status ? 'checked' : '' ?>>
                                                        <label class="btn btn-sm attendance-btn <?= $info[1] ?>" 
                                                               for="<?= $status ?>_<?= $student['student_id'] ?>">
                                                            <i class="<?= $info[2] ?> me-1"></i> <?= $info[0] ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <input type="text" 
                                                       name="remarks[<?= $student['student_id'] ?>]" 
                                                       class="form-control form-control-sm" 
                                                       placeholder="Optional remarks"
                                                       maxlength="100">
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Attendance Summary -->
                        <div class="attendance-summary-card mt-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-white mb-3">Attendance Summary</h6>
                                    <div class="row text-center">
                                        <div class="col-3">
                                            <div class="display-5 text-success" id="presentCount">0</div>
                                            <small>Present</small>
                                        </div>
                                        <div class="col-3">
                                            <div class="display-5 text-danger" id="absentCount">0</div>
                                            <small>Absent</small>
                                        </div>
                                        <div class="col-3">
                                            <div class="display-5 text-warning" id="lateCount">0</div>
                                            <small>Late</small>
                                        </div>
                                        <div class="col-3">
                                            <div class="display-5 text-info" id="leaveCount">0</div>
                                            <small>Leave</small>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <div class="progress progress-bar-custom">
                                            <div class="progress-bar bg-success" id="presentBar" style="width: 100%"></div>
                                        </div>
                                        <div class="d-flex justify-content-between mt-2">
                                            <small>Attendance Rate: <strong id="attendanceRate">100%</strong></small>
                                            <small>Total: <strong id="totalStudents">0</strong> students</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-center">
                                    <div class="w-100">
                                        <h6 class="text-white mb-3">Quick Actions</h6>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <button type="button" class="btn btn-outline-light w-100" onclick="markAll('present')">
                                                    <i class="fas fa-check-circle me-1"></i> All Present
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="btn btn-outline-light w-100" onclick="markAll('absent')">
                                                    <i class="fas fa-times-circle me-1"></i> All Absent
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="btn btn-outline-light w-100" onclick="markAll('late')">
                                                    <i class="fas fa-clock me-1"></i> All Late
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="button" class="btn btn-outline-light w-100" onclick="markAll('leave')">
                                                    <i class="fas fa-umbrella-beach me-1"></i> All Leave
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Buttons -->
                        <div class="mt-4 text-center">
                            <button type="submit" name="submit_attendance" class="btn btn-success btn-lg px-5">
                                <i class="fas fa-check-circle me-2"></i> Submit Attendance
                            </button>
                            <button type="reset" class="btn btn-secondary btn-lg px-5 ms-2" onclick="resetToDefaults()">
                                <i class="fas fa-redo me-2"></i> Reset
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- No Students -->
                        <div class="text-center py-5">
                            <i class="fas fa-users-slash fa-4x text-muted mb-4"></i>
                            <h4>No Students Enrolled</h4>
                            <p class="text-muted mb-4">
                                There are no students enrolled in this course yet.
                            </p>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php elseif ($course_id > 0): ?>
            <!-- Course not found -->
            <div class="chart-container mt-4">
                <div class="text-center py-5">
                    <i class="fas fa-exclamation-triangle fa-4x text-warning mb-4"></i>
                    <h4>Course Not Found</h4>
                    <p class="text-muted mb-4">
                        The selected course was not found.
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Update attendance summary
        function updateSummary() {
            let present = 0, absent = 0, late = 0, leave = 0;
            
            // Count each status
            document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
                switch(radio.value) {
                    case 'present': present++; break;
                    case 'absent': absent++; break;
                    case 'late': late++; break;
                    case 'leave': leave++; break;
                }
            });
            
            const total = present + absent + late + leave;
            const attendanceRate = total > 0 ? Math.round(((present + late) / total) * 100) : 0;
            
            // Update counters
            document.getElementById('presentCount').textContent = present;
            document.getElementById('absentCount').textContent = absent;
            document.getElementById('lateCount').textContent = late;
            document.getElementById('leaveCount').textContent = leave;
            document.getElementById('totalStudents').textContent = total;
            document.getElementById('attendanceRate').textContent = attendanceRate + '%';
            
            // Update progress bar
            const presentBar = document.getElementById('presentBar');
            presentBar.style.width = attendanceRate + '%';
            presentBar.textContent = attendanceRate + '%';
            
            // Update progress bar color based on rate
            if (attendanceRate >= 80) {
                presentBar.className = 'progress-bar bg-success';
            } else if (attendanceRate >= 60) {
                presentBar.className = 'progress-bar bg-warning';
            } else {
                presentBar.className = 'progress-bar bg-danger';
            }
        }
        
        // Mark all students with a specific status
        function markAll(status) {
            const radios = document.querySelectorAll(`input[value="${status}"]`);
            radios.forEach(radio => {
                radio.checked = true;
                // Update button styles
                const label = document.querySelector(`label[for="${radio.id}"]`);
                if (label) {
                    // Remove active class from all buttons in group
                    label.closest('.btn-group').querySelectorAll('.attendance-btn').forEach(btn => {
                        btn.classList.remove('active');
                    });
                    // Add active class to selected button
                    label.classList.add('active');
                }
            });
            updateSummary();
        }
        
        // Reset to default (present for all)
        function resetToDefaults() {
            if (confirm('Reset all attendance entries to Present?')) {
                markAll('present');
                // Clear all remarks
                document.querySelectorAll('input[name^="remarks"]').forEach(input => {
                    input.value = '';
                });
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add change listeners to all radio buttons
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    // Update button styles
                    const label = document.querySelector(`label[for="${this.id}"]`);
                    if (label) {
                        // Remove active class from all buttons in group
                        label.closest('.btn-group').querySelectorAll('.attendance-btn').forEach(btn => {
                            btn.classList.remove('active');
                        });
                        // Add active class to selected button
                        label.classList.add('active');
                    }
                    updateSummary();
                });
                
                // Set initial button styles
                if (radio.checked) {
                    const label = document.querySelector(`label[for="${radio.id}"]`);
                    if (label) {
                        label.classList.add('active');
                    }
                }
            });
            
            // Update summary initially
            updateSummary();
            
            // Form submission confirmation
            document.getElementById('attendanceForm').addEventListener('submit', function(e) {
                const total = parseInt(document.getElementById('totalStudents').textContent);
                const present = parseInt(document.getElementById('presentCount').textContent);
                const absent = parseInt(document.getElementById('absentCount').textContent);
                const late = parseInt(document.getElementById('lateCount').textContent);
                const leave = parseInt(document.getElementById('leaveCount').textContent);
                
                if (total === 0) {
                    e.preventDefault();
                    alert('No students to mark attendance for.');
                    return false;
                }
                
                const summary = `
                    Attendance Summary:
                    
                    📊 Total Students: ${total}
                    ✅ Present: ${present}
                    ❌ Absent: ${absent}
                    ⏰ Late: ${late}
                    🏖️ Leave: ${leave}
                    
                    Submit attendance?
                `;
                
                if (!confirm(summary)) {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Prevent shortcuts in input fields
                if (e.target.tagName === 'INPUT') return;
                
                // Number keys for quick status selection
                if (e.key >= '1' && e.key <= '4') {
                    e.preventDefault();
                    const statuses = ['present', 'absent', 'late', 'leave'];
                    const selectedRow = document.querySelector('tr:hover');
                    if (selectedRow) {
                        const radio = selectedRow.querySelector(`input[value="${statuses[parseInt(e.key) - 1]}"]`);
                        if (radio) {
                            radio.checked = true;
                            radio.dispatchEvent(new Event('change'));
                        }
                    }
                }
                
                // Ctrl+P to mark all present
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    markAll('present');
                }
                // Ctrl+A to mark all absent
                if (e.ctrlKey && e.key === 'a') {
                    e.preventDefault();
                    markAll('absent');
                }
                // Ctrl+S to submit
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    document.querySelector('button[name="submit_attendance"]').click();
                }
                // Ctrl+R to reset
                if (e.ctrlKey && e.key === 'r') {
                    e.preventDefault();
                    resetToDefaults();
                }
            });
        });
    </script>
</body>
</html>