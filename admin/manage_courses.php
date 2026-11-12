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

$search = $_GET['search'] ?? '';

$where = '';
if ($search !== '') {
    $search = $conn->real_escape_string($search);
    $where = "WHERE 
        c.course_code LIKE '%$search%' OR
        c.course_name LIKE '%$search%' OR
        d.department_name LIKE '%$search%'";
}

$sql = "
SELECT 
    c.course_id,
    c.course_code,
    c.course_name,
    c.credit_hours,
    c.semester,
    c.year_of_study,
    c.is_active,
    d.department_name,
    d.department_id,
    (SELECT COUNT(*) FROM course_teachers WHERE course_id = c.course_id) as assigned_teachers,
    (SELECT COUNT(*) FROM course_students WHERE course_id = c.course_id) as enrolled_students
FROM courses c
LEFT JOIN departments d 
    ON d.department_id = c.department_id
$where
ORDER BY c.course_id DESC
";

$courses = $conn->query($sql);
if (!$courses) {
    die("SQL Error: " . $conn->error);
}

// Get all teachers for assignment
$teachers_sql = "
    SELECT u.user_id, u.full_name, u.email, d.department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.role = 'teacher' AND u.is_active = 1
    ORDER BY u.full_name
";
$teachers_result = $conn->query($teachers_sql);

include '../includes/header.php';
?>

<div class="container-fluid">
    <h3 class="mt-4">Manage Courses</h3>

    <div class="card mb-3">
        <div class="card-body d-flex justify-content-between">
            <form class="d-flex" method="GET">
                <input class="form-control me-2" name="search"
                       placeholder="Search courses by code, name, or department"
                       value="<?= htmlspecialchars($search) ?>">
                <button class="btn btn-primary">Search</button>
            </form>

            <div>
                <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                    <i class="fas fa-plus"></i> Add Course
                </button>
                <a href="bulk_assign.php" class="btn btn-info">
                    <i class="fas fa-users"></i> Bulk Assign
                </a>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Credit</th>
                        <th>Department</th>
                        <th>Teachers</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($courses->num_rows > 0): ?>
                        <?php $i=1; while($row=$courses->fetch_assoc()): ?>
                            <?php
                            // Get assigned teachers for this course
                            $assigned_teachers_sql = "
                                SELECT u.user_id, u.full_name, u.email
                                FROM course_teachers ct
                                JOIN users u ON ct.teacher_id = u.user_id
                                WHERE ct.course_id = ?
                                ORDER BY u.full_name
                            ";
                            $stmt = $conn->prepare($assigned_teachers_sql);
                            $stmt->bind_param("i", $row['course_id']);
                            $stmt->execute();
                            $assigned_teachers = $stmt->get_result();
                            ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['course_code']) ?></strong><br>
                                    <small class="text-muted">
                                        Year <?= $row['year_of_study'] ?>, Sem <?= $row['semester'] ?>
                                    </small>
                                </td>
                                <td><?= htmlspecialchars($row['course_name']) ?></td>
                                <td><?= $row['credit_hours'] ?></td>
                                <td><?= htmlspecialchars($row['department_name'] ?: 'Not assigned') ?></td>
                                <td>
                                    <span class="badge bg-info"><?= $row['assigned_teachers'] ?> teachers</span>
                                    <?php if ($assigned_teachers->num_rows > 0): ?>
                                        <div class="mt-1">
                                            <?php while($teacher = $assigned_teachers->fetch_assoc()): ?>
                                                <small class="d-block">
                                                    <i class="fas fa-user-tie"></i> 
                                                    <?= htmlspecialchars($teacher['full_name']) ?>
                                                </small>
                                            <?php endwhile; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= $row['enrolled_students'] ?> students</span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $row['is_active'] ? 'success' : 'danger' ?>">
                                        <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#assignTeacherModal"
                                                data-course-id="<?= $row['course_id'] ?>"
                                                data-course-name="<?= htmlspecialchars($row['course_code'] . ' - ' . $row['course_name']) ?>"
                                                onclick="setCourseData(this)">
                                            <i class="fas fa-user-plus"></i> Assign
                                        </button>
                                        <button class="btn btn-outline-warning"
                                                onclick="editCourse(<?= $row['course_id'] ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-outline-danger"
                                                onclick="deleteCourse(<?= $row['course_id'] ?>)">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                    <div class="mt-1">
                                        <a href="course_students.php?id=<?= $row['course_id'] ?>" 
                                           class="btn btn-outline-info btn-sm">
                                            <i class="fas fa-user-graduate"></i> Enroll Students
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted">No courses found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ADD COURSE MODAL -->
<div class="modal fade" id="addCourseModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="course_actions.php">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Code *</label>
                            <input type="text" class="form-control" name="course_code" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Name *</label>
                            <input type="text" class="form-control" name="course_name" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Credit Hours *</label>
                            <input type="number" class="form-control" name="credit_hours" min="1" max="10" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Year of Study *</label>
                            <select class="form-control" name="year_of_study" required>
                                <option value="">Select Year</option>
                                <option value="1">Year 1</option>
                                <option value="2">Year 2</option>
                                <option value="3">Year 3</option>
                                <option value="4">Year 4</option>
                                <option value="5">Year 5</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Semester *</label>
                            <select class="form-control" name="semester" required>
                                <option value="">Select Semester</option>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                                <option value="3">Semester 3</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department *</label>
                            <select name="department_id" class="form-control" required>
                                <option value="">Select Department</option>
                                <?php
                                $deps = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
                                while($d = $deps->fetch_assoc()):
                                ?>
                                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <select name="is_active" class="form-control">
                                <option value="1" selected>Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Course Description (Optional)</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="action" value="add">
                        <i class="fas fa-save"></i> Save Course
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ASSIGN TEACHER MODAL -->
<div class="modal fade" id="assignTeacherModal">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="assign_teacher_action.php">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Teacher to Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="course_id_input" name="course_id">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        Course: <strong id="course_display"></strong>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Select Teacher *</label>
                            <select name="teacher_id" class="form-control" required id="teacherSelect">
                                <option value="">Select a teacher</option>
                                <?php 
                                // Re-fetch teachers for this modal
                                $modal_teachers_sql = "
                                    SELECT u.user_id, u.full_name, u.email, d.department_name
                                    FROM users u
                                    LEFT JOIN departments d ON u.department_id = d.department_id
                                    WHERE u.role = 'teacher' AND u.is_active = 1
                                    ORDER BY u.full_name
                                ";
                                $modal_teachers_result = $conn->query($modal_teachers_sql);
                                
                                if ($modal_teachers_result && $modal_teachers_result->num_rows > 0):
                                    while($teacher = $modal_teachers_result->fetch_assoc()):
                                ?>
                                    <option value="<?= $teacher['user_id'] ?>">
                                        <?= htmlspecialchars($teacher['full_name']) ?> 
                                        (<?= htmlspecialchars($teacher['department_name'] ?? 'No Department') ?>)
                                    </option>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                    <option value="">No teachers found</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-control">
                                <option value="primary">Primary Instructor</option>
                                <option value="assistant">Assistant Instructor</option>
                                <option value="lab">Lab Instructor</option>
                                <option value="guest">Guest Lecturer</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date (Optional)</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                    </div>
                    
                    <!-- Already Assigned Teachers -->
                    <div id="assignedTeachersSection" class="mt-3">
                        <h6>Currently Assigned Teachers</h6>
                        <div id="assignedTeachersList" class="list-group">
                            <!-- Will be populated by AJAX -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Assign Teacher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCourse(id){
    location.href = 'edit_course.php?id=' + id;
}

