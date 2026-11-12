<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) die("Invalid request");

$teacher = $conn->query("
    SELECT * FROM users
    WHERE user_id = $id AND role='teacher'
")->fetch_assoc();

if (!$teacher) die("Teacher not found");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $department_id = $_POST['department_id'];

    $stmt = $conn->prepare("
        UPDATE users SET
        full_name=?, email=?, phone=?, department_id=?
        WHERE user_id=?
    ");
    $stmt->bind_param(
        "sssii",
        $full_name, $email, $phone, $department_id, $id
    );

    if ($stmt->execute()) {
        $_SESSION['flash_message'] = "Teacher updated successfully";
        header("Location: manage_teachers.php");
        exit;
    }
}

include '../includes/header.php';
?>

<div class="container">
<h4 class="mt-4">Edit Teacher</h4>

<form method="POST">
<input class="form-control mb-2" name="full_name"
value="<?= htmlspecialchars($teacher['full_name']) ?>" required>

<input class="form-control mb-2" name="email"
value="<?= htmlspecialchars($teacher['email']) ?>">

<input class="form-control mb-2" name="phone"
value="<?= htmlspecialchars($teacher['phone']) ?>">

<select name="department_id" class="form-control mb-2" required>
<?php
$deps = $conn->query("SELECT department_id, department_name FROM departments");
while ($d = $deps->fetch_assoc()):
?>
<option value="<?= $d['department_id'] ?>"
<?= $d['department_id'] == $teacher['department_id'] ? 'selected' : '' ?>>
<?= $d['department_name'] ?>
</option>
<?php endwhile; ?>
</select>

<button class="btn btn-primary">Update</button>
<a href="manage_teachers.php" class="btn btn-secondary">Cancel</a>
</form>
</div>

<?php include '../includes/footer.php'; ?>
