<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$search = $_GET['search'] ?? '';

$where = '';
if ($search !== '') {
    $search = $conn->real_escape_string($search);
    $where = "WHERE 
        u.full_name LIKE '%$search%' OR
        u.username LIKE '%$search%' OR
        u.email LIKE '%$search%' OR
        u.role LIKE '%$search%' OR
        s.student_id_no LIKE '%$search%' OR
        d.department_name LIKE '%$search%'";
}

$sql = "
SELECT 
    u.user_id,
    u.full_name,
    u.username,
    u.email,
    u.phone,
    u.role,
    u.is_active,
    s.student_id,
    s.student_id_no,
    d.department_id,
    d.department_name
FROM users u
LEFT JOIN students s ON u.user_id = s.user_id
LEFT JOIN departments d ON s.department_id = d.department_id
$where
ORDER BY u.user_id DESC
";

$users = $conn->query($sql);

// Fetch departments for student form
$departments_result = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
$departments = [];
while($dept = $departments_result->fetch_assoc()) {
    $departments[] = $dept;
}

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Manage Users</h3>

<div class="card mb-3">
<div class="card-body d-flex justify-content-between">

<form class="d-flex" method="GET">
<input class="form-control me-2" name="search"
       placeholder="Search users..."
       value="<?= htmlspecialchars($search) ?>">
<button class="btn btn-primary">Search</button>
<a href="manage_users.php" class="btn btn-secondary ms-2">Reset</a>
</form>

<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addUserModal">
+ Add User
</button>

</div>
</div>

<div class="card">
<div class="card-body table-responsive">
<table class="table table-bordered table-hover">
<thead class="table-dark">
<tr>
<th>#</th>
<th>Full Name</th>
<th>Username</th>
<th>Email</th>
<th>Phone</th>
<th>Role</th>
<th>ID</th>
<th>Department</th>
<th>Status</th>
<th>Actions</th>
</tr>
</thead>
<tbody>

<?php if($users->num_rows > 0): ?>
<?php $i=1; while($row = $users->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['full_name']) ?></td>
<td><?= htmlspecialchars($row['username']) ?></td>
<td><?= htmlspecialchars($row['email'] ?: '-') ?></td>
<td><?= htmlspecialchars($row['phone'] ?: '-') ?></td>
<td>
    <?php 
    $role_colors = [
        'admin' => 'danger',
        'teacher' => 'warning',
        'student' => 'success',
        'dean' => 'info',
        'registrar' => 'primary'
    ];
    $color = $role_colors[$row['role']] ?? 'secondary';
    ?>
    <span class="badge bg-<?= $color ?>">
        <?= ucfirst($row['role']) ?>
    </span>
</td>
<td>
    <?php if ($row['role'] === 'student' && !empty($row['student_id_no'])): ?>
        <?= htmlspecialchars($row['student_id_no']) ?>
    <?php else: ?>
        -
    <?php endif; ?>
</td>
<td>
    <?php if ($row['role'] === 'student' && !empty($row['department_name'])): ?>
        <?= htmlspecialchars($row['department_name']) ?>
    <?php else: ?>
        -
    <?php endif; ?>
</td>
<td>
<span class="badge bg-<?= $row['is_active'] ? 'success' : 'danger' ?>">
<?= $row['is_active'] ? 'Active' : 'Disabled' ?>
</span>
</td>
<td>
<div class="btn-group btn-group-sm" role="group">
    <button class="btn btn-outline-warning"
            onclick="editUser(<?= $row['user_id'] ?>)"
            title="Edit User">
        <i class="fas fa-edit"></i>
    </button>

    <button class="btn btn-outline-info"
            onclick="resetPassword(<?= $row['user_id'] ?>)"
            title="Reset Password">
        <i class="fas fa-key"></i>
    </button>

    <?php if ($row['role'] === 'student'): ?>
    <button class="btn btn-outline-primary"
            onclick="viewStudent(<?= $row['user_id'] ?>, <?= $row['student_id'] ?? 'null' ?>)"
            title="View Student Details">
        <i class="fas fa-user-graduate"></i>
    </button>
    <?php endif; ?>

    <button class="btn btn-outline-<?= $row['is_active'] ? 'danger' : 'success' ?>"
            onclick="<?= $row['is_active'] ? 'disableUser' : 'enableUser' ?>(<?= $row['user_id'] ?>)"
            title="<?= $row['is_active'] ? 'Disable User' : 'Enable User' ?>">
        <i class="fas <?= $row['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i>
    </button>
