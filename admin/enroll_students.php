<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

checkRole(['admin']);

// Get courses
$courses = $conn->query("SELECT course_id, course_code, course_name FROM courses WHERE is_active = 1");

// Get departments
$departments = $conn->query("SELECT * FROM departments");

// Handle enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_id = intval($_POST['course_id']);
    $student_ids = $_POST['student_ids'] ?? [];
    $academic_year = $_POST['academic_year'];
    $semester = $_POST['semester'];
    
    $conn->begin_transaction();
    
    try {
        foreach ($student_ids as $student_id) {
            $student_id = intval($student_id);
            
            $stmt = $conn->prepare("
                INSERT INTO student_enrollments (student_id, course_id, academic_year, semester, enrollment_date, enrollment_status)
                VALUES (?, ?, ?, ?, CURDATE(), 'active')
                ON DUPLICATE KEY UPDATE enrollment_status = 'active'
            ");
            $stmt->bind_param("iiii", $student_id, $course_id, $academic_year, $semester);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        $_SESSION['success'] = "Students enrolled successfully!";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    header('Location: enroll_students.php');
    exit;
}

// Filter students
$filter_department = $_GET['department'] ?? '';
$filter_year = $_GET['year'] ?? '';
$filter_course = $_GET['course'] ?? '';

$where = "WHERE s.is_active = 1";
if ($filter_department) $where .= " AND s.department_id = $filter_department";
if ($filter_year) $where .= " AND s.year_of_study = $filter_year";

$students = $conn->query("
    SELECT s.student_id, s.student_id_no, s.full_name, d.department_name, s.year_of_study
    FROM students s
    LEFT JOIN departments d ON s.department_id = d.department_id
    $where
    ORDER BY s.full_name
    LIMIT 100
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Enroll Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include('../includes/admin_header.php'); ?>
    
    <div class="container-fluid mt-4">
        <h2>Enroll Students in Courses</h2>
        
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); endif; ?>
        
        <div class="row">
            <!-- Filter Panel -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-header">Filter Students</div>
                    <div class="card-body">
                        <form method="GET">
                            <div class="mb-3">
                                <label>Department</label>
                                <select name="department" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Departments</option>
                                    <?php while($dept = $departments->fetch_assoc()): ?>
                                    <option value="<?= $dept['department_id'] ?>" <?= $filter_department == $dept['department_id'] ? 'selected' : '' ?>>
                                        <?= $dept['department_name'] ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label>Year of Study</label>
                                <select name="year" class="form-select" onchange="this.form.submit()">
                                    <option value="">All Years</option>
                                    <?php for($i=1; $i<=5; $i++): ?>
                                    <option value="<?= $i ?>" <?= $filter_year == $i ? 'selected' : '' ?>>Year <?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Main Enrollment Form -->
            <div class="col-md-9">
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label>Select Course</label>
                                    <select name="course_id" class="form-select" required>
                                        <option value="">Choose Course</option>
                                        <?php while($course = $courses->fetch_assoc()): ?>
                                        <option value="<?= $course['course_id'] ?>"><?= $course['course_code'] ?> - <?= $course['course_name'] ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label>Academic Year</label>
                                    <input type="number" name="academic_year" class="form-control" value="2024" required>
                                </div>
                                <div class="col-md-3">
                                    <label>Semester</label>
                                    <select name="semester" class="form-select" required>
                                        <option value="1">Semester 1</option>
                                        <option value="2">Semester 2</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Select Students</label>
                                <div class="border p-3" style="max-height: 400px; overflow-y: auto;">
                                    <button type="button" class="btn btn-sm btn-secondary mb-2" onclick="selectAllStudents()">Select All</button>
                                    <button type="button" class="btn btn-sm btn-secondary mb-2" onclick="deselectAllStudents()">Deselect All</button>
                                    
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th width="50">Select</th>
                                                <th>Student ID</th>
                                                <th>Name</th>
                                                <th>Department</th>
                                                <th>Year</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while($student = $students->fetch_assoc()): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="student_ids[]" 
                                                           value="<?= $student['student_id'] ?>" 
                                                           class="student-checkbox">
                                                </td>
                                                <td><?= $student['student_id_no'] ?></td>
                                                <td><?= $student['full_name'] ?></td>
                                                <td><?= $student['department_name'] ?></td>
                                                <td>Year <?= $student['year_of_study'] ?></td>
                                            </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">Enroll Selected Students</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function selectAllStudents() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = true);
        }
        
        function deselectAllStudents() {
            document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
        }
    </script>
</body>
</html>