<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$search = $_GET['search'] ?? '';

$where = "WHERE 1=1";
if ($search !== '') {
    $search = $conn->real_escape_string($search);
    $where .= " AND department_name LIKE '%$search%'";
}

$sql = "
SELECT 
    department_id,
    department_name
FROM departments
$where
ORDER BY department_id DESC
";

$departments = $conn->query($sql);
if (!$departments) {
    die("SQL Error: " . $conn->error);
}

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Manage Departments</h3>

<div class="card mb-3">
<div class="card-body d-flex justify-content-between">
<form class="d-flex" method="GET">
<input class="form-control me-2"
       name="search"
       placeholder="Search departments"
       value="<?= htmlspecialchars($search) ?>">
<button class="btn btn-primary">Search</button>
</form>

<a href="add_department.php" class="btn btn-success">+ Add Department</a>
</div>
</div>

<div class="card">
<div class="card-body table-responsive">
<table class="table table-bordered">
<thead class="table-dark">
<tr>
<th>#</th>
<th>Department Name</th>
<th>Actions</th>
</tr>
</thead>
<tbody>

<?php if ($departments->num_rows > 0): ?>
<?php $i = 1; while ($row = $departments->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= htmlspecialchars($row['department_name']) ?></td>
<td>
<button class="btn btn-warning btn-sm"
onclick="editDepartment(<?= $row['department_id'] ?>)">✏</button>

<script>
function deleteDepartment(id){
    if(confirm('Delete this department?')){
        location = 'department_actions.php?action=delete&id=' + id;
    }
}
</script>

</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr>
<td colspan="3" class="text-center">No departments found</td>
</tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>
</div>

<script>
function editDepartment(id){
    location = 'edit_department.php?id=' + id;
}

function deleteDepartment(id){
    if(confirm('Delete department?')){
        location = 'department_actions.php?action=delete&id=' + id;
    }
}
</script>

<?php include '../includes/footer.php'; ?>