</div>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="10" class="text-center text-muted py-4">
    <i class="fas fa-users fa-2x mb-2 d-block"></i>
    No users found
</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>
</div>

<!-- ADD USER MODAL -->
<div class="modal fade" id="addUserModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
<form method="POST" action="user_actions.php" id="userForm">
<input type="hidden" name="action" value="add">

<div class="modal-header bg-primary text-white">
<h5 class="modal-title">Add User</h5>
<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input class="form-control" name="full_name" placeholder="Full Name" required>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Username *</label>
            <input class="form-control" name="username" placeholder="Username" required>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Password *</label>
            <div class="input-group">
                <input class="form-control" type="password" name="password" id="password" placeholder="Password" required>
                <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('password')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
            <small class="text-muted">Minimum 8 characters</small>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Confirm Password *</label>
            <div class="input-group">
                <input class="form-control" type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password" required>
                <button type="button" class="btn btn-outline-secondary" onclick="togglePassword('confirm_password')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Role *</label>
    <select name="role" class="form-control" id="roleSelect" required onchange="toggleStudentFields()">
        <option value="">Select Role</option>
        <option value="admin">Admin</option>
        <option value="teacher">Teacher</option>
        <option value="student">Student</option>
        <option value="dean">Dean</option>
        <option value="registrar">Registrar</option>
    </select>
</div>

