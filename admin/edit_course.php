<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: manage_courses.php');
    exit;
}

$course = $conn->query("SELECT * FROM courses WHERE course_id = $id")->fetch_assoc();
if (!$course) {
    die("Course not found");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $stmt = $conn->prepare("
        UPDATE courses SET
            course_code = ?,
            course_name = ?,
            credit_hours = ?,
            year_of_study = ?,
            semester = ?,
            department_id = ?
        WHERE course_id = ?
    ");

    $stmt->bind_param(
        "ssiiiii",
        $_POST['course_code'],
        $_POST['course_name'],
        $_POST['credit_hours'],
        $_POST['year_of_study'],
        $_POST['semester'],
        $_POST['department_id'],
        $id
    );

    $stmt->execute();
    header("Location: manage_courses.php");
    exit;
}

include '../includes/header.php';
?>

<div class="container">
<h4 class="mt-4">Edit Course</h4>

<form method="POST">
<input class="form-control mb-2" name="course_code"
       value="<?= htmlspecialchars($course['course_code']) ?>" required>

<input class="form-control mb-2" name="course_name"
       value="<?= htmlspecialchars($course['course_name']) ?>" required>

<input class="form-control mb-2" type="number" name="credit_hours"
       value="<?= $course['credit_hours'] ?>" required>

<input class="form-control mb-2" type="number" name="year_of_study"
       value="<?= $course['year_of_study'] ?>" required>

<input class="form-control mb-2" type="number" name="semester"
       value="<?= $course['semester'] ?>" required>

<select name="department_id" class="form-control mb-3" required>
<?php
$deps = $conn->query("SELECT department_id, department_name FROM departments");
while ($d = $deps->fetch_assoc()):
?>
<option value="<?= $d['department_id'] ?>"
<?= $d['department_id'] == $course['department_id'] ? 'selected' : '' ?>>
<?= htmlspecialchars($d['department_name']) ?>
</option>
<?php endwhile; ?>
</select>

<button class="btn btn-primary">Update</button>
<a href="manage_courses.php" class="btn btn-secondary">Cancel</a>
</form>
</div>

<?php include '../includes/footer.php'; ?>
