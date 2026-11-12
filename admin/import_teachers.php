<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!is_admin()) {
    header('Location: /ddu_attendance/login.php');
    exit();
}

$page_title = "Manage Teachers";
include '../includes/header.php';

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search and filter
$search = isset($_GET['search']) ? $_GET['search'] : '';
$department_filter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_clause = "WHERE u.role = 'teacher'";
$params = [];
$types = '';

if (!empty($search)) {
    $where_clause .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $types .= 'sss';
}

if ($department_filter > 0) {
    $where_clause .= " AND u.department_id = ?";
    $params[] = $department_filter;
    $types .= 'i';
}

if (!empty($status_filter)) {
    $where_clause .= " AND u.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM users u $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Get teachers with department info
$sql = "SELECT u.*, d.name as department_name, d.code as department_code,
               (SELECT COUNT(*) FROM course_teachers WHERE teacher_id = u.id) as course_count
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        $where_clause
        ORDER BY u.full_name
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$teachers = $stmt->get_result();

// Get all departments for filter dropdown
$departments_sql = "SELECT * FROM departments WHERE status = 'active' ORDER BY name";
$departments_result = $conn->query($departments_sql);
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4">Manage Teachers</h1>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-filter"></i> Filters
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <input type="text" class="form-control" name="search" placeholder="Search teachers..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-3">
                            <select class="form-control" name="department_id">
                                <option value="">All Departments</option>
                                <?php while ($dept = $departments_result->fetch_assoc()): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-control" name="status">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="manage_teachers.php" class="btn btn-secondary">Reset</a>
                        </div>
                        <div class="col-md-2 text-end">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
                                <i class="fas fa-plus"></i> Add Teacher
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Teachers Table -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-chalkboard-teacher"></i> Teachers List
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Department</th>
                                    <th>Courses Assigned</th>
                                    <th>Status</th>
                                    <th>Created At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($teacher = $teachers->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $teacher['id']; ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($teacher['full_name']); ?></strong>
                                        <?php if ($teacher['is_hod']): ?>
                                        <span class="badge bg-info ms-1">HOD</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($teacher['username']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td>
                                        <?php if ($teacher['department_name']): ?>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($teacher['department_code'] . ' - ' . $teacher['department_name']); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $teacher['course_count']; ?> courses</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $teacher['status'] == 'active' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($teacher['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($teacher['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-info" onclick="viewTeacher(<?php echo $teacher['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn btn-sm btn-warning" onclick="editTeacher(<?php echo $teacher['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-sm btn-secondary" onclick="manageCourses(<?php echo $teacher['id']; ?>)">
                                                <i class="fas fa-book"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="deleteTeacher(<?php echo $teacher['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo $search; ?>&department_id=<?php echo $department_filter; ?>&status=<?php echo $status_filter; ?>">Previous</a>
                            </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo $search; ?>&department_id=<?php echo $department_filter; ?>&status=<?php echo $status_filter; ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo $search; ?>&department_id=<?php echo $department_filter; ?>&status=<?php echo $status_filter; ?>">Next</a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics -->
            <div class="row">
                <div class="col-md-3">
                    <div class="card bg-primary text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Total Teachers</div>
                                    <div class="fs-2 fw-bold"><?php echo $total_rows; ?></div>
                                </div>
                                <i class="fas fa-chalkboard-teacher fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Active Teachers</div>
                                    <div class="fs-2 fw-bold">
                                        <?php
                                        $active_sql = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher' AND status = 'active'";
                                        $active_result = $conn->query($active_sql)->fetch_assoc();
                                        echo $active_result['count'];
                                        ?>
                                    </div>
                                </div>
                                <i class="fas fa-user-check fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">HODs</div>
                                    <div class="fs-2 fw-bold">
                                        <?php
                                        $hod_sql = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher' AND is_hod = 1";
                                        $hod_result = $conn->query($hod_sql)->fetch_assoc();
                                        echo $hod_result['count'];
                                        ?>
                                    </div>
                                </div>
                                <i class="fas fa-user-tie fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Without Department</div>
                                    <div class="fs-2 fw-bold">
                                        <?php
                                        $no_dept_sql = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher' AND department_id IS NULL";
                                        $no_dept_result = $conn->query($no_dept_sql)->fetch_assoc();
                                        echo $no_dept_result['count'];
                                        ?>
                                    </div>
                                </div>
                                <i class="fas fa-building fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="addTeacherForm" action="../api/teachers.php" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Teacher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="full_name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Employee ID</label>
                                <input type="text" class="form-control" name="employee_id">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Password *</label>
                                <input type="password" class="form-control" name="password" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" class="form-control" name="confirm_password" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Department</label>
                                <select class="form-control" name="department_id">
                                    <option value="">Select Department</option>
                                    <?php
                                    $departments_result->data_seek(0); // Reset pointer
                                    while ($dept = $departments_result->fetch_assoc()):
                                    ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Designation</label>
                                <select class="form-control" name="designation">
                                    <option value="">Select Designation</option>
                                    <option value="professor">Professor</option>
                                    <option value="associate_professor">Associate Professor</option>
                                    <option value="assistant_professor">Assistant Professor</option>
                                    <option value="lecturer">Lecturer</option>
                                    <option value="visiting_faculty">Visiting Faculty</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Qualification</label>
                                <input type="text" class="form-control" name="qualification" placeholder="e.g., Ph.D., M.Tech, etc.">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" name="date_of_birth">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Date of Joining</label>
                                <input type="date" class="form-control" name="date_of_joining" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Gender</label>
                                <select class="form-control" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-control" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_hod" value="1" id="isHod">
                            <label class="form-check-label" for="isHod">
                                Head of Department (HOD)
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="3" placeholder="Enter full address..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Teacher</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Courses Modal -->
<div class="modal fade" id="manageCoursesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="manageCoursesForm">
                <div class="modal-header">
                    <h5 class="modal-title">Manage Teacher's Courses</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="teacherInfo" class="mb-4">
                        <!-- Teacher info will be loaded here -->
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assign Courses</label>
                        <select class="form-control" id="courseSelect" multiple style="height: 200px;">
                            <?php
                            $all_courses_sql = "SELECT c.*, d.name as department_name FROM courses c
                                               JOIN departments d ON c.department_id = d.id
                                               WHERE c.status = 'active'
                                               ORDER BY d.name, c.semester, c.code";
                            $all_courses = $conn->query($all_courses_sql);
                            while ($course = $all_courses->fetch_assoc()):
                            ?>
                            <option value="<?php echo $course['id']; ?>">
                                <?php echo htmlspecialchars($course['department_name'] . ' - ' . $course['code'] . ' - ' . $course['name'] . ' (Sem ' . $course['semester'] . ')'); ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <small class="text-muted">Hold Ctrl/Cmd to select multiple courses</small>
                    </div>
                    <div id="assignedCourses" class="mt-3">
                        <!-- Assigned courses will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentTeacherId = null;

function viewTeacher(id) {
    window.location.href = `view_teacher.php?id=${id}`;
}

function editTeacher(id) {
    window.location.href = `edit_teacher.php?id=${id}`;
}

function deleteTeacher(id) {
    if (confirm('Are you sure you want to delete this teacher?')) {
        fetch(`../api/teachers.php?id=${id}`, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Teacher deleted successfully');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

function manageCourses(teacherId) {
    currentTeacherId = teacherId;
    
    // Load teacher info
    fetch(`../api/teachers.php?id=${teacherId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('teacherInfo').innerHTML = `
                <div class="card">
                    <div class="card-body">
                        <h6>Teacher: ${data.teacher.full_name}</h6>
                        <p class="mb-0">Department: ${data.teacher.department_name || 'Not Assigned'}</p>
                    </div>
                </div>
            `;
        }
    });
    
    // Load assigned courses
    loadAssignedCourses(teacherId);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('manageCoursesModal'));
    modal.show();
}

function loadAssignedCourses(teacherId) {
    fetch(`../api/teachers.php?action=get_courses&teacher_id=${teacherId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = '<h6>Currently Assigned Courses:</h6>';
            if (data.courses.length > 0) {
                html += '<div class="table-responsive"><table class="table table-sm">';
                html += '<thead><tr><th>Course</th><th>Department</th><th>Semester</th><th>Action</th></tr></thead><tbody>';
                data.courses.forEach(course => {
                    html += `
                        <tr>
                            <td>${course.code} - ${course.name}</td>
                            <td>${course.department_name}</td>
                            <td>Sem ${course.semester}</td>
                            <td>
                                <button class="btn btn-sm btn-danger" onclick="removeCourse(${course.id})">
                                    <i class="fas fa-times"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p class="text-muted">No courses assigned yet.</p>';
            }
            document.getElementById('assignedCourses').innerHTML = html;
        }
    });
}

function removeCourse(courseId) {
    if (confirm('Are you sure you want to remove this course from the teacher?')) {
        fetch(`../api/teachers.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'remove_course',
                teacher_id: currentTeacherId,
                course_id: courseId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Course removed successfully');
                loadAssignedCourses(currentTeacherId);
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
}

// Handle add teacher form submission
document.getElementById('addTeacherForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Teacher added successfully');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
});

// Handle manage courses form submission
document.getElementById('manageCoursesForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const selectedCourses = Array.from(document.getElementById('courseSelect').selectedOptions)
        .map(option => option.value);
    
    if (selectedCourses.length === 0) {
        alert('Please select at least one course');
        return;
    }
    
    fetch(`../api/teachers.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'assign_courses',
            teacher_id: currentTeacherId,
            course_ids: selectedCourses
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Courses assigned successfully');
            loadAssignedCourses(currentTeacherId);
            document.getElementById('courseSelect').selectedIndex = -1;
        } else {
            alert('Error: ' + data.message);
        }
    });
});

// Export teachers function
function exportTeachers() {
    const params = new URLSearchParams(window.location.search);
    params.set('format', 'excel');
    params.set('type', 'teachers');
    
    window.location.href = `../api/export.php?${params.toString()}`;
}
</script>

<?php include '../includes/footer.php'; ?>