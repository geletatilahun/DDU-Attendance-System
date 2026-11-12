<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$search = $_GET['search'] ?? '';

$where = "WHERE u.role = 'teacher'";
if ($search !== '') {
    $search = $conn->real_escape_string($search);
    $where .= " AND (
        u.full_name LIKE '%$search%' OR
        u.email LIKE '%$search%' OR
        u.username LIKE '%$search%'
    )";
}

$sql = "
SELECT 
    u.user_id,
    u.full_name,
    u.username,
    u.email,
    u.phone,
    u.is_active,
    u.is_department_head,
    d.department_id,
    d.department_name,
    d.department_head_id
FROM users u
LEFT JOIN departments d 
    ON d.department_id = u.department_id
$where
ORDER BY u.user_id DESC
";

$teachers = $conn->query($sql);
if (!$teachers) {
    die("SQL Error: " . $conn->error);
}

// Fetch all departments for the modal
$departments = $conn->query("SELECT department_id, department_name FROM departments ORDER BY department_name");

include '../includes/header.php';
?>

<div class="container-fluid">
<h3 class="mt-4">Manage Teachers</h3>

<div class="card mb-3">
<div class="card-body d-flex justify-content-between">
<form class="d-flex" method="GET">
<input class="form-control me-2" name="search"
       placeholder="Search teachers"
       value="<?= htmlspecialchars($search) ?>">
<button class="btn btn-primary">Search</button>
</form>

<a href="add_teacher.php" class="btn btn-success">+ Add Teacher</a>
</div>
</div>

<div class="card">
<div class="card-body table-responsive">
<table class="table table-bordered">
<thead class="table-dark">
<tr>
<th>#</th>
<th>Full Name</th>
<th>Username</th>
<th>Email</th>
<th>Department</th>
<th>Status</th>
<th>Role</th>
<th>Actions</th>
</tr>
</thead>
<tbody>

<?php if ($teachers->num_rows > 0): ?>
<?php $i=1; while($row=$teachers->fetch_assoc()): ?>
<tr>
<td><?= $i++ ?></td>
<td><?= $row['full_name'] ?></td>
<td><?= $row['username'] ?></td>
<td><?= $row['email'] ?></td>
<td><?= $row['department_name'] ?: '-' ?></td>
<td>
<span class="badge bg-<?= $row['is_active'] ? 'success' : 'danger' ?>">
<?= $row['is_active'] ? 'Active' : 'Inactive' ?>
</span>
</td>
<td>
<?php if ($row['is_department_head']): ?>
<span class="badge bg-warning text-dark">Department Head</span>
<?php if ($row['department_name']): ?>
<br><small class="text-muted"><?= $row['department_name'] ?></small>
<?php endif; ?>
<?php else: ?>
<span class="badge bg-secondary">Teacher</span>
<?php endif; ?>
</td>
<td>
<button class="btn btn-warning btn-sm"
onclick="editTeacher(<?= $row['user_id'] ?>)">✏</button>

<button class="btn btn-info btn-sm" 
        onclick="setDepartmentHead(<?= $row['user_id'] ?>, '<?= htmlspecialchars(addslashes($row['full_name'])) ?>', <?= $row['department_id'] ?: 'null' ?>, <?= $row['is_department_head'] ? 'true' : 'false' ?>)">
    👑
</button>

<button class="btn btn-danger btn-sm"
onclick="disableTeacher(<?= $row['user_id'] ?>)">🗑</button>
</td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="8" class="text-center">No teachers found</td></tr>
<?php endif; ?>

</tbody>
</table>
</div>
</div>
</div>

<!-- SET DEPARTMENT HEAD MODAL -->
<div class="modal fade" id="departmentHeadModal">
<div class="modal-dialog">
<div class="modal-content">
<form id="departmentHeadForm">
<div class="modal-header">
<h5>Set Department Head</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">
<input type="hidden" id="head_user_id" name="user_id">
<div class="mb-3">
<label class="form-label">Teacher:</label>
<h6 id="head_teacher_name" class="text-primary"></h6>
</div>

