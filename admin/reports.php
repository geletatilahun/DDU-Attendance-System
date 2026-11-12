<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!is_admin()) {
    header('Location: /ddu_attendance/login.php');
    exit();
}

$page_title = "System Reports";
include '../includes/header.php';

// Get statistics
$stats = [];

// Total users
$result = $conn->query("SELECT COUNT(*) as count FROM users");
$stats['total_users'] = $result->fetch_assoc()['count'];

// Total students
$result = $conn->query("SELECT COUNT(*) as count FROM students");
$stats['total_students'] = $result->fetch_assoc()['count'];

// Total teachers
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'teacher'");
$stats['total_teachers'] = $result->fetch_assoc()['count'];

// Total courses
$result = $conn->query("SELECT COUNT(*) as count FROM courses");
$stats['total_courses'] = $result->fetch_assoc()['count'];

// Total departments
$result = $conn->query("SELECT COUNT(*) as count FROM departments");
$stats['total_departments'] = $result->fetch_assoc()['count'];

// Attendance statistics
$result = $conn->query("SELECT 
    COUNT(*) as total_records,
    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count,
    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_count
    FROM attendance");
$attendance_stats = $result->fetch_assoc();
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4">System Reports</h1>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card bg-primary text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Total Users</div>
                                    <div class="fs-2 fw-bold"><?php echo $stats['total_users']; ?></div>
                                </div>
                                <i class="fas fa-users fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card bg-success text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Total Students</div>
                                    <div class="fs-2 fw-bold"><?php echo $stats['total_students']; ?></div>
                                </div>
                                <i class="fas fa-user-graduate fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card bg-info text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Total Teachers</div>
                                    <div class="fs-2 fw-bold"><?php echo $stats['total_teachers']; ?></div>
                                </div>
                                <i class="fas fa-chalkboard-teacher fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card bg-warning text-white mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fs-6 fw-light">Total Courses</div>
                                    <div class="fs-2 fw-bold"><?php echo $stats['total_courses']; ?></div>
                                </div>
                                <i class="fas fa-book fa-3x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Generation Section -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-chart-bar"></i> Attendance Report
                        </div>
                        <div class="card-body">
                            <form id="attendanceReportForm" action="../api/export.php" method="GET">
                                <input type="hidden" name="type" value="attendance">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Start Date</label>
                                        <input type="date" class="form-control" name="start_date" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">End Date</label>
                                        <input type="date" class="form-control" name="end_date" required>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Department</label>
                                        <select class="form-control" name="department_id">
                                            <option value="">All Departments</option>
                                            <?php
                                            $dept_sql = "SELECT * FROM departments ORDER BY name";
                                            $dept_result = $conn->query($dept_sql);
                                            while ($dept = $dept_result->fetch_assoc()):
                                            ?>
                                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Course</label>
                                        <select class="form-control" name="course_id">
                                            <option value="">All Courses</option>
                                            <?php
                                            $course_sql = "SELECT * FROM courses ORDER BY name";
                                            $course_result = $conn->query($course_sql);
                                            while ($course = $course_result->fetch_assoc()):
                                            ?>
                                            <option value="<?php echo $course['id']; ?>"><?php echo htmlspecialchars($course['name']); ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Format</label>
                                    <select class="form-control" name="format">
                                        <option value="pdf">PDF</option>
                                        <option value="excel">Excel</option>
                                        <option value="csv">CSV</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-chart-pie"></i> Student Report
                        </div>
                        <div class="card-body">
                            <form id="studentReportForm" action="../api/export.php" method="GET">
                                <input type="hidden" name="type" value="students">
                                <div class="mb-3">
                                    <label class="form-label">Department</label>
                                    <select class="form-control" name="department_id" required>
                                        <option value="">All Departments</option>
                                        <?php
                                        $dept_sql = "SELECT * FROM departments ORDER BY name";
                                        $dept_result = $conn->query($dept_sql);
                                        while ($dept = $dept_result->fetch_assoc()):
                                        ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Semester</label>
                                    <select class="form-control" name="semester">
                                        <option value="">All Semesters</option>
                                        <?php for ($i = 1; $i <= 8; $i++): ?>
                                        <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Batch Year</label>
                                    <input type="number" class="form-control" name="batch_year" min="2000" max="2099">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Format</label>
                                    <select class="form-control" name="format">
                                        <option value="pdf">PDF</option>
                                        <option value="excel">Excel</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">Generate Report</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Statistics -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i> Attendance Statistics
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card text-white bg-success mb-3">
                                <div class="card-body">
                                    <h5 class="card-title">Present</h5>
                                    <h2 class="card-text"><?php echo $attendance_stats['present_count']; ?></h2>
                                    <p class="card-text">
                                        <?php echo $attendance_stats['total_records'] > 0 ? 
                                            round(($attendance_stats['present_count'] / $attendance_stats['total_records']) * 100, 2) : 0; ?>%
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-white bg-danger mb-3">
                                <div class="card-body">
                                    <h5 class="card-title">Absent</h5>
                                    <h2 class="card-text"><?php echo $attendance_stats['absent_count']; ?></h2>
                                    <p class="card-text">
                                        <?php echo $attendance_stats['total_records'] > 0 ? 
                                            round(($attendance_stats['absent_count'] / $attendance_stats['total_records']) * 100, 2) : 0; ?>%
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card text-white bg-info mb-3">
                                <div class="card-body">
                                    <h5 class="card-title">Total Records</h5>
                                    <h2 class="card-text"><?php echo $attendance_stats['total_records']; ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Set default dates for attendance report
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    
    document.querySelector('input[name="start_date"]').valueAsDate = firstDay;
    document.querySelector('input[name="end_date"]').valueAsDate = today;
});
</script>

<?php include '../includes/footer.php'; ?>