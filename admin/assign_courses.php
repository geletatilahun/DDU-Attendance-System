<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

checkRole(['admin']);

// Get all teachers
$teachers = $conn->query("SELECT user_id, full_name, department_id FROM users WHERE role = 'teacher' AND is_active = 1");

// Get all courses
$courses = $conn->query("SELECT course_id, course_code, course_name, department_id FROM courses WHERE is_active = 1");

// Handle assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacher_id = intval($_POST['teacher_id']);
    $course_ids = $_POST['course_ids'] ?? [];
    $academic_year = $_POST['academic_year'];
    $semester = $_POST['semester'];
    
    $conn->begin_transaction();
    
    try {
        // Remove existing assignments for this teacher in this semester
        $delete_stmt = $conn->prepare("DELETE FROM course_assignments WHERE teacher_id = ? AND academic_year = ? AND semester = ?");
        $delete_stmt->bind_param("iii", $teacher_id, $academic_year, $semester);
        $delete_stmt->execute();
        
        // Add new assignments
        $insert_stmt = $conn->prepare("INSERT INTO course_assignments (course_id, teacher_id, academic_year, semester, assigned_date) VALUES (?, ?, ?, ?, CURDATE())");
        
        foreach ($course_ids as $course_id) {
            $course_id = intval($course_id);
            $insert_stmt->bind_param("iiii", $course_id, $teacher_id, $academic_year, $semester);
            $insert_stmt->execute();
        }
        
        $conn->commit();
        $_SESSION['success'] = "Courses assigned successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header('Location: assign_courses.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Courses</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include('../includes/admin_header.php'); ?>
    
    <div class="container mt-4">
        <h2>Assign Courses to Teachers</h2>
        
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); endif; ?>
        
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Select Teacher</label>
                            <select name="teacher_id" class="form-select" required onchange="loadTeacherCourses(this.value)">
                                <option value="">Choose Teacher</option>
                                <?php while($teacher = $teachers->fetch_assoc()): ?>
                                <option value="<?= $teacher['user_id'] ?>"><?= $teacher['full_name'] ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label>Academic Year</label>
                            <select name="academic_year" class="form-select" required>
                                <option value="2024">2024</option>
                                <option value="2025">2025</option>
                                <option value="2026">2026</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3 mb-3">
                            <label>Semester</label>
                            <select name="semester" class="form-select" required>
                                <option value="1">Semester 1</option>
                                <option value="2">Semester 2</option>
                                <option value="3">Summer</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Select Courses</label>
                        <div id="coursesContainer" class="border p-3" style="max-height: 300px; overflow-y: auto;">
                            <?php while($course = $courses->fetch_assoc()): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="course_ids[]" 
                                       value="<?= $course['course_id'] ?>" id="course_<?= $course['course_id'] ?>">
                                <label class="form-check-label" for="course_<?= $course['course_id'] ?>">
                                    <?= $course['course_code'] ?> - <?= $course['course_name'] ?>
                                </label>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Assign Courses</button>
                </form>
            </div>
        </div>
        
        <!-- Current Assignments -->
        <div class="card mt-4">
            <div class="card-header">Current Assignments</div>
            <div class="card-body">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Course</th>
                            <th>Academic Year</th>
                            <th>Semester</th>
                            <th>Assigned Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $assignments = $conn->query("
                            SELECT u.full_name as teacher, c.course_code, c.course_name, 
                                   ca.academic_year, ca.semester, ca.assigned_date
                            FROM course_assignments ca
                            JOIN users u ON ca.teacher_id = u.user_id
                            JOIN courses c ON ca.course_id = c.course_id
                            ORDER BY ca.academic_year DESC, ca.semester DESC
                            LIMIT 20
                        ");
                        
                        while($assign = $assignments->fetch_assoc()):
                        ?>
                        <tr>
                            <td><?= $assign['teacher'] ?></td>
                            <td><?= $assign['course_code'] ?> - <?= $assign['course_name'] ?></td>
                            <td><?= $assign['academic_year'] ?></td>
                            <td>Semester <?= $assign['semester'] ?></td>
                            <td><?= date('M d, Y', strtotime($assign['assigned_date'])) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>