<div class="mb-3">
<label for="department_id" class="form-label">Assign as Head of Department:</label>
<select class="form-control" id="department_id" name="department_id" required>
<option value="">-- Select Department --</option>
<?php while($dept = $departments->fetch_assoc()): ?>
<option value="<?= $dept['department_id'] ?>"><?= $dept['department_name'] ?></option>
<?php endwhile; ?>
</select>
</div>

<div class="form-check mb-3">
<input class="form-check-input" type="checkbox" id="remove_head" name="remove_head" value="1">
<label class="form-check-label text-danger" for="remove_head">
Remove as Department Head
</label>
</div>

<div id="currentHeadInfo" class="alert alert-info" style="display:none;">
<strong>Current Department Head:</strong>
<div id="currentHeadDetails"></div>
</div>
</div>

<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
<button type="submit" class="btn btn-primary">Save Changes</button>
</div>
</form>
</div>
</div>
</div>

<script>
function editTeacher(id){
    location = 'edit_teacher.php?id=' + id;
}

function disableTeacher(id){
    if(confirm('Disable teacher?')){
        location = 'user_actions.php?action=disable&id=' + id;
    }
}

function setDepartmentHead(userId, userName, currentDeptId, isCurrentlyHead) {
    // Set basic info
    document.getElementById('head_user_id').value = userId;
    document.getElementById('head_teacher_name').textContent = userName;
    
    // Reset form
    document.getElementById('department_id').value = currentDeptId || '';
    document.getElementById('remove_head').checked = false;
    document.getElementById('currentHeadInfo').style.display = 'none';
    
    // Show current head info if user is already a department head
    if (isCurrentlyHead && currentDeptId) {
        fetchCurrentDepartmentHead(currentDeptId, userId);
    } else {
        document.getElementById('currentHeadInfo').style.display = 'none';
    }
    
    // Show modal
    new bootstrap.Modal(document.getElementById('departmentHeadModal')).show();
}

function fetchCurrentDepartmentHead(deptId, currentUserId) {
    fetch(`../api/departments.php?action=get_head&department_id=${deptId}`)
        .then(r => r.json())
        .then(data => {
            const infoDiv = document.getElementById('currentHeadInfo');
            const detailsDiv = document.getElementById('currentHeadDetails');
            
            if (data.success && data.head) {
                if (data.head.user_id == currentUserId) {
                    detailsDiv.innerHTML = `
                        <div class="mt-2">
                            <span class="badge bg-info">Currently assigned to this teacher</span>
                        </div>
                    `;
                } else {
                    detailsDiv.innerHTML = `
                        <div class="mt-2">
                            <strong>Name:</strong> ${data.head.full_name}<br>
                            <strong>Email:</strong> ${data.head.email}<br>
                            <span class="badge bg-warning">Will be replaced</span>
                        </div>
                    `;
                }
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        })
        .catch(err => {
            console.error('Error fetching department head:', err);
        });
}

// Handle department head form submission
document.getElementById('departmentHeadForm').onsubmit = function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    
    submitBtn.textContent = 'Processing...';
    submitBtn.disabled = true;
    
    fetch('../api/departments.php?action=set_head', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Department head updated successfully!');
            location.reload();
        } else {
            alert(data.message || 'Failed to update department head');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    })
    .catch(err => {
        console.error('Error:', err);
        alert('Network error. Please try again.');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    });
};

// Show/hide department select based on checkbox
document.getElementById('remove_head').addEventListener('change', function() {
    const deptSelect = document.getElementById('department_id');
    deptSelect.disabled = this.checked;
    if (this.checked) {
        deptSelect.value = '';
    }
});

// When department is selected, fetch current head info
document.getElementById('department_id').addEventListener('change', function() {
    const deptId = this.value;
    const userId = document.getElementById('head_user_id').value;
    
    if (deptId) {
        fetchCurrentDepartmentHead(deptId, userId);
    } else {
        document.getElementById('currentHeadInfo').style.display = 'none';
    }
});
</script>

<?php include '../includes/footer.php'; ?>