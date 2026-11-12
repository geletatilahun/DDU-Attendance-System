<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

/**
 * Validate department id
 */
$department_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($department_id <= 0) {
    die('Invalid department ID');
}

$error = '';
$success = '';

/**
 * Handle DELETE
 */
if (isset($_GET['action']) && $_GET['action'] === 'delete') {

    // Prevent delete if department is in use
    $check = $conn->prepare(
        "SELECT COUNT(*) 
         FROM users 
         WHERE department_id = ?"
    );
    $check->bind_param("i", $department_id);
    $check->execute();
    $check->bind_result($used);
    $check->fetch();
    $check->close();

    if ($used > 0) {
        $error = 'Cannot delete department: it is assigned to users.';
    } else {
        $del = $conn->prepare(
            "DELETE FROM departments WHERE department_id = ?"
        );
        $del->bind_param("i", $department_id);

        if ($del->execute()) {
            header('Location: manage_departments.php?deleted=1');
            exit;
        } else {
            $error = 'Failed to delete department';
        }
    }
}

/**
 * Handle UPDATE
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $department_name = trim($_POST['department_name'] ?? '');

    if ($department_name === '') {
        $error = 'Department name is required';
    } else {

        // Prevent duplicate name
        $check = $conn->prepare(
            "SELECT department_id 
             FROM departments 
             WHERE department_name = ? 
             AND department_id != ?"
        );
        $check->bind_param("si", $department_name, $department_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'Department name already exists';
        } else {

            $stmt = $conn->prepare(
                "UPDATE departments 
                 SET department_name = ? 
                 WHERE department_id = ?"
            );
            $stmt->bind_param("si", $department_name, $department_id);

            if ($stmt->execute()) {
                $success = 'Department updated successfully';
            } else {
                $error = 'Update failed';
            }
        }
    }
}

/**
 * Fetch department info
 */
$stmt = $conn->prepare(
    "SELECT department_name 
     FROM departments 
     WHERE department_id = ?"
);
$stmt->bind_param("i", $department_id);
$stmt->execute();
$result = $stmt->get_result();
$department = $result->fetch_assoc();

if (!$department) {
    die('Department not found');
}

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Edit Department</h3>

<div class="card col-md-6">
<div class="card-body">

<?php if ($error): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<form method="POST">
<div class="mb-3">
<label class="form-label">Department Name</label>
<input type="text"
       name="department_name"
       class="form-control"
       required
       value="<?= htmlspecialchars($department['department_name']) ?>">
</div>

<button class="btn btn-primary">Update</button>
<a href="manage_departments.php" class="btn btn-secondary">Back</a>

<button type="button"
        class="btn btn-danger float-end"
        onclick="confirmDelete()">
Delete
</button>
</form>

</div>
</div>
</div>

<script>
function confirmDelete() {
    if (confirm('Are you sure you want to delete this department?')) {
        window.location = 'edit_department.php?id=<?= $department_id ?>&action=delete';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
