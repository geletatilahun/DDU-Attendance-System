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

// Get all courses
$courses_sql = "
    SELECT c.course_id, c.course_code, c.course_name, d.department_name
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    WHERE c.is_active = 1
    ORDER BY d.department_name, c.course_code
";
$courses_result = $conn->query($courses_sql);

// Get all active teachers
$teachers_sql = "
    SELECT u.user_id, u.full_name, u.email, d.department_name,
           (SELECT COUNT(*) FROM course_teachers ct WHERE ct.teacher_id = u.user_id) as assigned_courses
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.role = 'teacher' AND u.is_active = 1
    ORDER BY d.department_name, u.full_name
";
$teachers_result = $conn->query($teachers_sql);

// Get selected course details if course_id is provided
$selected_course = null;
if (isset($_GET['course_id'])) {
    $course_id = (int)$_GET['course_id'];
    $course_sql = "
        SELECT c.*, d.department_name,
               (SELECT COUNT(*) FROM course_teachers WHERE course_id = c.course_id) as assigned_teachers
        FROM courses c
        LEFT JOIN departments d ON c.department_id = d.department_id
        WHERE c.course_id = ?
    ";
    $stmt = $conn->prepare($course_sql);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $selected_course = $stmt->get_result()->fetch_assoc();
    
    // Get teachers already assigned to this course
    if ($selected_course) {
        $assigned_sql = "
            SELECT u.user_id, u.full_name
            FROM course_teachers ct
            JOIN users u ON ct.teacher_id = u.user_id
            WHERE ct.course_id = ?
            ORDER BY u.full_name
        ";
        $assigned_stmt = $conn->prepare($assigned_sql);
        $assigned_stmt->bind_param("i", $course_id);
        $assigned_stmt->execute();
        $assigned_teachers = $assigned_stmt->get_result();
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <h3 class="mt-4">Bulk Assign Teachers to Courses</h3>
    
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
    
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Select Course</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="">
                        <div class="mb-3">
                            <label class="form-label">Choose Course</label>
                            <select name="course_id" class="form-control" onchange="this.form.submit()">
                                <option value="">-- Select a course --</option>
                                <?php while($course = $courses_result->fetch_assoc()): ?>
                                    <option value="<?= $course['course_id'] ?>" 
                                        <?= isset($selected_course) && $selected_course['course_id'] == $course['course_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($course['course_code']) ?> - 
                                        <?= htmlspecialchars($course['course_name']) ?>
                                        (<?= htmlspecialchars($course['department_name']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </form>
                    
                    <?php if ($selected_course): ?>
                        <div class="course-info mt-4">
                            <h6>Course Details:</h6>
                            <ul class="list-unstyled">
                                <li><strong>Code:</strong> <?= htmlspecialchars($selected_course['course_code']) ?></li>
                                <li><strong>Name:</strong> <?= htmlspecialchars($selected_course['course_name']) ?></li>
                                <li><strong>Department:</strong> <?= htmlspecialchars($selected_course['department_name']) ?></li>
                                <li><strong>Credit Hours:</strong> <?= $selected_course['credit_hours'] ?></li>
                                <li><strong>Assigned Teachers:</strong> <?= $selected_course['assigned_teachers'] ?></li>
                            </ul>
                        </div>
                        
                        <?php if (isset($assigned_teachers) && $assigned_teachers->num_rows > 0): ?>
                            <div class="assigned-teachers mt-3">
                                <h6>Currently Assigned Teachers:</h6>
                                <div class="list-group">
                                    <?php while($teacher = $assigned_teachers->fetch_assoc()): ?>
                                        <div class="list-group-item py-2">
                                            <i class="fas fa-user-tie me-2"></i>
                                            <?= htmlspecialchars($teacher['full_name']) ?>
                                        </div>
                                    <?php endwhile; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <?php if ($selected_course): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            Select Teachers for: 
                            <?= htmlspecialchars($selected_course['course_code']) ?> - 
                            <?= htmlspecialchars($selected_course['course_name']) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="course_actions.php">
                            <input type="hidden" name="action" value="bulk_assign">
                            <input type="hidden" name="course_id" value="<?= $selected_course['course_id'] ?>">
                            
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label mb-0">Select Teachers</label>
                                    <div>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllTeachers()">
                                            Select All
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselectAllTeachers()">
                                            Deselect All
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="teacher-list" style="max-height: 400px; overflow-y: auto;">
                                    <?php if ($teachers_result->num_rows > 0): ?>
                                        <?php while($teacher = $teachers_result->fetch_assoc()): ?>
                                            <?php
                                            // Check if teacher is already assigned to this course
                                            $is_assigned = false;
                                            if (isset($assigned_teachers)) {
                                                $assigned_teachers->data_seek(0);
                                                while ($assigned = $assigned_teachers->fetch_assoc()) {
                                                    if ($assigned['user_id'] == $teacher['user_id']) {
                                                        $is_assigned = true;
                                                        break;
                                                    }
                                                }
                                            }
                                            ?>
                                            <div class="form-check mb-2 p-2 border rounded">
                                                <input class="form-check-input teacher-checkbox" 
                                                       type="checkbox" 
                                                       name="teacher_ids[]" 
                                                       value="<?= $teacher['user_id'] ?>"
                                                       id="teacher_<?= $teacher['user_id'] ?>"
                                                       <?= $is_assigned ? 'disabled' : '' ?>>
                                                <label class="form-check-label w-100" for="teacher_<?= $teacher['user_id'] ?>">
                                                    <div class="d-flex justify-content-between">
                                                        <div>
                                                            <strong><?= htmlspecialchars($teacher['full_name']) ?></strong><br>
                                                            <small class="text-muted">
                                                                <i class="fas fa-envelope"></i> <?= htmlspecialchars($teacher['email']) ?> |
                                                                <i class="fas fa-building"></i> <?= htmlspecialchars($teacher['department_name']) ?>
                                                            </small>
                                                        </div>
                                                        <div class="text-end">
                                                            <span class="badge bg-info">
                                                                <?= $teacher['assigned_courses'] ?> courses
                                                            </span>
                                                            <?php if ($is_assigned): ?>
                                                                <span class="badge bg-warning">Already assigned</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <div class="alert alert-warning">No active teachers found.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-user-plus me-2"></i> Assign Selected Teachers
                                </button>
                                <a href="manage_courses.php" class="btn btn-secondary">Back to Courses</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-book-open fa-3x text-muted mb-3"></i>
                        <h5>Select a Course</h5>
                        <p class="text-muted">Please select a course from the dropdown to assign teachers.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Quick Stats -->
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">
                                <?php 
                                $total_courses = $conn->query("SELECT COUNT(*) as count FROM courses WHERE is_active = 1")->fetch_assoc()['count'];
                                echo $total_courses;
                                ?>
                            </h4>
                            <p class="mb-0">Active Courses</p>
                        </div>
                        <i class="fas fa-book fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">
                                <?php 
                                $total_teachers = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher' AND is_active = 1")->fetch_assoc()['count'];
                                echo $total_teachers;
                                ?>
                            </h4>
                            <p class="mb-0">Active Teachers</p>
                        </div>
                        <i class="fas fa-user-tie fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">
                                <?php 
                                $total_assignments = $conn->query("SELECT COUNT(*) as count FROM course_teachers")->fetch_assoc()['count'];
                                echo $total_assignments;
                                ?>
                            </h4>
                            <p class="mb-0">Total Assignments</p>
                        </div>
                        <i class="fas fa-link fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0">
                                <?php 
                                $avg_assignments = $total_courses > 0 ? round($total_assignments / $total_courses, 1) : 0;
                                echo $avg_assignments;
                                ?>
                            </h4>
                            <p class="mb-0">Avg Teachers/Course</p>
                        </div>
                        <i class="fas fa-chart-bar fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function selectAllTeachers() {
    const checkboxes = document.querySelectorAll('.teacher-checkbox:not(:disabled)');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
}

function deselectAllTeachers() {
    const checkboxes = document.querySelectorAll('.teacher-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
}

// Auto-select course from URL if present
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const courseId = urlParams.get('course_id');
    
    if (courseId) {
        // Course is already selected via GET parameter
        console.log('Course ID from URL:', courseId);
    }
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<style>
.teacher-list::-webkit-scrollbar {
    width: 8px;
}

.teacher-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.teacher-list::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
}

.teacher-list::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.form-check-input:disabled + label {
    opacity: 0.6;
    cursor: not-allowed;
}
</style>

<?php include '../includes/footer.php'; ?>