function deleteCourse(id){
    if(confirm('Are you sure you want to delete this course? This will also remove all teacher assignments and student enrollments.')){
        location.href = 'course_actions.php?action=delete&id=' + id;
    }
}

function setCourseData(element) {
    const courseId = element.getAttribute('data-course-id');
    const courseName = element.getAttribute('data-course-name');
    
    document.getElementById('course_id_input').value = courseId;
    document.getElementById('course_display').textContent = courseName;
    
    // Load assigned teachers via AJAX
    loadAssignedTeachers(courseId);
}

function loadAssignedTeachers(courseId) {
    fetch(`get_assigned_teachers.php?course_id=${courseId}`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('assignedTeachersList').innerHTML = data;
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('assignedTeachersList').innerHTML = 
                '<div class="alert alert-danger">Failed to load assigned teachers</div>';
        });
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Refresh teachers list when modal is shown
    $('#assignTeacherModal').on('show.bs.modal', function () {
        // Reset teacher select
        document.getElementById('teacherSelect').selectedIndex = 0;
    });
});
// Add this to your existing JavaScript
function testAssignment() {
    const courseId = document.getElementById('course_id_input').value;
    const teacherId = document.getElementById('teacherSelect').value;
    
    if (!courseId || !teacherId) {
        alert('Please select both course and teacher');
        return;
    }
    
    console.log('Testing assignment:', {
        course_id: courseId,
        teacher_id: teacherId
    });
    
    // Send a test request
    fetch('test_assign_action.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `course_id=${courseId}&teacher_id=${teacherId}&role=primary`
    })
    .then(response => response.text())
    .then(data => {
        console.log('Test response:', data);
        // Open in new window for debugging
        const win = window.open();
        win.document.write(data);
    });
}

// Add a debug button to the modal footer (optional)
// Add this after the existing submit button in the modal footer:
// <button type="button" class="btn btn-warning" onclick="testAssignment()">Debug Test</button>
</script>

<?php include '../includes/footer.php'; ?>