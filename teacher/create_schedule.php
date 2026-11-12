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

/* ======================================================
   FETCH DEPARTMENT HEAD INFORMATION
====================================================== */
$head_sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        u.profile_image,
        d.department_name,
        d.department_id
    FROM users u
    LEFT JOIN departments d 
        ON u.department_id = d.department_id
    WHERE u.user_id = ?
";

$head_stmt = $conn->prepare($head_sql);
if (!$head_stmt) {
    die("Head query failed: " . $conn->error);
}
$head_stmt->bind_param("i", $department_head_id);
$head_stmt->execute();
$head_result = $head_stmt->get_result();
$department_head = $head_result->fetch_assoc();

if (!$department_head) {
    die("Department head not found.");
}

/* ======================================================
   FETCH DEPARTMENT TEACHERS
====================================================== */
$teachers_sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        u.profile_image,
        COUNT(ct.course_id) as total_courses
    FROM users u
    LEFT JOIN course_teachers ct ON u.user_id = ct.teacher_id
    WHERE u.department_id = ?
      AND u.role = 'teacher'
      AND u.is_active = 1
    GROUP BY u.user_id
    ORDER BY u.full_name
";

$teachers_stmt = $conn->prepare($teachers_sql);
$teachers_stmt->bind_param("i", $department_head['department_id']);
$teachers_stmt->execute();
$teachers_result = $teachers_stmt->get_result();

/* ======================================================
   FETCH DEPARTMENT COURSES
====================================================== */
$courses_sql = "
    SELECT 
        c.course_id,
        c.course_code,
        c.course_name,
        c.semester,
        c.credits,
        GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') as assigned_teachers
    FROM courses c
    LEFT JOIN course_teachers ct ON c.course_id = ct.course_id
    LEFT JOIN users u ON ct.teacher_id = u.user_id
    WHERE c.department_id = ?
      AND c.is_active = 1
    GROUP BY c.course_id
    ORDER BY c.semester, c.course_code
";

$courses_stmt = $conn->prepare($courses_sql);
$courses_stmt->bind_param("i", $department_head['department_id']);
$courses_stmt->execute();
$courses_result = $courses_stmt->get_result();

/* ======================================================
   FETCH ASSIGNED TEACHERS FOR EACH COURSE
====================================================== */
$assigned_teachers_sql = "
    SELECT 
        ct.course_id,
        ct.teacher_id,
        u.full_name
    FROM course_teachers ct
    JOIN users u ON ct.teacher_id = u.user_id
    WHERE EXISTS (
        SELECT 1 FROM courses c 
        WHERE c.course_id = ct.course_id 
        AND c.department_id = ?
    )
    AND ct.is_active = 1
";

$assigned_stmt = $conn->prepare($assigned_teachers_sql);
$assigned_stmt->bind_param("i", $department_head['department_id']);
$assigned_stmt->execute();
$assigned_result = $assigned_stmt->get_result();

$assigned_teachers = [];
while ($row = $assigned_result->fetch_assoc()) {
    $assigned_teachers[$row['course_id']][] = [
        'teacher_id' => $row['teacher_id'],
        'full_name' => $row['full_name']
    ];
}

/* ======================================================
   FETCH EXISTING SCHEDULES
====================================================== */
$existing_schedules_sql = "
    SELECT 
        cs.*,
        c.course_code,
        c.course_name,
        u.full_name as teacher_name,
        CONCAT(cs.start_time, ' - ', cs.end_time) as time_slot
    FROM class_schedule cs
    JOIN courses c ON cs.course_id = c.course_id
    JOIN users u ON cs.teacher_id = u.user_id
    WHERE c.department_id = ?
    ORDER BY cs.day_of_week, cs.start_time
";

$existing_stmt = $conn->prepare($existing_schedules_sql);
$existing_stmt->bind_param("i", $department_head['department_id']);
$existing_stmt->execute();
$existing_result = $existing_stmt->get_result();

