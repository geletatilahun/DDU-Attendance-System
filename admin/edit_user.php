<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_users.php');
    exit;
}

$user_id = (int)$_GET['id'];

// Check if connection is valid
if (!$conn) {
    die("Database connection failed");
}

// Get user details
$sql = "SELECT * FROM users WHERE user_id = ?";
error_log("Edit User SQL: " . $sql . " with ID: " . $user_id);

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}

$bind_result = $stmt->bind_param("i", $user_id);
if (!$bind_result) {
    die("Bind failed: " . htmlspecialchars($stmt->error));
}

$execute_result = $stmt->execute();
if (!$execute_result) {
    die("Execute failed: " . htmlspecialchars($stmt->error));
}

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = 'User not found';
    header('Location: manage_users.php');
    exit;
}

$user = $result->fetch_assoc();

// Now get student details if user is a student
$student_data = [];
if ($user['role'] === 'student') {
    $student_sql = "SELECT * FROM students WHERE user_id = ?";
    $student_stmt = $conn->prepare($student_sql);
    if ($student_stmt) {
        $student_stmt->bind_param("i", $user_id);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        if ($student_result->num_rows > 0) {
            $student_data = $student_result->fetch_assoc();
        }
    }
}

// Fetch departments
$departments_result = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");
if (!$departments_result) {
    error_log("Department query failed: " . $conn->error);
    $departments = [];
} else {
    $departments = [];
    while($dept = $departments_result->fetch_assoc()) {
        $departments[] = $dept;
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Edit User: <?= htmlspecialchars($user['full_name'] ?? 'Unknown') ?></h3>

<!-- Display success/error messages -->
<?php if(isset($_SESSION['success'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?= $_SESSION['success'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= $_SESSION['error'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<div class="card">
<div class="card-body">
<form method="POST" action="user_actions.php" id="editUserForm">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Full Name *</label>
            <input class="form-control" name="full_name" 
                   value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input class="form-control" value="<?= htmlspecialchars($user['username'] ?? '') ?>" readonly>
            <small class="text-muted">Username cannot be changed</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Email</label>
            <input class="form-control" type="email" name="email" 
                   value="<?= htmlspecialchars($user['email'] ?? '') ?>">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label class="form-label">Phone</label>
            <input class="form-control" name="phone" 
                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
        </div>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Role *</label>
    <select name="role" class="form-control" id="roleSelect" required onchange="toggleStudentFields()">
        <option value="">Select Role</option>
        <option value="admin" <?= ($user['role'] ?? '') == 'admin' ? 'selected' : '' ?>>Admin</option>
        <option value="teacher" <?= ($user['role'] ?? '') == 'teacher' ? 'selected' : '' ?>>Teacher</option>
        <option value="student" <?= ($user['role'] ?? '') == 'student' ? 'selected' : '' ?>>Student</option>
        <option value="dean" <?= ($user['role'] ?? '') == 'dean' ? 'selected' : '' ?>>Dean</option>
        <option value="registrar" <?= ($user['role'] ?? '') == 'registrar' ? 'selected' : '' ?>>Registrar</option>
    </select>
</div>

<!-- Student-specific fields -->
<div id="studentFields" style="<?= ($user['role'] ?? '') == 'student' ? 'display: block;' : 'display: none;' ?>">
    <div class="border p-3 mb-3">
        <h5>Student Information</h5>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Student ID *</label>
                    <input class="form-control" name="student_id_no" 
                           value="<?= htmlspecialchars($student_data['student_id_no'] ?? '') ?>"
                           <?= ($user['role'] ?? '') == 'student' ? 'required' : '' ?>>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Department *</label>
                    <select name="department_id" class="form-control" <?= ($user['role'] ?? '') == 'student' ? 'required' : '' ?>>
                        <option value="">Select Department</option>
                        <?php foreach($departments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>" 
                            <?= ($student_data['department_id'] ?? '') == $dept['department_id'] ? 'selected' : '' ?>>
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
                    <input class="form-control" name="program" 
                           value="<?= htmlspecialchars($student_data['program'] ?? '') ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label">Year of Study</label>
                    <select name="year_of_study" class="form-control">
                        <option value="">Select Year</option>
                        <?php for($i = 1; $i <= 4; $i++): ?>
                            <option value="<?= $i ?>" <?= ($student_data['year_of_study'] ?? '') == $i ? 'selected' : '' ?>>
                                Year <?= $i ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label">Semester</label>
                    <select name="semester" class="form-control">
                        <option value="">Select Semester</option>
                        <?php for($i = 1; $i <= 8; $i++): ?>
                            <option value="<?= $i ?>" <?= ($student_data['semester'] ?? '') == $i ? 'selected' : '' ?>>
                                Semester <?= $i ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Academic Year</label>
            <input class="form-control" name="academic_year" 
                   value="<?= htmlspecialchars($student_data['academic_year'] ?? date('Y') . '-' . (date('Y') + 1)) ?>">
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" name="address" rows="2"><?= htmlspecialchars($student_data['address'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Emergency Contact</label>
                    <input class="form-control" name="emergency_contact" 
                           value="<?= htmlspecialchars($student_data['emergency_contact'] ?? '') ?>">
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-control">
                        <option value="">Select Gender</option>
                        <option value="Male" <?= ($student_data['gender'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= ($student_data['gender'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                        <option value="Other" <?= ($student_data['gender'] ?? '') == 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Date of Birth</label>
                    <input class="form-control" type="date" name="date_of_birth" 
                           value="<?= $student_data['date_of_birth'] ?? '' ?>">
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Change Password (leave blank to keep current)</label>
    <div class="row">
        <div class="col-md-6">
            <input class="form-control" type="password" name="password" id="new_password" placeholder="New Password">
        </div>
        <div class="col-md-6">
            <input class="form-control" type="password" name="confirm_password" id="confirm_new_password" placeholder="Confirm New Password">
        </div>
    </div>
</div>

<div class="mb-3">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
               <?= ($user['is_active'] ?? 0) ? 'checked' : '' ?>>
        <label class="form-check-label" for="is_active">Active Account</label>
    </div>
</div>

<div class="d-flex justify-content-between">
    <a href="manage_users.php" class="btn btn-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary">Update User</button>
</div>

</form>
</div>
</div>
</div>

<script>
function toggleStudentFields() {
    const studentFields = document.getElementById('studentFields');
    const roleSelect = document.getElementById('roleSelect');
    const role = roleSelect.value;
    
    if (role === 'student') {
        studentFields.style.display = 'block';
        
        // Make student-specific fields required
        const studentInputs = studentFields.querySelectorAll('input, select');
        studentInputs.forEach(field => {
            if (field.name && (field.name.includes('student_id_no') || field.name.includes('department_id'))) {
                field.required = true;
            }
        });
    } else {
        studentFields.style.display = 'none';
        
        // Remove required attribute
        const studentInputs = studentFields.querySelectorAll('input, select');
        studentInputs.forEach(field => {
            field.required = false;
        });
    }
}

// Form validation
document.getElementById('editUserForm').addEventListener('submit', function(e) {
    const password = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_new_password').value;
    const role = document.getElementById('roleSelect').value;
    
    // Check if passwords are provided, they must match
    if (password !== '' && password !== confirmPassword) {
        e.preventDefault();
        alert('Passwords do not match!');
        document.getElementById('new_password').focus();
        return false;
    }
    
    // Check password strength if provided
    if (password !== '' && password.length < 8) {
        e.preventDefault();
        alert('Password must be at least 8 characters long!');
        document.getElementById('new_password').focus();
        return false;
    }
    
    // For student role, check required fields
    if (role === 'student') {
        const studentIdNo = document.querySelector('input[name="student_id_no"]').value;
        const departmentId = document.querySelector('select[name="department_id"]').value;
        
        if (!studentIdNo.trim()) {
            e.preventDefault();
            alert('Student ID Number is required!');
            document.querySelector('input[name="student_id_no"]').focus();
            return false;
        }
        
        if (!departmentId) {
            e.preventDefault();
            alert('Department is required for students!');
            document.querySelector('select[name="department_id"]').focus();
            return false;
        }
    }
    
    return true;
});

// Initialize student fields on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleStudentFields();
});
</script>

<?php include '../includes/footer.php'; ?>