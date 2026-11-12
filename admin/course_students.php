<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../unauthorized.php');
    exit;
}

$course_id = (int)($_GET['id'] ?? 0);

if ($course_id === 0) {
    header('Location: manage_courses.php');
    exit;
}

// Get course details with proper columns
$course_sql = "
    SELECT c.*, d.department_name,
           (SELECT COUNT(*) FROM course_students WHERE course_id = c.course_id AND status = 'active') as enrolled_count
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    WHERE c.course_id = ?
";

$course_stmt = $conn->prepare($course_sql);
if (!$course_stmt) {
    die("Database error preparing course query: " . $conn->error);
}
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();

if (!$course) {
    header('Location: manage_courses.php');
    exit;
}

// Handle student enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll_students'])) {
    $student_ids = $_POST['student_ids'] ?? [];
    $enrolled_by = $_SESSION['user_id'];
    
    if (empty($student_ids)) {
        $_SESSION['error'] = "Please select at least one student";
        header("Location: course_students.php?id=$course_id");
        exit;
    }
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($student_ids as $student_id) {
        $student_id = (int)$student_id;
        
        // Check if already enrolled (active or dropped)
        $check_sql = "SELECT id FROM course_students WHERE course_id = ? AND student_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        if (!$check_stmt) {
            error_log("Check SQL error: " . $conn->error);
            continue; // Skip to next student
        }
        
        $check_stmt->bind_param("ii", $course_id, $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            // Enroll student
            $enroll_sql = "INSERT INTO course_students (course_id, student_id, enrolled_by, enrollment_date, status) 
                          VALUES (?, ?, ?, NOW(), 'active')";
            $enroll_stmt = $conn->prepare($enroll_sql);
            
            if ($enroll_stmt) {
                $enroll_stmt->bind_param("iii", $course_id, $student_id, $enrolled_by);
                if ($enroll_stmt->execute()) {
                    $success_count++;
                } else {
                    error_log("Enroll error: " . $enroll_stmt->error);
                    $error_count++;
                }
            } else {
                error_log("Prepare enroll error: " . $conn->error);
                $error_count++;
            }
        } else {
            // Student already enrolled - check status
            $existing = $check_result->fetch_assoc();
            $update_sql = "UPDATE course_students SET status = 'active' WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("i", $existing['id']);
                if ($update_stmt->execute()) {
                    $success_count++; // Reactivated
                } else {
                    $error_count++;
                }
            } else {
                $error_count++;
            }
        }
    }
    
    if ($success_count > 0) {
        $_SESSION['success'] = "Successfully enrolled $success_count student(s)";
    }
    if ($error_count > 0) {
        $_SESSION['error'] = ($_SESSION['error'] ?? '') . " $error_count student(s) failed to enroll.";
    }
    
    header("Location: course_students.php?id=$course_id");
    exit;
}

// Handle student status update (remove/drop/complete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? 'dropped';
    
    if ($student_id > 0) {
        $update_sql = "UPDATE course_students SET status = ? WHERE course_id = ? AND student_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt) {
            $update_stmt->bind_param("sii", $new_status, $course_id, $student_id);
            
            if ($update_stmt->execute()) {
                $status_labels = [
                    'active' => 'activated',
                    'dropped' => 'dropped',
                    'completed' => 'marked as completed'
                ];
                $_SESSION['success'] = "Student status updated to " . $status_labels[$new_status];
            } else {
                $_SESSION['error'] = "Error updating student status: " . $update_stmt->error;
            }
        } else {
            $_SESSION['error'] = "Database error: " . $conn->error;
        }
    }
    
    header("Location: course_students.php?id=$course_id");
    exit;
}

// Get enrolled students - ONLY ACTIVE STUDENTS
$enrolled_sql = "
    SELECT cs.id as enrollment_id, s.student_id, s.full_name, s.email, 
           cs.enrollment_date, cs.status, d.department_name
    FROM course_students cs
    JOIN students s ON cs.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE cs.course_id = ? AND cs.status = 'active'
    ORDER BY s.full_name
";

$enrolled_stmt = $conn->prepare($enrolled_sql);
if (!$enrolled_stmt) {
    die("Database error preparing enrolled students query: " . $conn->error . "<br>SQL: " . $enrolled_sql);
}
$enrolled_stmt->bind_param("i", $course_id);
$enrolled_stmt->execute();
$enrolled_students = $enrolled_stmt->get_result();

// Get dropped/completed students
$inactive_sql = "
    SELECT cs.id as enrollment_id, s.student_id, s.full_name, s.email, 
           cs.enrollment_date, cs.status, d.department_name
    FROM course_students cs
    JOIN students s ON cs.student_id = s.student_id
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE cs.course_id = ? AND cs.status != 'active'
    ORDER BY cs.status, s.full_name
