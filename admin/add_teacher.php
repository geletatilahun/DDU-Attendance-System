<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = $_POST['full_name'];
    $username  = $_POST['username'];
    $email     = $_POST['email'];
    $phone     = $_POST['phone'];
    $department_id = $_POST['department_id'];
    $password  = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO users
        (full_name, username, email, phone, password, role, department_id, is_active)
        VALUES (?, ?, ?, ?, ?, 'teacher', ?, 1)
    ");
    $stmt->bind_param(
        "sssssi",
        $full_name, $username, $email, $phone, $password, $department_id
    );

    if ($stmt->execute()) {
        $_SESSION['flash_message'] = "Teacher added successfully";
        header("Location: manage_teachers.php");
        exit;
    } else {
        $error = "Failed to add teacher";
    }
}

include '../includes/header.php';
?>

<div class="container">
<h4 class="mt-4">Add Teacher</h4>

<form method="POST">
<input class="form-control mb-2" name="full_name" placeholder="Full Name" required>
<input class="form-control mb-2" name="username" placeholder="Username" required>
<input class="form-control mb-2" name="email" type="email" placeholder="Email">
<input class="form-control mb-2" name="phone" placeholder="Phone">

<select name="department_id" class="form-control mb-2" required>
<option value="">Select Department</option>
<?php
$deps = $conn->query("SELECT department_id, department_name FROM departments");
while ($d = $deps->fetch_assoc()):
?>
<option value="<?= $d['department_id'] ?>"><?= $d['department_name'] ?></option>
<?php endwhile; ?>
</select>

<input class="form-control mb-2" name="password" type="password" placeholder="Password" required>

<button class="btn btn-primary">Save</button>
<a href="manage_teachers.php" class="btn btn-secondary">Cancel</a>
</form>
</div>

<?php include '../includes/footer.php'; ?>