<!-- Student-specific fields (hidden by default) -->
<div id="studentFields" style="display: none;">
    <div class="border-top pt-3 mb-3">
        <h6>Student Information</h6>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Student ID Number *</label>
                    <input class="form-control" name="student_id_no" id="student_id_no" placeholder="STU-2024-001">
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Department *</label>
                    <select name="department_id" id="department_id" class="form-control">
                        <option value="">Select Department</option>
                        <?php foreach($departments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>">
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label">Program</label>
                    <input class="form-control" name="program" placeholder="e.g., B.Tech Computer Science">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label">Year of Study *</label>
                    <select name="year_of_study" class="form-control">
                        <option value="">Select Year</option>
                        <?php for($i = 1; $i <= 4; $i++): ?>
                            <option value="<?= $i ?>">Year <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label">Semester *</label>
                    <select name="semester" class="form-control">
                        <option value="">Select Semester</option>
                        <?php for($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>">Semester <?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Academic Year *</label>
            <?php
            $currentYear = date('Y');
            $defaultYear = $currentYear . '-' . ($currentYear + 1);
            ?>
            <input class="form-control" name="academic_year" value="<?= $defaultYear ?>" placeholder="YYYY-YYYY">
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2" placeholder="Full address"></textarea>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Emergency Contact</label>
                    <input class="form-control" name="emergency_contact" placeholder="+91 9876543210">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Common fields for all users -->
<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" placeholder="Email">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" placeholder="Phone">
        </div>
    </div>
</div>

<div class="mb-3">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
        <label class="form-check-label" for="is_active">Active Account</label>
    </div>
</div>
</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Save User</button>
</div>

</form>
</div>
</div>
</div>

<script>

function editUser(id){
    window.location.href = 'edit_user.php?id=' + id;
}

function viewStudent(userId, studentId){
    console.log("View Student called with User ID:", userId, "Student ID:", studentId);
    
    // If we have studentId directly, use it
    if (studentId && studentId !== 'null' && studentId !== 'undefined') {
        window.location.href = 'view_student.php?id=' + studentId;
        return;
    }
    
    // If we don't have studentId, try to get it from the user_id
    // First check if we can redirect directly with user_id
    if (userId) {
        // Try both approaches
        window.location.href = 'view_student.php?user_id=' + userId;
    } else {
        alert('Student information not available');
    }
}

// Alternative simplified version (if above doesn't work):
function viewStudentSimple(userId){
    if (userId) {
        window.location.href = 'view_student.php?user_id=' + userId;
    } else {
        alert('Student ID not found');
    }
}

function resetPassword(id){
    if(confirm('Reset password for this user? The new password will be sent to their email.')){
        window.location.href = 'reset_password.php?id=' + id;
    }
}

function disableUser(id){
    if(confirm('Are you sure you want to disable this user? They will not be able to login.')){
        window.location.href = 'user_actions.php?action=disable&id=' + id;
    }
}

function enableUser(id){
    if(confirm('Are you sure you want to enable this user?')){
        window.location.href = 'user_actions.php?action=enable&id=' + id;
    }
}

function resetPassword(id){
    if(confirm('Reset password for this user? The new password will be shown on screen.')){
        window.location.href = 'user_actions.php?action=reset_password&id=' + id;
    }
}

function deleteUser(id){
    if(confirm('Are you sure you want to delete this user? This action cannot be undone.')){
        window.location.href = 'user_actions.php?action=delete&id=' + id;
    }
}

// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
    field.setAttribute('type', type);
}

// Show/hide student fields based on role selection
function toggleStudentFields() {
    const studentFields = document.getElementById('studentFields');
    const roleSelect = document.getElementById('roleSelect');
    const role = roleSelect.value;
    
    if (role === 'student') {
        studentFields.style.display = 'block';
        
        // Make student-specific fields required
        const requiredFields = [
            'student_id_no',
            'department_id',
            'year_of_study',
            'semester',
            'academic_year'
        ];
        
        requiredFields.forEach(fieldName => {
            const field = document.getElementsByName(fieldName)[0];
            if (field) field.required = true;
        });
    } else {
        studentFields.style.display = 'none';
        
        // Remove required attribute from student fields
        const studentFormElements = document.querySelectorAll('#studentFields input, #studentFields select');
        studentFormElements.forEach(field => {
            field.required = false;
        });
    }
}

// Form validation
document.getElementById('userForm').addEventListener('submit', function(e) {
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    const role = document.getElementById('roleSelect').value;
    
    // Check password match
    if (password !== confirmPassword) {
        e.preventDefault();
        alert('Passwords do not match!');
        document.getElementById('password').focus();
        return false;
    }
    
    // Check password strength (minimum 8 characters)
    if (password.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters long!');
        document.getElementById('password').focus();
        return false;
    }
    
    // For student role, check required fields
    if (role === 'student') {
        const studentIdNo = document.getElementById('student_id_no').value;
        const departmentId = document.getElementById('department_id').value;
        
        if (!studentIdNo.trim()) {
            e.preventDefault();
            alert('Student ID Number is required!');
            document.getElementById('student_id_no').focus();
            return false;
        }
        
        if (!departmentId) {
            e.preventDefault();
            alert('Department is required for students!');
            document.getElementById('department_id').focus();
            return false;
        }
    }
    
    return true;
});

// Auto-generate username from full name
document.querySelector('input[name="full_name"]').addEventListener('blur', function() {
    const usernameField = document.querySelector('input[name="username"]');
    const emailField = document.querySelector('input[name="email"]');
    
    // Only auto-generate if username is empty
    if (usernameField && !usernameField.value.trim()) {
        // Generate username (lowercase, remove spaces, special chars)
        const username = this.value.toLowerCase()
            .replace(/\s+/g, '.')
            .replace(/[^a-z0-9.]/g, '');
        usernameField.value = username;
    }
    
    // Auto-generate email if empty
    if (emailField && !emailField.value.trim() && this.value.trim()) {
        const email = this.value.toLowerCase()
            .replace(/\s+/g, '.')
            .replace(/[^a-z0-9.]/g, '') + '@ddu.ac.in';
        emailField.value = email;
    }
});

// Auto-generate student ID from name and year
document.getElementById('student_id_no')?.addEventListener('focus', function() {
    if (!this.value.trim()) {
        const fullName = document.querySelector('input[name="full_name"]').value;
        const currentYear = new Date().getFullYear().toString().slice(-2);
        
        if (fullName.trim()) {
            // Extract initials from full name
            const nameParts = fullName.trim().split(' ');
            let initials = '';
            if (nameParts.length >= 2) {
                // First letter of first name + first letter of last name
                initials = nameParts[0].charAt(0) + nameParts[nameParts.length - 1].charAt(0);
            } else if (nameParts.length === 1) {
                // First two letters if only one name
                initials = nameParts[0].substring(0, 2);
            }
            
            if (initials) {
                // Generate a simple student ID
                const randomNum = Math.floor(100 + Math.random() * 900); // 100-999
                this.value = `STU${currentYear}${initials.toUpperCase()}${randomNum}`;
            }
        }
    }
});

// Reset form when modal is closed
document.getElementById('addUserModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('userForm').reset();
    document.getElementById('studentFields').style.display = 'none';
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include '../includes/footer.php'; ?>