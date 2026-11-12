<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!is_teacher()) {
    header('Location: /ddu_attendance/login.php');
    exit();
}

$page_title = "Generate Reports";
include '../includes/header.php';

$teacher_id = $_SESSION['user_id'];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4">Generate Reports</h1>
            
            <!-- Report Cards -->
            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Attendance Report</h5>
                        </div>
                        <div class="card-body">
                            <p>Generate detailed attendance reports for specific courses and date ranges.</p>
                            <ul>
                                <li>Daily attendance summary</li>
                                <li>Student-wise attendance</li>
                                <li>Attendance percentage charts</li>
                                <li>Export to PDF/Excel</li>
                            </ul>
                        </div>
                        <div class="card-footer">
                            <a href="attendance_report.php" class="btn btn-primary">Generate</a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-user-graduate"></i> Student Performance</h5>
                        </div>
                        <div class="card-body">
                            <p>Generate student performance reports based on attendance.</p>
                            <ul>
                                <li>Attendance percentage</li>
                                <li>Defaulter list</li>
                                <li>Attendance trends</li>
                                <li>Warning letters</li>
                            </ul>
                        </div>
                        <div class="card-footer">
                            <a href="student_performance.php" class="btn btn-success">Generate</a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Monthly Summary</h5>
                        </div>
                        <div class="card-body">
                            <p>Generate monthly attendance summaries for all courses.</p>
                            <ul>
                                <li>Monthly attendance percentage</li>
                                <li>Course-wise comparison</li>
                                <li>Department statistics</li>
                                <li>Export options</li>
                            </ul>
                        </div>
                        <div class="card-footer">
                            <a href="monthly_summary.php" class="btn btn-info">Generate</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Report Generation -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-file-export"></i> Quick Report Generator
                </div>
                <div class="card-body">
                    <form id="quickReportForm" action="../api/export.php" method="GET" target="_blank">
                        <input type="hidden" name="type" value="teacher_report">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Report Type</label>
                                <select class="form-control" name="report_type" required>
                                    <option value="">Select report type</option>
                                    <option value="attendance_summary">Attendance Summary</option>
                                    <option value="defaulter_list">Defaulter List</option>
                                    <option value="student_attendance">Student Attendance</option>
                                    <option value="monthly_report">Monthly Report</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Start Date</label>
                                <input type="date" class="form-control" name="start_date" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">End Date</label>
                                <input type="date" class="form-control" name="end_date" required>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Course</label>
                                <select class="form-control" name="course_id">
                                    <option value="">All Courses</option>
                                    <?php
                                    $courses_sql = "SELECT c.* FROM courses c
                                                   JOIN course_teachers ct ON c.id = ct.course_id
                                                   WHERE ct.teacher_id = ? AND c.status = 'active'
                                                   ORDER BY c.code";
                                    $courses_stmt = $conn->prepare($courses_sql);
                                    $courses_stmt->bind_param("i", $teacher_id);
                                    $courses_stmt->execute();
                                    $courses = $courses_stmt->get_result();
                                    
                                    while ($course = $courses->fetch_assoc()):
                                    ?>
                                    <option value="<?php echo $course['id']; ?>">
                                        <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name']); ?>
                                    </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Format</label>
                                <select class="form-control" name="format" required>
                                    <option value="pdf">PDF Document</option>
                                    <option value="excel">Excel Spreadsheet</option>
                                    <option value="csv">CSV File</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Include Charts</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="include_charts" value="1" id="includeCharts">
                                <label class="form-check-label" for="includeCharts">
                                    Include charts and graphs in the report
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download"></i> Generate Report
                        </button>
                    </form>
                </div>
            </div>

            <!-- Recent Reports -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-history"></i> Recently Generated Reports
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Report Type</th>
                                    <th>Period</th>
                                    <th>Format</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $reports_sql = "SELECT * FROM generated_reports 
                                               WHERE teacher_id = ? 
                                               ORDER BY created_at DESC 
                                               LIMIT 10";
                                $reports_stmt = $conn->prepare($reports_sql);
                                $reports_stmt->bind_param("i", $teacher_id);
                                $reports_stmt->execute();
                                $reports = $reports_stmt->get_result();
                                
                                while ($report = $reports->fetch_assoc()):
                                ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($report['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($report['report_type']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($report['start_date'])) . ' to ' . date('M d, Y', strtotime($report['end_date'])); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo strtoupper($report['format']); ?></span></td>
                                    <td><span class="badge bg-success">Completed</span></td>
                                    <td>
                                        <?php if ($report['file_path']): ?>
                                        <a href="<?php echo htmlspecialchars($report['file_path']); ?>" class="btn btn-sm btn-primary" download>
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                
                                <?php if ($reports->num_rows == 0): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No reports generated yet.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Set default dates
document.addEventListener('DOMContentLoaded', function() {
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    
    document.querySelector('input[name="start_date"]').valueAsDate = firstDay;
    document.querySelector('input[name="end_date"]').valueAsDate = today;
});
</script>

<?php include '../includes/footer.php'; ?>