/* ======================================================
   CHECK FOR CONFLICTS
====================================================== */
function checkScheduleConflicts($conn, $teacher_id, $day_of_week, $start_time, $end_time, $room_number, $exclude_id = null) {
    $conflict_sql = "
        SELECT 
            cs.*,
            c.course_code,
            c.course_name,
            u.full_name as teacher_name
        FROM class_schedule cs
        JOIN courses c ON cs.course_id = c.course_id
        JOIN users u ON cs.teacher_id = u.user_id
        WHERE cs.is_active = 1
          AND cs.day_of_week = ?
          AND (
            (cs.start_time < ? AND cs.end_time > ?) OR
            (cs.start_time < ? AND cs.end_time > ?) OR
            (cs.start_time >= ? AND cs.end_time <= ?)
          )
          AND (
            cs.teacher_id = ? OR 
            (cs.room_number = ? AND cs.room_number IS NOT NULL AND ? IS NOT NULL)
          )
    ";
    
    if ($exclude_id) {
        $conflict_sql .= " AND cs.schedule_id != ?";
    }
    
    $conflict_stmt = $conn->prepare($conflict_sql);
    
    if ($exclude_id) {
        $conflict_stmt->bind_param(
            "ssssssiisi",
            $day_of_week,
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time,
            $teacher_id,
            $room_number, $room_number,
            $exclude_id
        );
    } else {
        $conflict_stmt->bind_param(
            "ssssssiis",
            $day_of_week,
            $end_time, $start_time,
            $start_time, $end_time,
            $start_time, $end_time,
            $teacher_id,
            $room_number, $room_number
        );
    }
    
    $conflict_stmt->execute();
    return $conflict_stmt->get_result();
}

/* ======================================================
   HANDLE FORM SUBMISSION
====================================================== */
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_schedule') {
            $course_id = (int) $_POST['course_id'];
            $teacher_id = (int) $_POST['teacher_id'];
            $day_of_week = $_POST['day_of_week'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $room_number = $_POST['room_number'];
            $building = $_POST['building'];
            $session_type = $_POST['session_type'];
            $semester = (int) $_POST['semester'];
            $academic_year = $_POST['academic_year'];
            
            // Validate time
            if (strtotime($start_time) >= strtotime($end_time)) {
                $error_message = "End time must be after start time.";
            } else {
                // Check for conflicts
                $conflicts = checkScheduleConflicts($conn, $teacher_id, $day_of_week, $start_time, $end_time, $room_number);
                
                if ($conflicts->num_rows > 0) {
                    $conflict_list = [];
                    while ($conflict = $conflicts->fetch_assoc()) {
                        $conflict_type = '';
                        if ($conflict['teacher_id'] == $teacher_id) {
                            $conflict_type = 'Teacher conflict: ' . $conflict['teacher_name'] . ' already has a class';
                        } elseif ($conflict['room_number'] == $room_number) {
                            $conflict_type = 'Room conflict: ' . $room_number . ' is already booked';
                        }
                        $conflict_list[] = $conflict_type . ' for ' . $conflict['course_code'] . ' at ' . $conflict['time_slot'];
                    }
                    
                    $error_message = "Schedule conflicts detected:<br>" . implode("<br>", $conflict_list);
                } else {
                    // Insert schedule
                    $insert_sql = "
                        INSERT INTO class_schedule (
                            course_id, teacher_id, day_of_week, start_time, end_time,
                            room_number, building, session_type, semester, academic_year, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ";
                    
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        "iissssssisi",
                        $course_id,
                        $teacher_id,
                        $day_of_week,
                        $start_time,
                        $end_time,
                        $room_number,
                        $building,
                        $session_type,
                        $semester,
                        $academic_year,
                        $department_head_id
                    );
                    
                    if ($insert_stmt->execute()) {
                        $schedule_id = $conn->insert_id;
                        
                        // Create notification for teacher
                        $notification_sql = "
                            INSERT INTO schedule_notifications (
                                teacher_id, department_head_id, schedule_id, message
                            ) VALUES (?, ?, ?, ?)
                        ";
                        
                        // Get course info for notification
                        $course_info_sql = "SELECT course_code, course_name FROM courses WHERE course_id = ?";
                        $course_stmt = $conn->prepare($course_info_sql);
                        $course_stmt->bind_param("i", $course_id);
                        $course_stmt->execute();
                        $course_info = $course_stmt->get_result()->fetch_assoc();
                        
                        $message = "New schedule assigned: " . $course_info['course_code'] . " - " . $course_info['course_name'] . 
                                   " on " . $day_of_week . " at " . date('h:i A', strtotime($start_time)) . 
                                   " in " . ($room_number ?: 'TBA');
                        
                        $notify_stmt = $conn->prepare($notification_sql);
                        $notify_stmt->bind_param("iiis", $teacher_id, $department_head_id, $schedule_id, $message);
                        $notify_stmt->execute();
                        
                        $success_message = "Schedule created successfully! Teacher has been notified.";
                    } else {
                        $error_message = "Error creating schedule: " . $conn->error;
                    }
                }
            }
        } elseif ($_POST['action'] === 'update_schedule') {
            $schedule_id = (int) $_POST['schedule_id'];
            $course_id = (int) $_POST['course_id'];
            $teacher_id = (int) $_POST['teacher_id'];
            $day_of_week = $_POST['day_of_week'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $room_number = $_POST['room_number'];
            $building = $_POST['building'];
            $session_type = $_POST['session_type'];
            $semester = (int) $_POST['semester'];
            $academic_year = $_POST['academic_year'];
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            // Check for conflicts (excluding current schedule)
            $conflicts = checkScheduleConflicts($conn, $teacher_id, $day_of_week, $start_time, $end_time, $room_number, $schedule_id);
            
            if ($conflicts->num_rows > 0) {
                $error_message = "Schedule conflicts detected. Please choose different time, teacher, or room.";
            } else {
                $update_sql = "
                    UPDATE class_schedule 
                    SET course_id = ?, teacher_id = ?, day_of_week = ?, start_time = ?, end_time = ?,
                        room_number = ?, building = ?, session_type = ?, semester = ?, 
                        academic_year = ?, is_active = ?
                    WHERE schedule_id = ?
                ";
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param(
                    "iissssssisii",
                    $course_id,
                    $teacher_id,
                    $day_of_week,
                    $start_time,
                    $end_time,
                    $room_number,
                    $building,
                    $session_type,
                    $semester,
                    $academic_year,
                    $is_active,
                    $schedule_id
                );
                
                if ($update_stmt->execute()) {
                    $success_message = "Schedule updated successfully!";
                } else {
                    $error_message = "Error updating schedule: " . $conn->error;
                }
            }
        } elseif ($_POST['action'] === 'delete_schedule') {
            $schedule_id = (int) $_POST['schedule_id'];
            
            $delete_sql = "DELETE FROM class_schedule WHERE schedule_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $schedule_id);
            
            if ($delete_stmt->execute()) {
                $success_message = "Schedule deleted successfully!";
            } else {
                $error_message = "Error deleting schedule: " . $conn->error;
            }
        }
    }
}

