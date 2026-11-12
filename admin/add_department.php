<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $department_name = trim($_POST['department_name'] ?? '');

    if ($department_name === '') {
        $error = 'Department name is required';
    } else {

        // Prevent duplicate department names
        $check = $conn->prepare(
            "SELECT department_id FROM departments WHERE department_name = ?"
        );
        $check->bind_param("s", $department_name);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = 'Department already exists';
        } else {

            $stmt = $conn->prepare(
                "INSERT INTO departments (department_name) VALUES (?)"
            );

            if (!$stmt) {
                die("SQL Error: " . $conn->error);
            }

            $stmt->bind_param("s", $department_name);

            if ($stmt->execute()) {
                $success = 'Department added successfully';
            } else {
                $error = 'Failed to add department';
            }
        }
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Add Department</h3>

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
       value="<?= htmlspecialchars($_POST['department_name'] ?? '') ?>">
</div>

<button class="btn btn-success">Save</button>
<a href="manage_departments.php" class="btn btn-secondary">Back</a>
</form>

</div>
</div>
</div>

<?php include '../includes/footer.php'; ?>
