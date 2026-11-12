<?php
session_start();
require_once '../includes/db_connect.php';

$id = (int)($_GET['id'] ?? 0);

$student = $conn->query("SELECT * FROM students WHERE student_id=$id")->fetch_assoc();
if (!$student) {
    header("Location: manage_students.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $stmt = $conn->prepare("
        UPDATE students SET
        student_id_no=?, full_name=?, email=?, phone=?,
        department_id=?, program=?, year_of_study=?, semester=?
        WHERE student_id=?
    ");

    $stmt->bind_param(
        "ssssissii",
        $_POST['student_id_no'],
        $_POST['full_name'],
        $_POST['email'],
        $_POST['phone'],
        $_POST['department_id'],
        $_POST['program'],
        $_POST['year_of_study'],
        $_POST['semester'],
        $id
    );

    $stmt->execute();
    $_SESSION['flash_message']="Student updated successfully";
    header("Location: manage_students.php");
    exit;
}

include '../includes/header.php';
?>

<div class="container">
<h3>Edit Student</h3>

<form method="POST">
<input class="form-control mb-2" name="student_id_no" value="<?= $student['student_id_no'] ?>" required>
<input class="form-control mb-2" name="full_name" value="<?= $student['full_name'] ?>" required>
<input class="form-control mb-2" name="email" value="<?= $student['email'] ?>" required>
<input class="form-control mb-2" name="phone" value="<?= $student['phone'] ?>">

<select name="department_id" class="form-control mb-2" required>
<?php
$deps = $conn->query("SELECT * FROM departments");
while($d=$deps->fetch_assoc()):
?>
<option value="<?= $d['department_id'] ?>"
<?= $student['department_id']==$d['department_id']?'selected':'' ?>>
<?= $d['department_name'] ?>
</option>
<?php endwhile; ?>
</select>

<input class="form-control mb-2" name="program" value="<?= $student['program'] ?>">
<input class="form-control mb-2" name="year_of_study" value="<?= $student['year_of_study'] ?>">
<input class="form-control mb-2" name="semester" value="<?= $student['semester'] ?>">

<button class="btn btn-primary">Save Changes</button>
<a href="manage_students.php" class="btn btn-secondary">Cancel</a>
</form>
</div>

<?php include '../includes/footer.php'; ?>
