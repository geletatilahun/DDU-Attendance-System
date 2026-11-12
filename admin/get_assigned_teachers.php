<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized");
}

$course_id = (int)($_GET['course_id'] ?? 0);

// Debug: Check if course_id is received
error_log("Course ID received: " . $course_id);

if ($course_id === 0) {
    echo '<div class="alert alert-warning">No course selected.</div>';
    exit;
}

// First, let's check if the course exists
$course_check = $conn->query("SELECT course_id FROM courses WHERE course_id = $course_id");
if ($course_check->num_rows === 0) {
    echo '<div class="alert alert-danger">Course not found.</div>';
    exit;
}

// Check if course_teachers table exists
$table_check = $conn->query("SHOW TABLES LIKE 'course_teachers'");
if ($table_check->num_rows === 0) {
    echo '<div class="alert alert-danger">Course teachers table does not exist.</div>';
    echo '<script>console.error("course_teachers table not found in database");</script>';
    exit;
}

$sql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.email,
        ct.role,
        ct.assigned_date,
        ct.start_date,
        ct.end_date
    FROM course_teachers ct
    JOIN users u ON ct.teacher_id = u.user_id
    WHERE ct.course_id = ?
    ORDER BY ct.role = 'primary' DESC, u.full_name
";

// Debug: Show the SQL query
error_log("SQL Query: " . $sql);

$stmt = $conn->prepare($sql);
if (!$stmt) {
    // Show the error
    echo '<div class="alert alert-danger">';
    echo 'Database Error: ' . htmlspecialchars($conn->error);
    echo '<br>SQL Query: ' . htmlspecialchars($sql);
    echo '</div>';
    
    // Also show in console for debugging
    echo '<script>';
    echo 'console.error("Prepare failed: ' . addslashes($conn->error) . '");';
    echo 'console.error("SQL: ' . addslashes($sql) . '");';
    echo '</script>';
    exit;
}

$stmt->bind_param("i", $course_id);
if (!$stmt->execute()) {
    echo '<div class="alert alert-danger">';
    echo 'Execute Error: ' . htmlspecialchars($stmt->error);
    echo '</div>';
    exit;
}

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        ?>
        <div class="list-group-item d-flex justify-content-between align-items-center">
            <div>
                <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                <small class="text-muted d-block"><?= htmlspecialchars($row['email']) ?></small>
                <span class="badge bg-info"><?= ucfirst($row['role']) ?></span>
                <small class="text-muted">Assigned: <?= date('M d, Y', strtotime($row['assigned_date'])) ?></small>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger" 
                    onclick="removeTeacher(<?= $row['user_id'] ?>, <?= $course_id ?>)">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php
    }
} else {
    echo '<div class="alert alert-warning">No teachers assigned yet.</div>';
}
?>