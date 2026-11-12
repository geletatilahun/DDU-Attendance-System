<?php
session_start();
require_once '../includes/db_connect.php';

echo "<h2>Debug Teacher Assignment System</h2>";
echo "<hr>";

// Check session
echo "<h3>1. Session Check</h3>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "<br>";
echo "Role: " . ($_SESSION['role'] ?? 'Not set') . "<br>";
echo "Is Admin: " . (($_SESSION['role'] ?? '') === 'admin' ? 'Yes' : 'No') . "<br>";

// Check if course_teachers table exists
echo "<h3>2. Database Tables Check</h3>";
$tables = $conn->query("SHOW TABLES LIKE 'course_teachers'");
if ($tables->num_rows > 0) {
    echo "✅ course_teachers table exists<br>";
    
    // Show table structure
    echo "<h4>Table Structure:</h4>";
    $structure = $conn->query("DESCRIBE course_teachers");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $structure->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . $value . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    // Show existing assignments
    echo "<h4>Existing Assignments:</h4>";
    $assignments = $conn->query("
        SELECT ct.*, c.course_code, c.course_name, u.full_name as teacher_name
        FROM course_teachers ct
        LEFT JOIN courses c ON ct.course_id = c.course_id
        LEFT JOIN users u ON ct.teacher_id = u.user_id
        LIMIT 10
    ");
    
    if ($assignments->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Course</th><th>Teacher</th><th>Role</th><th>Assigned Date</th></tr>";
        while ($row = $assignments->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['course_code'] . " - " . $row['course_name'] . "</td>";
            echo "<td>" . $row['teacher_name'] . "</td>";
            echo "<td>" . $row['role'] . "</td>";
            echo "<td>" . $row['assigned_date'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No assignments found in database<br>";
    }
} else {
    echo "❌ course_teachers table does NOT exist<br>";
}

// Check available teachers
echo "<h3>3. Available Teachers</h3>";
$teachers = $conn->query("
    SELECT u.user_id, u.full_name, u.email, u.role, u.is_active, d.department_name
    FROM users u
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.role = 'teacher'
    ORDER BY u.full_name
");

if ($teachers->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Active</th><th>Department</th></tr>";
    while ($row = $teachers->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['user_id'] . "</td>";
        echo "<td>" . $row['full_name'] . "</td>";
        echo "<td>" . $row['email'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "<td>" . ($row['department_name'] ?? 'None') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No teachers found in database<br>";
}

// Check available courses
echo "<h3>4. Available Courses</h3>";
$courses = $conn->query("
    SELECT c.course_id, c.course_code, c.course_name, c.is_active, d.department_name
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.department_id
    ORDER BY c.course_code
    LIMIT 10
");

if ($courses->num_rows > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Active</th><th>Department</th></tr>";
    while ($row = $courses->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['course_id'] . "</td>";
        echo "<td>" . $row['course_code'] . "</td>";
        echo "<td>" . $row['course_name'] . "</td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "<td>" . ($row['department_name'] ?? 'None') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No courses found in database<br>";
}

// Test form submission
echo "<h3>5. Test Form Submission</h3>";
?>
<form method="POST" action="test_assign_action.php">
    <label>Course ID: <input type="number" name="course_id" value="1"></label><br>
    <label>Teacher ID: <input type="number" name="teacher_id" value="1"></label><br>
    <label>Role: 
        <select name="role">
            <option value="primary">Primary</option>
            <option value="assistant">Assistant</option>
        </select>
    </label><br>
    <input type="submit" value="Test Assignment">
</form>
<?php
echo "<hr>";
echo "<h3>6. Check File Permissions</h3>";
$files = [
    'assign_teacher_action.php',
    'course_actions.php',
    'get_assigned_teachers.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✅ $file exists (Size: " . filesize($file) . " bytes)<br>";
    } else {
        echo "❌ $file does not exist<br>";
    }
}
?>