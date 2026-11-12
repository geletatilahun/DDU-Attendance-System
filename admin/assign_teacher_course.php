<?php
session_start();
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !is_admin()) {
    header('Location: ../login.php');
    exit;
}

/* FETCH TEACHERS */
$teachers = $conn->query("
    SELECT user_id, full_name
    FROM users
    WHERE role='teacher' AND is_active=1
");

/* FETCH COURSES */
$courses = $conn->query("
    SELECT course_id, course_name
    FROM courses
    WHERE is_active=1
");

/* FETCH ASSIGNMENTS */
$assigned = $conn->query("
    SELECT 
        u.full_name,
        c.course_name
    FROM teacher_courses tc
    JOIN users u ON u.user_id = tc.teacher_id
    JOIN courses c ON c.course_id = tc.course_id
");

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Assign Teachers to Courses</h3>

<div class="card mb-3">
<div class="card-body">
<form method="POST" action="teacher_course_actions.php" class="row g-2">

<div class="col-md-5">
<select name="teacher_id" class="form-control" required>
<option value="">Select Teacher</option>
<?php while($t=$teachers->fetch_assoc()): ?>
<option value="<?= $t['user_id'] ?>"><?= $t['full_name'] ?></option>
<?php endwhile; ?>
</select>
</div>

<div class="col-md-5">
<select name="course_id" class="form-control" required>
<option value="">Select Course</option>
<?php while($c=$courses->fetch_assoc()): ?>
<option value="<?= $c['course_id'] ?>"><?= $c['course_name'] ?></option>
<?php endwhile; ?>
</select>
</div>

<div class="col-md-2">
<button class="btn btn-success w-100" name="action" value="assign">
Assign
</button>
</div>

</form>
</div>
</div>

<div class="card">
<div class="card-body table-responsive">
<table class="table table-bordered">
<thead class="table-dark">
<tr>
<th>Teacher</th>
<th>Course</th>
</tr>
</thead>
<tbody>
<?php while($r=$assigned->fetch_assoc()): ?>
<tr>
<td><?= $r['full_name'] ?></td>
<td><?= $r['course_name'] ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>
</div>
</div>

<?php include '../includes/footer.php'; ?>
