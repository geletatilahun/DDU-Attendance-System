<?php
session_start();
include('../includes/db_connect.php');

// Check admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$full_name = $_SESSION['full_name'] ?? 'Administrator';
$alert = '';
$error = '';

// Check if course_code exists
function column_exists($conn, $table, $column) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = false;
    if ($res && $row = $res->fetch_assoc()) {
        $exists = intval($row['cnt']) > 0;
    }
    $stmt->close();
    return $exists;
}

$has_course_code = column_exists($conn, 'courses', 'course_code');

// ADD new course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $course_name = trim($_POST['course_name']);
    $course_code = trim($_POST['course_code'] ?? '');

    if ($has_course_code) {
        $stmt = $conn->prepare("INSERT INTO courses (course_name, course_code) VALUES (?, ?)");
        $stmt->bind_param("ss", $course_name, $course_code);
    } else {
        $stmt = $conn->prepare("INSERT INTO courses (course_name) VALUES (?)");
        $stmt->bind_param("s", $course_name);
    }

    if ($stmt->execute()) {
        $alert = "Course added successfully!";
    } else {
        $error = "Error adding course: " . $stmt->error;
    }
    $stmt->close();
}

// EDIT course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $course_id = intval($_POST['course_id']);
    $course_name = trim($_POST['course_name']);
    $course_code = trim($_POST['course_code'] ?? '');

    if ($has_course_code) {
        $stmt = $conn->prepare("UPDATE courses SET course_name = ?, course_code = ? WHERE course_id = ?");
        $stmt->bind_param("ssi", $course_name, $course_code, $course_id);
    } else {
        $stmt = $conn->prepare("UPDATE courses SET course_name = ? WHERE course_id = ?");
        $stmt->bind_param("si", $course_name, $course_id);
    }

    if ($stmt->execute()) {
        $alert = "Course updated successfully!";
    } else {
        $error = "Error updating course: " . $stmt->error;
    }
    $stmt->close();
}

// DELETE course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $course_id = intval($_POST['course_id']);
    $stmt = $conn->prepare("DELETE FROM courses WHERE course_id = ?");
    $stmt->bind_param("i", $course_id);
    if ($stmt->execute()) {
        $alert = "Course deleted successfully!";
    } else {
        $error = "Error deleting course: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch all courses
if ($has_course_code) {
    $sql = "SELECT course_id, course_name, course_code FROM courses ORDER BY course_id DESC";
} else {
    $sql = "SELECT course_id, course_name FROM courses ORDER BY course_id DESC";
}
$courses = $conn->query($sql);
if (!$courses) {
    die("Database query failed: " . $conn->error);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Courses - Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background: #f7f9ff; font-family: 'Poppins', sans-serif; }
.navbar { background: #0d6efd; }
.navbar-brand { color: #fff !important; font-weight: 600; }
.container { padding: 30px 0; }
.card { border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.card-title { font-weight: 600; }
.table thead { background: #0d6efd; color: #fff; }
.table tbody tr:hover { background: rgba(13,110,253,0.1); }
.back-btn { margin-bottom: 15px; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">DDU Attendance - Admin</a>
    <div class="d-flex align-items-center">
      <span class="text-white me-3">Welcome, <?= htmlspecialchars($full_name) ?></span>
      <a href="../logout.php" class="btn btn-danger btn-sm">Logout</a>
    </div>
  </div>
</nav>

<div class="container">
  <a href="dashboard.php" class="btn btn-secondary back-btn"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>

  <div class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="card-title">Manage Courses</h4>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCourseModal">
        <i class="bi bi-plus-circle"></i> Add Course
      </button>
    </div>

    <?php if($alert): ?><div class="alert alert-success"><?= htmlspecialchars($alert) ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <input class="form-control mb-3" id="searchInput" type="text" placeholder="Search courses...">

    <div class="table-responsive">
      <table class="table table-hover" id="coursesTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Course Name</th>
            <?php if ($has_course_code): ?><th>Course Code</th><?php endif; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; while($row = $courses->fetch_assoc()): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($row['course_name']) ?></td>
            <?php if ($has_course_code): ?><td><?= htmlspecialchars($row['course_code']) ?></td><?php endif; ?>
            <td>
              <button class="btn btn-sm btn-warning me-1 editBtn"
                data-id="<?= $row['course_id'] ?>"
                data-name="<?= htmlspecialchars($row['course_name']) ?>"
                <?php if ($has_course_code): ?>data-code="<?= htmlspecialchars($row['course_code']) ?>"<?php endif; ?>>
                <i class="bi bi-pencil"></i>
              </button>
              <button class="btn btn-sm btn-danger deleteBtn"
                data-id="<?= $row['course_id'] ?>"
                data-name="<?= htmlspecialchars($row['course_name']) ?>">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Course Modal -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="add">
      <div class="modal-header">
        <h5 class="modal-title">Add New Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label>Course Name</label>
          <input name="course_name" class="form-control" required>
        </div>
        <?php if ($has_course_code): ?>
        <div class="mb-3">
          <label>Course Code</label>
          <input name="course_code" class="form-control" required>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Add Course</button>
      </div>
    </form>
  </div>
</div> 

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="course_id" id="editCourseId">
      <div class="modal-header">
        <h5 class="modal-title">Edit Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label>Course Name</label>
          <input name="course_name" id="editCourseName" class="form-control" required>
        </div>
        <?php if ($has_course_code): ?>
        <div class="mb-3">
          <label>Course Code</label>
          <input name="course_code" id="editCourseCode" class="form-control" required>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="course_id" id="deleteCourseId">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Delete Course</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete <b id="deleteCourseName"></b>?</p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-danger">Yes, Delete</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const searchInput = document.getElementById('searchInput');
searchInput.addEventListener('keyup', function() {
  const filter = this.value.toLowerCase();
  document.querySelectorAll('#coursesTable tbody tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
  });
});

// Edit button handler
document.querySelectorAll('.editBtn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('editCourseId').value = btn.dataset.id;
    document.getElementById('editCourseName').value = btn.dataset.name;
    const codeField = document.getElementById('editCourseCode');
    if (codeField && btn.dataset.code !== undefined) {
      codeField.value = btn.dataset.code;
    }
    new bootstrap.Modal(document.getElementById('editModal')).show();
  });
});

// Delete button handler
document.querySelectorAll('.deleteBtn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('deleteCourseId').value = btn.dataset.id;
    document.getElementById('deleteCourseName').textContent = btn.dataset.name;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
  });
});
</script>
</body>
</html>
