<?php
/**
 * Dean Dashboard - DDU Attendance System
 */

session_start();
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check dean role
checkRole(['dean']);

$dean_id = $_SESSION['user_id'];
$dean_name = $_SESSION['full_name'];

// Get dean's department
$dean_dept = $conn->query("SELECT department_id FROM users WHERE user_id = $dean_id")->fetch_assoc();
$department_id = $dean_dept['department_id'] ?? 0;

// Get department info
$dept_info = $conn->query("
    SELECT d.*, COUNT(DISTINCT s.student_id) as total_students, 
           COUNT(DISTINCT u.user_id) as total_teachers
    FROM departments d
    LEFT JOIN students s ON d.department_id = s.department_id AND s.is_active = 1
    LEFT JOIN users u ON d.department_id = u.department_id AND u.role = 'teacher' AND u.is_active = 1
    WHERE d.department_id = $department_id
    GROUP BY d.department_id
")->fetch_assoc();

// Get statistics for the department
$stats = [];

// Overall attendance for department this month
$current_month = date('Y-m');
$attendance_stats = $conn->query("
    SELECT 
        COUNT(*) as total_sessions,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
    FROM attendance a
    JOIN students s ON a.student_id = s.student_id
    WHERE s.department_id = $department_id
    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = '$current_month'
")->fetch_assoc();

$stats['attendance'] = $attendance_stats;

// Courses with low attendance
$low_attendance_courses = $conn->query("
    SELECT c.course_code, c.course_name, 
           COUNT(DISTINCT a.student_id) as total_students,
           ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    JOIN students s ON a.student_id = s.student_id
    WHERE s.department_id = $department_id
    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = '$current_month'
    GROUP BY c.course_id
    HAVING attendance_percentage < 75
    ORDER BY attendance_percentage ASC
    LIMIT 5
");

// Students with critical attendance
$critical_students = $conn->query("
    SELECT s.student_id_no, s.full_name, s.year_of_study,
           COUNT(*) as total_sessions,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
           ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as attendance_percentage
    FROM attendance a
    JOIN students s ON a.student_id = s.student_id
    WHERE s.department_id = $department_id
    AND DATE_FORMAT(a.attendance_date, '%Y-%m') = '$current_month'
    GROUP BY s.student_id
    HAVING attendance_percentage < 50
    ORDER BY attendance_percentage ASC
    LIMIT 10
");

// Recent attendance activities
$recent_activities = $conn->query("
    SELECT a.attendance_date, c.course_code, u.full_name as teacher_name,
           COUNT(*) as total_students,
           SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    JOIN users u ON a.teacher_id = u.user_id
    JOIN students s ON a.student_id = s.student_id
    WHERE s.department_id = $department_id
    GROUP BY a.attendance_date, a.course_id
    ORDER BY a.attendance_date DESC
    LIMIT 5
");

// Page title
$page_title = "Dean Dashboard - " . ($dept_info['department_name'] ?? 'Department');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/header.php'; ?>
    <style>
        .stat-card {
            border-radius: 10px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .bg-attendance { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-students { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .bg-teachers { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .bg-courses { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            height: 100%;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Welcome Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-1">Welcome, <?php echo htmlspecialchars($dean_name); ?></h3>
                                <p class="text-muted mb-0"><?php echo htmlspecialchars($dept_info['department_name'] ?? ''); ?> Department</p>
                            </div>
                            <div class="text-end">
                                <small class="text-muted">Last login: <?php echo date('F j, Y H:i'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-attendance">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo $stats['attendance']['attendance_percentage'] ?? 0; ?>%
                    </div>
                    <div class="stat-label">Attendance Rate (This Month)</div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-students">
                    <div class="stat-icon">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($dept_info['total_students'] ?? 0); ?>
                    </div>
                    <div class="stat-label">Total Students</div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-teachers">
                    <div class="stat-icon">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-value">
                        <?php echo number_format($dept_info['total_teachers'] ?? 0); ?>
                    </div>
                    <div class="stat-label">Faculty Members</div>
                </div>
            </div>
            
            <div class="col-xl-3 col-md-6">
                <div class="stat-card bg-courses">
                    <div class="stat-icon">
                        <i class="fas fa-book"></i>
                    </div>
                    <div class="stat-value">
                        <?php 
                        $course_count = $conn->query("
                            SELECT COUNT(*) as count FROM courses 
                            WHERE department_id = $department_id AND is_active = 1
                        ")->fetch_assoc()['count'];
                        echo number_format($course_count);
                        ?>
                    </div>
                    <div class="stat-label">Active Courses</div>
                </div>
            </div>
        </div>
        
        <!-- Charts and Reports -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="chart-container">
                    <h5 class="mb-3">Monthly Attendance Trend</h5>
                    <canvas id="attendanceTrendChart" height="250"></canvas>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="chart-container">
                    <h5 class="mb-3">Attendance Distribution</h5>
                    <canvas id="attendanceDistributionChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Alerts and Issues -->
        <div class="row">
            <!-- Low Attendance Courses -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle"></i> Courses with Low Attendance</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($low_attendance_courses->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Students</th>
                                        <th>Attendance</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($course = $low_attendance_courses->fetch_assoc()): 
                                        $status = getAttendanceStatus($course['attendance_percentage']);
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($course['course_code']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($course['course_name']); ?></small>
                                        </td>
                                        <td><?php echo $course['total_students']; ?></td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-<?php echo $status['class']; ?>" 
                                                     style="width: <?php echo $course['attendance_percentage']; ?>%">
                                                    <?php echo $course['attendance_percentage']; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $status['class']; ?>">
                                                <?php echo $status['text']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-check-circle fa-2x mb-3"></i>
                            <p>All courses have satisfactory attendance!</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Critical Students -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="fas fa-user-times"></i> Students with Critical Attendance</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($critical_students->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Year</th>
                                        <th>Attendance</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($student = $critical_students->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['student_id_no']); ?></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td>Year <?php echo $student['year_of_study']; ?></td>
                                        <td>
                                            <span class="badge bg-danger">
                                                <?php echo $student['attendance_percentage']; ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-envelope"></i> Notify
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-check-circle fa-2x mb-3"></i>
                            <p>No students with critical attendance!</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activities -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Recent Attendance Activities</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Course</th>
                                        <th>Teacher</th>
                                        <th>Students</th>
                                        <th>Present</th>
                                        <th>Rate</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($activity = $recent_activities->fetch_assoc()): 
                                        $rate = round(($activity['present_count'] / $activity['total_students']) * 100, 1);
                                    ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($activity['attendance_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($activity['course_code']); ?></td>
                                        <td><?php echo htmlspecialchars($activity['teacher_name']); ?></td>
                                        <td><?php echo $activity['total_students']; ?></td>
                                        <td><?php echo $activity['present_count']; ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $rate >= 75 ? 'success' : ($rate >= 50 ? 'warning' : 'danger'); ?>">
                                                <?php echo $rate; ?>%
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-bolt"></i> Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-2 col-sm-4 mb-3">
                                <a href="department_report.php" class="btn btn-primary w-100">
                                    <i class="fas fa-chart-bar"></i><br>Department Report
                                </a>
                            </div>
                            <div class="col-md-2 col-sm-4 mb-3">
                                <a href="student_performance.php" class="btn btn-success w-100">
                                    <i class="fas fa-user-graduate"></i><br>Student Performance
                                </a>
                            </div>
                            <div class="col-md-2 col-sm-4 mb-3">
                                <a href="../reports/reports.php?department_id=<?php echo $department_id; ?>" class="btn btn-info w-100">
                                    <i class="fas fa-file-export"></i><br>Export Reports
                                </a>
                            </div>
                            <div class="col-md-2 col-sm-4 mb-3">
                                <a href="#" class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#sendNotificationModal">
                                    <i class="fas fa-bullhorn"></i><br>Send Notification
                                </a>
                            </div>
                            <div class="col-md-2 col-sm-4 mb-3">
                                <a href="#" class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#scheduleMeetingModal">
                                    <i class="fas fa-calendar-alt"></i><br>Schedule Meeting
                                </a>
                            </div>
                            <div class="col-md-2 col-sm-4 mb-3">
                                <a href="#" class="btn btn-secondary w-100" onclick="window.print()">
                                    <i class="fas fa-print"></i><br>Print Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Send Notification Modal -->
    <div class="modal fade" id="sendNotificationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Send Notification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="send_notification.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Recipient</label>
                            <select class="form-select" name="recipient_type">
                                <option value="all">All Department Members</option>
                                <option value="teachers">Teachers Only</option>
                                <option value="students">Students Only</option>
                                <option value="specific">Specific Individuals</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-control" name="subject" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" name="message" rows="4" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="low">Low</option>
                                <option value="normal" selected>Normal</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Send Notification</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Schedule Meeting Modal -->
    <div class="modal fade" id="scheduleMeetingModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Schedule Department Meeting</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="schedule_meeting.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Meeting Title</label>
                            <input type="text" class="form-control" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="meeting_date" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Time</label>
                            <input type="time" class="form-control" name="meeting_time" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Venue</label>
                            <input type="text" class="form-control" name="venue" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Agenda</label>
                            <textarea class="form-control" name="agenda" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Invitees</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="invitees[]" value="teachers" checked>
                                <label class="form-check-label">All Teachers</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="invitees[]" value="students">
                                <label class="form-check-label">Student Representatives</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Schedule Meeting</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Attendance Trend Chart
        const trendCtx = document.getElementById('attendanceTrendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Attendance %',
                    data: [85, 82, 88, 90, 87, 92],
                    borderColor: '#003366',
                    backgroundColor: 'rgba(0, 51, 102, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 70,
                        max: 100
                    }
                }
            }
        });
        
        // Attendance Distribution Chart
        const distCtx = document.getElementById('attendanceDistributionChart').getContext('2d');
        const distChart = new Chart(distCtx, {
            type: 'pie',
            data: {
                labels: ['Satisfactory (≥75%)', 'Warning (50-74%)', 'Critical (<50%)'],
                datasets: [{
                    data: [70, 20, 10],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Auto-refresh dashboard every 60 seconds
        setInterval(() => {
            if (!document.querySelector('.modal.show')) {
                location.reload();
            }
        }, 60000);
    </script>
</body>
</html>