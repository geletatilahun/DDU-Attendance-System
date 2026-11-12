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
    header('Location: ../dashboard.php');
    exit;
}

$search = $_GET['search'] ?? '';
$department_filter = $_GET['department_id'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if ($search !== '') {
    $search = $conn->real_escape_string($search);
    $where_conditions[] = "(s.student_id_no LIKE '%$search%' OR s.full_name LIKE '%$search%' OR s.email LIKE '%$search%')";
}

if ($department_filter !== '') {
    $where_conditions[] = "s.department_id = " . intval($department_filter);
}

if ($status_filter !== '') {
    $where_conditions[] = "s.is_active = " . intval($status_filter);
}

$where = '';
if (!empty($where_conditions)) {
    $where = "WHERE " . implode(" AND ", $where_conditions);
}

// SQL query - UPDATED with proper table structure
$sql = "
SELECT 
    s.student_id,
    s.student_id_no,
    s.full_name,
    s.email,
    s.phone,
    s.program,
    s.year_of_study,
    s.semester,
    s.is_active,
    d.department_name,
    (SELECT COUNT(*) FROM course_students cs WHERE cs.student_id = s.student_id) as course_count
FROM students s
LEFT JOIN departments d ON d.department_id = s.department_id
$where
ORDER BY s.student_id DESC
";

$students = $conn->query($sql);
if (!$students) {
    die("Database query failed: " . $conn->error);
}

// Get total count for stats
$count_sql = "SELECT COUNT(*) as total FROM students s $where";
$count_result = $conn->query($count_sql);
$total_students = $count_result ? $count_result->fetch_assoc()['total'] : 0;

// Fetch active courses for enrollment
$courses = $conn->query("SELECT course_id, course_code, course_name FROM courses WHERE is_active = 1 ORDER BY course_code");

// Fetch departments for filter
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");

include '../includes/header.php';
?>

<div class="container-fluid">
    <h3 class="mt-4">Manage Students</h3>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body text-center">
                    <h1 class="display-6 mb-2"><?= number_format($total_students) ?></h1>
                    <p class="mb-0">Total Students</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body text-center">
                    <h1 class="display-6 mb-2">
                        <?php 
                        $active_sql = "SELECT COUNT(*) as active FROM students WHERE is_active = 1";
                        $active_result = $conn->query($active_sql);
                        echo number_format($active_result->fetch_assoc()['active']);
                        ?>
                    </h1>
                    <p class="mb-0">Active Students</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body text-center">
                    <h1 class="display-6 mb-2">
                        <?php 
                        $dept_sql = "SELECT COUNT(DISTINCT department_id) as dept_count FROM students";
                        $dept_result = $conn->query($dept_sql);
                        echo number_format($dept_result->fetch_assoc()['dept_count']);
                        ?>
                    </h1>
                    <p class="mb-0">Departments</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-dark">
                <div class="card-body text-center">
                    <h1 class="display-6 mb-2">
                        <a href="javascript:void(0)" class="text-decoration-none text-dark" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                            <i class="fas fa-user-plus"></i>
                        </a>
                    </h1>
                    <a href="javascript:void(0)" class="text-decoration-none text-dark" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                        <p class="mb-0">Add Student</p>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input class="form-control" name="search" placeholder="Search by ID, Name, Email" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="department_id">
                        <option value="">All Departments</option>
                        <?php 
                        $departments->data_seek(0);
                        while($dept = $departments->fetch_assoc()): 
                            $selected = ($department_filter == $dept['department_id']) ? 'selected' : '';
                        ?>
                            <option value="<?= $dept['department_id'] ?>" <?= $selected ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100">Search</button>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                        <i class="fas fa-user-plus"></i> Add Student
                    </button>
                    <a href="import_students.php" class="btn btn-info">
                        <i class="fas fa-file-import"></i> Import CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>ID No</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Department</th>
                        <th>Program</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Courses</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($students->num_rows > 0): ?>
                        <?php while($row = $students->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?= htmlspecialchars($row['student_id_no']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($row['full_name']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['department_name']) ?></td>
                                <td><?= htmlspecialchars($row['program'] ?: '-') ?></td>
                                <td>
                                    <span class="badge bg-secondary">Year <?= $row['year_of_study'] ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-info">Sem <?= $row['semester'] ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-warning"><?= $row['course_count'] ?> courses</span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $row['is_active'] ? 'success' : 'danger' ?>">
                                        <?= $row['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-outline-info" onclick="viewStudent(<?= $row['student_id'] ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-warning" onclick="editStudent(<?= $row['student_id'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-outline-primary" onclick="enrollStudent(<?= $row['student_id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')">
                                            <i class="fas fa-book"></i>
                                        </button>
                                        <button class="btn btn-outline-danger" onclick="deleteStudent(<?= $row['student_id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name'])) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center py-4">
                                <i class="fas fa-user-slash fa-2x text-muted mb-3"></i>
                                <h5>No Students Found</h5>
                                <p class="text-muted">No students match your search criteria.</p>
                                <a href="manage_students.php" class="btn btn-primary">Clear Filters</a>
                                <button class="btn btn-success ms-2" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                                    <i class="fas fa-user-plus"></i> Add Student
                                </button>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination would go here -->
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div>
                    Showing <?= $students->num_rows ?> of <?= number_format($total_students) ?> students
                </div>
                <div>
                    <a href="?export=csv&<?= http_build_query($_GET) ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-download"></i> Export CSV
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ADD STUDENT MODAL -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="addStudentForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add Student</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Student ID No *</label>
                            <input class="form-control" name="student_id_no" placeholder="STU-2024-001" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name *</label>
                            <input class="form-control" name="full_name" placeholder="John Doe" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email *</label>
                            <input class="form-control" name="email" type="email" placeholder="john.doe@ddu.ac.in" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone</label>
                            <input class="form-control" name="phone" placeholder="+91 9876543210">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department *</label>
                            <select name="department_id" class="form-control" required>
                                <option value="">Select Department</option>
                                <?php
                                $departments->data_seek(0);
                                while($d = $departments->fetch_assoc()):
                                ?>
                                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Program</label>
                            <input class="form-control" name="program" placeholder="e.g., B.Tech Computer Science">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Year of Study *</label>
                            <select name="year_of_study" class="form-control" required>
                                <option value="">Select Year</option>
                                <?php for($i = 1; $i <= 4; $i++): ?>
                                    <option value="<?= $i ?>">Year <?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Semester *</label>
                            <select name="semester" class="form-control" required>
                                <option value="">Select Semester</option>
                                <?php for($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?= $i ?>">Semester <?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Academic Year *</label>
                            <?php
                            $currentYear = date('Y');
                            $defaultYear = $currentYear . '-' . ($currentYear + 1);
                            ?>
                            <input class="form-control" name="academic_year" value="<?= $defaultYear ?>" placeholder="YYYY-YYYY" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2" placeholder="Full address"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Emergency Contact</label>
                            <input class="form-control" name="emergency_contact" placeholder="+91 9876543210">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active">Active Account</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-info-circle"></i> 
                            Password will be auto-generated and sent to student's email. 
                            Student can reset password after first login.
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ENROLL IN COURSES MODAL -->
<div class="modal fade" id="enrollStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="enrollStudentForm">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Enroll Student in Courses</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <input type="hidden" id="enroll_student_id" name="student_id">
                    <div class="mb-3">
                        <label class="form-label">Student: <strong id="enroll_student_name" class="text-primary"></strong></label>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Select Courses to Enroll</label>
                        <div id="coursesContainer" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <?php 
                            $courses->data_seek(0);
                            if ($courses->num_rows > 0):
                                while($course = $courses->fetch_assoc()): 
                            ?>
                                <div class="form-check mb-2">
                                    <input class="form-check-input course-checkbox" type="checkbox" name="course_ids[]" 
                                           value="<?= $course['course_id'] ?>" id="course_<?= $course['course_id'] ?>">
                                    <label class="form-check-label" for="course_<?= $course['course_id'] ?>">
                                        <?= htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']) ?>
                                    </label>
                                </div>
                            <?php 
                                endwhile;
                            else:
                            ?>
                                <div class="alert alert-warning">No active courses available.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <select class="form-control" id="academic_year" name="academic_year" required>
                                    <?php
                                    $currentYear = date('Y');
                                    for($i = $currentYear - 1; $i <= $currentYear + 1; $i++):
                                        $yearRange = $i . '-' . ($i + 1);
                                    ?>
                                        <option value="<?= $yearRange ?>" <?= ($yearRange == ($currentYear) . '-' . ($currentYear+1)) ? 'selected' : '' ?>>
                                            <?= $yearRange ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="semester" class="form-label">Semester</label>
                                <select class="form-control" id="semester" name="semester" required>
                                    <option value="1">Semester 1</option>
                                    <option value="2" selected>Semester 2</option>
                                    <option value="3">Summer</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div id="currentEnrollments" class="mt-4">
                        <h6>Current Enrollments</h6>
                        <div id="enrollmentsList" class="alert alert-info">
                            Loading enrollments...
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Enroll in Selected Courses</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// View student details
function viewStudent(id) {
    window.location.href = 'view_student.php?id=' + id;
}

// Edit student
function editStudent(id) {
    window.location.href = 'edit_student.php?id=' + id;
}

// Enroll student in courses
function enrollStudent(id, name) {
    document.getElementById('enroll_student_id').value = id;
    document.getElementById('enroll_student_name').textContent = name;
    
    // Reset checkboxes
    document.querySelectorAll('.course-checkbox').forEach(cb => cb.checked = false);
    
    // Load current enrollments
    loadCurrentEnrollments(id);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('enrollStudentModal'));
    modal.show();
}

// Load current enrollments
function loadCurrentEnrollments(studentId) {
    const container = document.getElementById('enrollmentsList');
    container.innerHTML = '<div class="text-center"><div class="spinner-border spinner-border-sm"></div> Loading...</div>';
    
    fetch(`../api/enrollments.php?student_id=${studentId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.enrollments && data.enrollments.length > 0) {
                let html = '<ul class="list-group list-group-flush">';
                data.enrollments.forEach(enrollment => {
                    html += `
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${enrollment.course_code}</strong> - ${enrollment.course_name}
                            <br>
                            <small class="text-muted">
                                ${enrollment.academic_year} | Semester ${enrollment.semester}
                            </small>
                        </div>
                        <div>
                            <span class="badge bg-${enrollment.is_active ? 'success' : 'secondary'} me-2">
                                ${enrollment.is_active ? 'Active' : 'Inactive'}
                            </span>
                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                    onclick="unenrollCourse(${enrollment.enrollment_id}, ${studentId})">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </li>`;
                });
                html += '</ul>';
                container.innerHTML = html;
            } else {
                container.innerHTML = '<div class="alert alert-warning mb-0">No current enrollments found.</div>';
            }
        })
        .catch(error => {
            console.error('Error loading enrollments:', error);
            container.innerHTML = '<div class="alert alert-danger mb-0">Failed to load enrollments: ' + error.message + '</div>';
        });
}

// Unenroll from course
function unenrollCourse(enrollmentId, studentId) {
    if (confirm('Are you sure you want to remove this enrollment?')) {
        fetch(`../api/enrollments.php?enrollment_id=${enrollmentId}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Enrollment removed successfully!');
                loadCurrentEnrollments(studentId);
            } else {
                alert('Error: ' + (data.message || 'Failed to remove enrollment'));
            }
        })
        .catch(error => {
            console.error('Error removing enrollment:', error);
            alert('Failed to remove enrollment. Please try again.');
        });
    }
}

// Delete student
function deleteStudent(id, name) {
    if (confirm(`Are you sure you want to delete student "${name}"?\n\nThis action cannot be undone!`)) {
        fetch('../api/students.php?id=' + id, {
            method: 'DELETE'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert('Student deleted successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to delete student'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Network error. Please try again.');
        });
    }
}

// Handle add student form submission
document.getElementById('addStudentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;
    
    fetch('../api/students.php', {
        method: 'POST',
        body: new FormData(this)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert('Student added successfully!');
            location.reload();
        } else {
            alert(data.message || 'Failed to add student');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    });
});

// Handle enroll student form submission
document.getElementById('enrollStudentForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const checkboxes = document.querySelectorAll('.course-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one course');
        return;
    }
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Processing...';
    submitBtn.disabled = true;
    
    const formData = new FormData(this);
    
    fetch('../api/enrollments.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            alert(data.message || 'Student enrolled successfully!');
            const studentId = document.getElementById('enroll_student_id').value;
            loadCurrentEnrollments(studentId);
            // Uncheck all checkboxes
            document.querySelectorAll('.course-checkbox').forEach(cb => cb.checked = false);
        } else {
            alert(data.message || 'Failed to enroll student');
        }
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Network error. Please try again.');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    });
});

// Auto-generate email based on name
document.querySelector('input[name="full_name"]')?.addEventListener('blur', function() {
    const emailField = document.querySelector('input[name="email"]');
    if (emailField && !emailField.value) {
        const name = this.value.toLowerCase().replace(/\s+/g, '.');
        const idField = document.querySelector('input[name="student_id_no"]');
        const id = idField ? idField.value.toLowerCase() : '';
        if (name && id) {
            emailField.value = name + '.' + id + '@ddu.ac.in';
        }
    }
});
</script>

<?php include '../includes/footer.php'; ?>