/* ======================================================
   PAGE RENDER
====================================================== */
$page_title = "Create Schedule";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Schedule - DDU Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
        
        .card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .time-input-group {
            max-width: 150px;
        }
        
        .conflict-alert {
            border-left: 4px solid var(--ddu-red);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { background-color: rgba(220, 53, 69, 0.1); }
            50% { background-color: rgba(220, 53, 69, 0.2); }
            100% { background-color: rgba(220, 53, 69, 0.1); }
        }
        
        .available-slot {
            border-left: 4px solid var(--ddu-green);
        }
        
        .schedule-table th {
            background-color: var(--ddu-blue);
            color: white;
        }
        
        .day-column {
            background-color: #f8f9fa;
        }
        
        .time-slot-cell {
            padding: 10px;
            border: 1px solid #dee2e6;
            min-height: 80px;
            vertical-align: top;
        }
        
        .occupied-slot {
            background-color: #e8f4ff;
            border: 1px solid #007bff;
            border-radius: 5px;
            padding: 5px;
            margin-bottom: 2px;
        }
        
        .teacher-avatar {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .modal-lg-custom {
            max-width: 800px;
        }
        
        .drag-handle {
            cursor: move;
            color: #6c757d;
        }
        
        .day-badge {
            width: 100px;
        }
        
        .session-badge {
            font-size: 0.7rem;
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
            <h4><i class="fas fa-university"></i> DDU Department</h4>
            <small>Schedule Management</small>
        </div>
        
        <nav class="nav flex-column mt-3">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="teachers.php" class="nav-link">
                <i class="fas fa-chalkboard-teacher"></i> Teachers
            </a>
            <a href="courses.php" class="nav-link">
                <i class="fas fa-book"></i> Courses
            </a>
            <a href="assign_courses.php" class="nav-link">
                <i class="fas fa-tasks"></i> Assign Courses
            </a>
            <a href="create_schedule.php" class="nav-link active">
                <i class="fas fa-calendar-plus"></i> Create Schedule
            </a>
            <a href="view_schedules.php" class="nav-link">
                <i class="fas fa-calendar-alt"></i> View Schedules
            </a>
            <a href="reports.php" class="nav-link">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            
            <div class="mt-4 px-3">
                <div class="user-info">
                    <small>Logged in as:</small>
                    <h6><?= htmlspecialchars($department_head['full_name']) ?></h6>
                    <small class="text-muted">Department Head | <?= htmlspecialchars($department_head['department_name']) ?></small>
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
                    <i class="fas fa-calendar-plus"></i> Create Schedule
                </h4>
                <small class="text-muted">Welcome, <?= htmlspecialchars($department_head['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <?php if (!empty($department_head['profile_image'])): ?>
                            <img src="../uploads/profiles/<?= htmlspecialchars($department_head['profile_image']) ?>" 
                                 class="rounded-circle" width="40" height="40">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($department_head['full_name']) ?>&background=003366&color=fff" 
                                 class="rounded-circle" width="40" height="40">
                        <?php endif; ?>
                        <div class="ms-2 d-none d-md-block">
                            <div class="fw-bold"><?= htmlspecialchars($department_head['full_name']) ?></div>
                            <small class="text-muted">Department Head</small>
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
        
        <!-- Alerts -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?= $success_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?= $error_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Create New Schedule Form -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i> Create New Schedule</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="scheduleForm">
                    <input type="hidden" name="action" value="create_schedule">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="course_id" class="form-label">Select Course *</label>
                            <select class="form-select" id="course_id" name="course_id" required>
                                <option value="">Choose a course...</option>
                                <?php while ($course = $courses_result->fetch_assoc()): ?>
                                    <option value="<?= $course['course_id'] ?>">
                                        <?= htmlspecialchars($course['course_code']) ?> - 
                                        <?= htmlspecialchars($course['course_name']) ?> 
                                        (Sem <?= $course['semester'] ?>,
                                        Teachers: <?= htmlspecialchars($course['assigned_teachers'] ?? 'Not assigned') ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="teacher_id" class="form-label">Select Teacher *</label>
                            <select class="form-select" id="teacher_id" name="teacher_id" required>
                                <option value="">Choose a teacher...</option>
                                <?php 
                                $teachers_result->data_seek(0); // Reset pointer
                                while ($teacher = $teachers_result->fetch_assoc()): ?>
                                    <option value="<?= $teacher['user_id'] ?>">
                                        <?= htmlspecialchars($teacher['full_name']) ?> 
                                        (<?= $teacher['total_courses'] ?> courses)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="day_of_week" class="form-label">Day of Week *</label>
                            <select class="form-select" id="day_of_week" name="day_of_week" required>
                                <option value="">Select day...</option>
                                <option value="Monday">Monday</option>
                                <option value="Tuesday">Tuesday</option>
                                <option value="Wednesday">Wednesday</option>
                                <option value="Thursday">Thursday</option>
                                <option value="Friday">Friday</option>
                                <option value="Saturday">Saturday</option>
                                <option value="Sunday">Sunday</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="start_time" class="form-label">Start Time *</label>
                            <input type="time" class="form-control" id="start_time" name="start_time" 
                                   min="07:00" max="20:00" step="1800" required>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="end_time" class="form-label">End Time *</label>
                            <input type="time" class="form-control" id="end_time" name="end_time" 
                                   min="07:30" max="21:00" step="1800" required>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label for="session_type" class="form-label">Session Type *</label>
                            <select class="form-select" id="session_type" name="session_type" required>
                                <option value="lecture">Lecture</option>
                                <option value="lab">Lab</option>
                                <option value="tutorial">Tutorial</option>
                                <option value="exam">Exam</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="room_number" class="form-label">Room Number</label>
                            <input type="text" class="form-control" id="room_number" name="room_number" 
                                   placeholder="e.g., A-101">
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="building" class="form-label">Building</label>
                            <input type="text" class="form-control" id="building" name="building" 
                                   placeholder="e.g., Main Building">
                        </div>
                        
                        <div class="col-md-2 mb-3">
                            <label for="semester" class="form-label">Semester *</label>
                            <select class="form-select" id="semester" name="semester" required>
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?= $i ?>" <?= $i == 1 ? 'selected' : '' ?>>
                                        Semester <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2 mb-3">
                            <label for="academic_year" class="form-label">Academic Year *</label>
                            <select class="form-select" id="academic_year" name="academic_year" required>
                                <?php 
                                $current_year = date('Y');
                                for ($i = -2; $i <= 2; $i++): 
                                    $year = $current_year + $i;
                                    $academic_year = $year . '-' . ($year + 1);
                                ?>
                                    <option value="<?= $academic_year ?>" <?= $i == 0 ? 'selected' : '' ?>>
                                        <?= $academic_year ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i> Create Schedule
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearForm()">
                            <i class="fas fa-redo me-2"></i> Clear Form
                        </button>
                        <button type="button" class="btn btn-info" onclick="checkConflicts()">
                            <i class="fas fa-search me-2"></i> Check Conflicts
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Weekly Schedule Overview -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i> Weekly Schedule Overview</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered schedule-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Monday</th>
                                <th>Tuesday</th>
                                <th>Wednesday</th>
                                <th>Thursday</th>
                                <th>Friday</th>
                                <th>Saturday</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Define time slots (8 AM to 6 PM, 1-hour intervals)
                            $time_slots = [];
                            for ($hour = 8; $hour <= 18; $hour++) {
                                $time_slots[] = sprintf('%02d:00', $hour);
                            }
                            
                            // Group existing schedules by day and time
                            $schedule_grid = [];
                            $existing_result->data_seek(0);
                            while ($schedule = $existing_result->fetch_assoc()) {
                                $day = $schedule['day_of_week'];
                                $start_hour = (int) substr($schedule['start_time'], 0, 2);
                                if (!isset($schedule_grid[$day][$start_hour])) {
                                    $schedule_grid[$day][$start_hour] = [];
                                }
                                $schedule_grid[$day][$start_hour][] = $schedule;
                            }
                            
                            foreach ($time_slots as $time_slot): 
                                $hour = (int) substr($time_slot, 0, 2);
                            ?>
                                <tr>
                                    <td class="fw-bold"><?= date('h:i A', strtotime($time_slot)) ?></td>
                                    <?php foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day): ?>
                                        <td class="time-slot-cell">
                                            <?php if (isset($schedule_grid[$day][$hour])): ?>
                                                <?php foreach ($schedule_grid[$day][$hour] as $schedule): ?>
                                                    <div class="occupied-slot" 
                                                         data-bs-toggle="tooltip" 
                                                         title="<?= htmlspecialchars($schedule['course_code']) ?> - <?= htmlspecialchars($schedule['teacher_name']) ?>">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <div>
                                                                <small class="fw-bold"><?= htmlspecialchars($schedule['course_code']) ?></small><br>
                                                                <small><?= htmlspecialchars($schedule['teacher_name']) ?></small>
                                                            </div>
                                                            <div>
                                                                <span class="badge bg-primary session-badge">
                                                                    <?= ucfirst($schedule['session_type']) ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Existing Schedules -->
        <div class="card">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-list-alt me-2"></i> Existing Schedules</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Course</th>
                                <th>Teacher</th>
                                <th>Day & Time</th>
                                <th>Room</th>
                                <th>Session</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $existing_result->data_seek(0);
                            while ($schedule = $existing_result->fetch_assoc()): 
                                $is_active = $schedule['is_active'];
                            ?>
                                <tr class="<?= !$is_active ? 'table-secondary' : '' ?>">
                                    <td><?= $schedule['schedule_id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($schedule['course_code']) ?></strong><br>
                                        <small><?= htmlspecialchars($schedule['course_name']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($schedule['teacher_name']) ?></td>
                                    <td>
                                        <span class="badge bg-primary day-badge mb-1">
                                            <?= $schedule['day_of_week'] ?>
                                        </span><br>
                                        <?= date('h:i A', strtotime($schedule['start_time'])) ?> - 
                                        <?= date('h:i A', strtotime($schedule['end_time'])) ?>
                                    </td>
                                    <td><?= htmlspecialchars($schedule['room_number'] ?? 'TBA') ?></td>
                                    <td>
                                        <span class="badge bg-<?= 
                                            $schedule['session_type'] == 'lecture' ? 'primary' : 
                                            ($schedule['session_type'] == 'lab' ? 'success' : 
                                            ($schedule['session_type'] == 'tutorial' ? 'warning' : 'danger')) 
                                        ?>">
                                            <?= ucfirst($schedule['session_type']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $is_active ? 'success' : 'secondary' ?>">
                                            <?= $is_active ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editModal"
                                                    onclick="editSchedule(<?= htmlspecialchars(json_encode($schedule)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?= $schedule['schedule_id'] ?>, '<?= htmlspecialchars($schedule['course_code']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Schedule Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-lg-custom">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editModalLabel">Edit Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="" id="editForm">
                        <input type="hidden" name="action" value="update_schedule">
                        <input type="hidden" name="schedule_id" id="edit_schedule_id">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_course_id" class="form-label">Course</label>
                                <select class="form-select" id="edit_course_id" name="course_id" required>
                                    <?php 
                                    $courses_result->data_seek(0);
                                    while ($course = $courses_result->fetch_assoc()): ?>
                                        <option value="<?= $course['course_id'] ?>">
                                            <?= htmlspecialchars($course['course_code']) ?> - 
                                            <?= htmlspecialchars($course['course_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="edit_teacher_id" class="form-label">Teacher</label>
                                <select class="form-select" id="edit_teacher_id" name="teacher_id" required>
                                    <?php 
                                    $teachers_result->data_seek(0);
                                    while ($teacher = $teachers_result->fetch_assoc()): ?>
                                        <option value="<?= $teacher['user_id'] ?>">
                                            <?= htmlspecialchars($teacher['full_name']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="edit_day_of_week" class="form-label">Day of Week</label>
                                <select class="form-select" id="edit_day_of_week" name="day_of_week" required>
                                    <option value="Monday">Monday</option>
                                    <option value="Tuesday">Tuesday</option>
                                    <option value="Wednesday">Wednesday</option>
                                    <option value="Thursday">Thursday</option>
                                    <option value="Friday">Friday</option>
                                    <option value="Saturday">Saturday</option>
                                    <option value="Sunday">Sunday</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="edit_start_time" class="form-label">Start Time</label>
                                <input type="time" class="form-control" id="edit_start_time" name="start_time" required>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="edit_end_time" class="form-label">End Time</label>
                                <input type="time" class="form-control" id="edit_end_time" name="end_time" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_room_number" class="form-label">Room Number</label>
                                <input type="text" class="form-control" id="edit_room_number" name="room_number">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="edit_building" class="form-label">Building</label>
                                <input type="text" class="form-control" id="edit_building" name="building">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="edit_session_type" class="form-label">Session Type</label>
                                <select class="form-select" id="edit_session_type" name="session_type" required>
                                    <option value="lecture">Lecture</option>
                                    <option value="lab">Lab</option>
                                    <option value="tutorial">Tutorial</option>
                                    <option value="exam">Exam</option>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="edit_semester" class="form-label">Semester</label>
                                <select class="form-select" id="edit_semester" name="semester" required>
                                    <?php for ($i = 1; $i <= 8; $i++): ?>
                                        <option value="<?= $i ?>">Semester <?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label for="edit_academic_year" class="form-label">Academic Year</label>
                                <select class="form-select" id="edit_academic_year" name="academic_year" required>
                                    <?php 
                                    $current_year = date('Y');
                                    for ($i = -2; $i <= 2; $i++): 
                                        $year = $current_year + $i;
                                        $academic_year = $year . '-' . ($year + 1);
                                    ?>
                                        <option value="<?= $academic_year ?>"><?= $academic_year ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" checked>
                            <label class="form-check-label" for="edit_is_active">Active Schedule</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="editForm" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete schedule for <span id="deleteCourseName" class="fw-bold"></span>?</p>
                    <p class="text-danger"><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="" id="deleteForm">
                        <input type="hidden" name="action" value="delete_schedule">
                        <input type="hidden" name="schedule_id" id="delete_schedule_id">
                    </form>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="deleteForm" class="btn btn-danger">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Initialize time pickers
            flatpickr("#start_time", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true
            });
            
            flatpickr("#end_time", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true
            });
            
            flatpickr("#edit_start_time", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true
            });
            
            flatpickr("#edit_end_time", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i",
                time_24hr: true
            });
            
            // Auto-fill end time based on start time
            document.getElementById('start_time').addEventListener('change', function() {
                const startTime = new Date('1970-01-01T' + this.value + ':00');
                const endTime = new Date(startTime.getTime() + 60 * 60 * 1000); // Add 1 hour
                document.getElementById('end_time').value = endTime.toTimeString().substring(0, 5);
            });
        });
        
        // Clear form function
        function clearForm() {
            document.getElementById('scheduleForm').reset();
        }
        
        // Check conflicts function
        function checkConflicts() {
            const courseId = document.getElementById('course_id').value;
            const teacherId = document.getElementById('teacher_id').value;
            const dayOfWeek = document.getElementById('day_of_week').value;
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const roomNumber = document.getElementById('room_number').value;
            
            if (!courseId || !teacherId || !dayOfWeek || !startTime || !endTime) {
                alert('Please fill all required fields first.');
                return;
            }
            
            // In real implementation, this would make an AJAX call
            alert('Checking conflicts... This would check against existing schedules.');
            
            // Simulate conflict check
            const hasConflict = Math.random() > 0.7; // 30% chance of conflict for demo
            
            if (hasConflict) {
                document.getElementById('scheduleForm').classList.add('conflict-alert');
                setTimeout(() => {
                    document.getElementById('scheduleForm').classList.remove('conflict-alert');
                }, 3000);
            } else {
                document.getElementById('scheduleForm').classList.add('available-slot');
                setTimeout(() => {
                    document.getElementById('scheduleForm').classList.remove('available-slot');
                }, 3000);
            }
        }
        
        // Edit schedule function
        function editSchedule(schedule) {
            document.getElementById('edit_schedule_id').value = schedule.schedule_id;
            document.getElementById('edit_course_id').value = schedule.course_id;
            document.getElementById('edit_teacher_id').value = schedule.teacher_id;
            document.getElementById('edit_day_of_week').value = schedule.day_of_week;
            document.getElementById('edit_start_time').value = schedule.start_time;
            document.getElementById('edit_end_time').value = schedule.end_time;
            document.getElementById('edit_room_number').value = schedule.room_number || '';
            document.getElementById('edit_building').value = schedule.building || '';
            document.getElementById('edit_session_type').value = schedule.session_type;
            document.getElementById('edit_semester').value = schedule.semester;
            document.getElementById('edit_academic_year').value = schedule.academic_year;
            document.getElementById('edit_is_active').checked = schedule.is_active == 1;
        }
        
        // Confirm delete function
        function confirmDelete(scheduleId, courseName) {
            document.getElementById('delete_schedule_id').value = scheduleId;
            document.getElementById('deleteCourseName').textContent = courseName;
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
            deleteModal.show();
        }
        
        // Auto-assign teacher based on course selection
        document.getElementById('course_id').addEventListener('change', function() {
            const courseId = this.value;
            // In real implementation, fetch assigned teachers for this course via AJAX
            // For now, we'll just enable the teacher dropdown
            document.getElementById('teacher_id').disabled = false;
        });
        
        // Validate time overlap
        function validateTimeOverlap() {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            if (startTime && endTime) {
                const start = new Date('1970-01-01T' + startTime + ':00');
                const end = new Date('1970-01-01T' + endTime + ':00');
                
                if (start >= end) {
                    alert('End time must be after start time.');
                    return false;
                }
                
                // Check if duration is reasonable (max 3 hours)
                const duration = (end - start) / (1000 * 60 * 60); // in hours
                if (duration > 3) {
                    if (!confirm(`Class duration is ${duration} hours. Are you sure this is correct?`)) {
                        return false;
                    }
                }
            }
            return true;
        }
        
        // Form submission validation
        document.getElementById('scheduleForm').addEventListener('submit', function(e) {
            if (!validateTimeOverlap()) {
                e.preventDefault();
            }
        });
        
        // Generate schedule for multiple weeks
        function generateRecurringSchedule() {
            const weeks = prompt('How many weeks should this schedule repeat?', '15');
            if (weeks && !isNaN(weeks)) {
                // In real implementation, this would create multiple schedule entries
                alert(`This would create schedule for ${weeks} weeks.`);
            }
        }
        
        // Export schedule to CSV
        function exportScheduleToCSV() {
            // In real implementation, generate and download CSV
            alert('Exporting schedule to CSV...');
        }
        
        // Print schedule
        function printSchedule() {
            window.print();
        }
    </script>
</body>
</html>