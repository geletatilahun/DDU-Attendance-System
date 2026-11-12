<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Check if id parameter is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_users.php');
    exit;
}

// Check if id parameter is provided (student_id)
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $student_id = (int)$_GET['id'];
    $sql = "SELECT s.*, d.department_name, u.username, u.is_active, u.role
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN users u ON s.user_id = u.user_id
            WHERE s.student_id = ?";
    $param_type = "i";
    $param_value = $student_id;
} 
// Check if user_id parameter is provided
elseif (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $user_id = (int)$_GET['user_id'];
    $sql = "SELECT s.*, d.department_name, u.username, u.is_active, u.role
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.department_id
            LEFT JOIN users u ON s.user_id = u.user_id
            WHERE s.user_id = ?";
    $param_type = "i";
    $param_value = $user_id;
} 
else {
    $_SESSION['error'] = 'Invalid parameters';
    header('Location: manage_users.php');
    exit;
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_type, $param_value);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = 'Student not found';
    header('Location: manage_users.php');
    exit;
}

$student = $result->fetch_assoc();

$student_id = (int)$_GET['id'];

// Get student details
$sql = "SELECT s.*, d.department_name, u.username, u.is_active, u.role
        FROM students s
        LEFT JOIN departments d ON s.department_id = d.department_id
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE s.student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = 'Student not found';
    header('Location: manage_users.php');
    exit;
}

$student = $result->fetch_assoc();

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Student Details: <?= htmlspecialchars($student['full_name']) ?></h3>

<div class="row">
<div class="col-md-8">
    <div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Personal Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Student ID:</th>
                        <td><?= htmlspecialchars($student['student_id_no']) ?></td>
                    </tr>
                    <tr>
                        <th>Full Name:</th>
                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                    </tr>
                    <tr>
                        <th>Email:</th>
                        <td><?= htmlspecialchars($student['email'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Phone:</th>
                        <td><?= htmlspecialchars($student['phone'] ?: 'N/A') ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Username:</th>
                        <td><?= htmlspecialchars($student['username'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <span class="badge bg-<?= $student['is_active'] ? 'success' : 'danger' ?>">
                                <?= $student['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Role:</th>
                        <td>
                            <span class="badge bg-info"><?= ucfirst($student['role']) ?></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    </div>

    <div class="card mt-3">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Academic Information</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Department:</th>
                        <td><?= htmlspecialchars($student['department_name'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Program:</th>
                        <td><?= htmlspecialchars($student['program'] ?: 'N/A') ?></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-borderless">
                    <tr>
                        <th width="40%">Year of Study:</th>
                        <td><?= htmlspecialchars($student['year_of_study'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Semester:</th>
                        <td><?= htmlspecialchars($student['semester'] ?: 'N/A') ?></td>
                    </tr>
                    <tr>
                        <th>Academic Year:</th>
                        <td><?= htmlspecialchars($student['academic_year'] ?: 'N/A') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    </div>
</div>

<div class="col-md-4">
    <div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Quick Actions</h5>
    </div>
    <div class="card-body">
        <div class="d-grid gap-2">
            <a href="edit_user.php?id=<?= $student['user_id'] ?>" class="btn btn-warning">
                <i class="fas fa-edit"></i> Edit Student
            </a>
            <button onclick="resetPassword(<?= $student['user_id'] ?>)" class="btn btn-info">
                <i class="fas fa-key"></i> Reset Password
            </button>
            <?php if ($student['is_active']): ?>
                <button onclick="disableUser(<?= $student['user_id'] ?>)" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Disable Account
                </button>
            <?php else: ?>
                <button onclick="enableUser(<?= $student['user_id'] ?>)" class="btn btn-success">
                    <i class="fas fa-check"></i> Enable Account
                </button>
            <?php endif; ?>
            <a href="manage_users.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Users
            </a>
        </div>
    </div>
    </div>

    <div class="card mt-3">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Additional Information</h5>
    </div>
    <div class="card-body">
        <table class="table table-borderless">
            <tr>
                <th width="40%">Address:</th>
                <td><?= htmlspecialchars($student['address'] ?: 'N/A') ?></td>
            </tr>
            <tr>
                <th>Emergency Contact:</th>
                <td><?= htmlspecialchars($student['emergency_contact'] ?: 'N/A') ?></td>
            </tr>
            <tr>
                <th>Gender:</th>
                <td><?= htmlspecialchars($student['gender'] ?: 'N/A') ?></td>
            </tr>
            <tr>
                <th>Date of Birth:</th>
                <td><?= $student['date_of_birth'] ? date('d/m/Y', strtotime($student['date_of_birth'])) : 'N/A' ?></td>
            </tr>
            <tr>
                <th>Created:</th>
                <td><?= date('d/m/Y H:i', strtotime($student['created_at'])) ?></td>
            </tr>
        </table>
    </div>
    </div>
</div>
</div>
</div>

<script>
function resetPassword(id){
    if(confirm('Reset password for this user? The new password will be shown on screen.')){
        window.location.href = 'user_actions.php?action=reset_password&id=' + id;
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
</script>

<?php include '../includes/footer.php'; ?>