";

$inactive_stmt = $conn->prepare($inactive_sql);
$inactive_stmt->bind_param("i", $course_id);
$inactive_stmt->execute();
$inactive_students = $inactive_stmt->get_result();

// Get available students (not enrolled in this course or dropped/completed)
$available_sql = "
    SELECT s.student_id, s.full_name, s.email, d.department_name
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    WHERE s.is_active = 1
      AND s.student_id NOT IN (
          SELECT student_id FROM course_students 
          WHERE course_id = ? AND status = 'active'
      )
    ORDER BY s.full_name
";

$available_stmt = $conn->prepare($available_sql);
if (!$available_stmt) {
    die("Database error preparing available students query: " . $conn->error . "<br>SQL: " . $available_sql);
}
$available_stmt->bind_param("i", $course_id);
$available_stmt->execute();
$available_students = $available_stmt->get_result();

// Get course statistics
$stats_sql = "
    SELECT 
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
        COUNT(CASE WHEN status = 'dropped' THEN 1 END) as dropped_count,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count
    FROM course_students 
    WHERE course_id = ?
";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param("i", $course_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0">Enroll Students in Course</h3>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_courses.php">Courses</a></li>
                    <li class="breadcrumb-item active">Enroll Students</li>
                </ol>
            </nav>
        </div>
        <a href="manage_courses.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Courses
        </a>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <!-- Course Information Card -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-8">
                    <h4 class="text-primary"><?= htmlspecialchars($course['course_code']) ?> - <?= htmlspecialchars($course['course_name']) ?></h4>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Department:</strong> <?= htmlspecialchars($course['department_name']) ?></p>
                            <p class="mb-1"><strong>Credit Hours:</strong> <?= $course['credit_hours'] ?></p>
                            <p class="mb-1"><strong>Semester:</strong> <?= $course['semester'] ?></p>
                        </div>
                        <div class="col-md-6">
                            <p class="mb-1"><strong>Year of Study:</strong> <?= $course['year_of_study'] ?></p>
                            <p class="mb-1"><strong>Status:</strong> 
                                <span class="badge bg-<?= $course['is_active'] ? 'success' : 'danger' ?>">
                                    <?= $course['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="display-4 text-primary mb-2"><?= $stats['active_count'] ?? 0 ?></div>
                    <p class="mb-0">Active Students</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0"><?= $stats['active_count'] ?? 0 ?></h4>
                            <p class="mb-0">Active</p>
                        </div>
                        <i class="fas fa-user-check fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0"><?= $stats['dropped_count'] ?? 0 ?></h4>
                            <p class="mb-0">Dropped</p>
                        </div>
                        <i class="fas fa-user-slash fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0"><?= $stats['completed_count'] ?? 0 ?></h4>
                            <p class="mb-0">Completed</p>
                        </div>
                        <i class="fas fa-user-graduate fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <!-- Enrolled Students Card -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-user-graduate me-2"></i>
                        Active Students (<?= $enrolled_students->num_rows ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($enrolled_students->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Student ID</th>
                                        <th>Full Name</th>
                                        <th>Email</th>
                                        <th>Department</th>
                                        <th>Enrolled Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i = 1; while($student = $enrolled_students->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td>
                                                <span class="badge bg-info">
                                                    <?= htmlspecialchars($student['student_id']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($student['full_name']) ?></strong>
                                            </td>
                                            <td>
                                                <small><?= htmlspecialchars($student['email']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($student['department_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?= date('M d, Y', strtotime($student['enrollment_date'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?= ucfirst($student['status']) ?></span>
                                            </td>
                                            <td>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-cog"></i> Actions
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <form method="POST" action="" class="dropdown-item p-0">
                                                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                                                <input type="hidden" name="new_status" value="dropped">
                                                                <button type="submit" name="update_status" class="dropdown-item text-warning" onclick="return confirm('Mark this student as dropped?')">
                                                                    <i class="fas fa-user-slash me-2"></i> Mark as Dropped
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <form method="POST" action="" class="dropdown-item p-0">
                                                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                                                <input type="hidden" name="new_status" value="completed">
                                                                <button type="submit" name="update_status" class="dropdown-item text-info" onclick="return confirm('Mark this student as completed?')">
                                                                    <i class="fas fa-graduation-cap me-2"></i> Mark as Completed
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <a class="dropdown-item" href="view_student.php?id=<?= $student['student_id'] ?>">
                                                                <i class="fas fa-eye me-2"></i> View Profile
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                            <h5>No Active Students</h5>
                            <p class="text-muted">No students are currently enrolled in this course.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Inactive Students (Dropped/Completed) -->
            <?php if ($inactive_students->num_rows > 0): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>
                            Inactive Students (<?= $inactive_students->num_rows ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Status</th>
                                        <th>Enrolled Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($student = $inactive_students->fetch_assoc()): ?>
                                        <tr class="<?= $student['status'] == 'dropped' ? 'table-warning' : 'table-info' ?>">
                                            <td><?= $student['student_id'] ?></td>
                                            <td><?= htmlspecialchars($student['full_name']) ?></td>
                                            <td>
                                                <span class="badge bg-<?= $student['status'] == 'dropped' ? 'warning' : 'info' ?>">
                                                    <?= ucfirst($student['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?= date('M d, Y', strtotime($student['enrollment_date'])) ?></small>
                                            </td>
                                            <td>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                                    <input type="hidden" name="new_status" value="active">
                                                    <button type="submit" name="update_status" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-redo"></i> Reactivate
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Enroll New Students Card -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-user-plus me-2"></i>
                        Enroll New Students
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label">Select Students to Enroll</label>
                            
                            <?php if ($available_students->num_rows > 0): ?>
                                <div class="student-list" style="max-height: 300px; overflow-y: auto;">
                                    <?php while($student = $available_students->fetch_assoc()): ?>
                                        <div class="form-check mb-2 p-2 border rounded">
                                            <input class="form-check-input student-checkbox" 
                                                   type="checkbox" 
                                                   name="student_ids[]" 
                                                   value="<?= $student['student_id'] ?>"
                                                   id="student_<?= $student['student_id'] ?>">
                                            <label class="form-check-label w-100" for="student_<?= $student['student_id'] ?>">
                                                <div>
                                                    <strong><?= htmlspecialchars($student['full_name']) ?></strong><br>
                                                    <small class="text-muted">
                                                        ID: <?= htmlspecialchars($student['student_id']) ?> |
                                                        <?= htmlspecialchars($student['email']) ?>
                                                    </small><br>
                                                    <small>
                                                        <i class="fas fa-building"></i> 
                                                        <?= htmlspecialchars($student['department_name'] ?? 'N/A') ?>
                                                    </small>
                                                </div>
                                            </label>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllStudents()">
                                        Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllStudents()">
                                        Deselect All
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    All available students are already enrolled in this course.
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($available_students->num_rows > 0): ?>
                            <button type="submit" name="enroll_students" class="btn btn-primary w-100">
                                <i class="fas fa-user-plus me-2"></i>
                                Enroll Selected Students
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="card mt-3">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="fas fa-bolt me-2"></i>
                        Quick Actions
                    </h6>
                    <div class="d-grid gap-2">
                        <a href="bulk_enroll.php?course_id=<?= $course_id ?>" class="btn btn-outline-success">
                            <i class="fas fa-file-import me-2"></i> Bulk Enroll from CSV
                        </a>
                        <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#bulkDropModal">
                            <i class="fas fa-user-minus me-2"></i> Bulk Drop Students
                        </button>
                        <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#bulkCompleteModal">
                            <i class="fas fa-graduation-cap me-2"></i> Bulk Mark as Completed
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Drop Modal -->
<div class="modal fade" id="bulkDropModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Drop Students</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to mark all active students in this course as dropped?</p>
                <p class="text-warning"><i class="fas fa-exclamation-triangle me-2"></i>This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="bulk_status_update.php" class="d-inline">
                    <input type="hidden" name="course_id" value="<?= $course_id ?>">
                    <input type="hidden" name="new_status" value="dropped">
                    <button type="submit" class="btn btn-warning">Drop All Students</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Complete Modal -->
<div class="modal fade" id="bulkCompleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Mark as Completed</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to mark all active students in this course as completed?</p>
                <p class="text-info"><i class="fas fa-info-circle me-2"></i>This is typically done at the end of a semester.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="bulk_status_update.php" class="d-inline">
                    <input type="hidden" name="course_id" value="<?= $course_id ?>">
                    <input type="hidden" name="new_status" value="completed">
                    <button type="submit" class="btn btn-info">Mark All as Completed</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function selectAllStudents() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
}

function deselectAllStudents() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<style>
.student-list::-webkit-scrollbar {
    width: 6px;
}

.student-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.student-list::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}

.student-list::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.form-check-input:checked + label {
    background-color: #e8f4ff;
    border-color: #0d6efd;
}
</style>

<?php include '../includes/footer.php'; ?>