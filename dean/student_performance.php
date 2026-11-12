<?php
/**
 * Student Performance Analysis - Dean Module
 */

session_start();
require_once '../includes/db_connect.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Check dean role
checkRole(['dean']);

$dean_id = $_SESSION['user_id'];

// Get dean's department
$dean_dept = $conn->query("SELECT department_id FROM users WHERE user_id = $dean_id")->fetch_assoc();
$department_id = $dean_dept['department_id'] ?? 0;

// Get department info
$department = $conn->query("SELECT * FROM departments WHERE department_id = $department_id")->fetch_assoc();

// Filters
$year_of_study = $_GET['year_of_study'] ?? '';
$program = $_GET['program'] ?? '';
$semester = $_GET['semester'] ?? '1';
$threshold = $_GET['threshold'] ?? 75;

// Get student performance data
function getStudentPerformance($conn, $dept_id, $filters) {
    $where = "WHERE s.department_id = $dept_id AND s.is_active = 1";
    
    if (!empty($filters['year_of_study'])) {
        $where .= " AND s.year_of_study = " . intval($filters['year_of_study']);
    }
    
    if (!empty($filters['program'])) {
        $where .= " AND s.program = '" . $conn->real_escape_string($filters['program']) . "'";
    }
    
    if (!empty($filters['semester'])) {
        $where .= " AND c.semester = " . intval($filters['semester']);
    }
    
    $sql = "
        SELECT 
            s.student_id,
            s.student_id_no,
            s.full_name,
            s.year_of_study,
            s.program,
            s.email,
            s.phone,
            COUNT(DISTINCT a.course_id) as total_courses,
            COUNT(*) as total_sessions,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as overall_percentage,
            
            -- Course-wise performance
            GROUP_CONCAT(DISTINCT CONCAT(
                c.course_code, ':', 
                ROUND((SUM(CASE WHEN a.status = 'present' AND a.course_id = c.course_id THEN 1 ELSE 0 END) * 100.0 / 
                      NULLIF(COUNT(CASE WHEN a.course_id = c.course_id THEN 1 END), 0)), 2)
            ) SEPARATOR ';') as course_performance,
            
            -- Attendance pattern
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days,
            
            -- Risk assessment
            CASE 
                WHEN COUNT(*) = 0 THEN 'No Data'
                WHEN (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) >= {$filters['threshold']} THEN 'Low Risk'
                WHEN (SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) >= 50 THEN 'Medium Risk'
                ELSE 'High Risk'
            END as risk_level,
            
            -- Last attendance date
            MAX(a.attendance_date) as last_attendance_date
            
        FROM students s
        LEFT JOIN attendance a ON s.student_id = a.student_id
        LEFT JOIN courses c ON a.course_id = c.course_id
        $where
        GROUP BY s.student_id
        HAVING total_sessions > 0
        ORDER BY overall_percentage ASC
    ";
    
    return $conn->query($sql);
}

// Get programs in department
$programs = $conn->query("
    SELECT DISTINCT program 
    FROM students 
    WHERE department_id = $department_id AND program IS NOT NULL AND program != ''
    ORDER BY program
");

// Get performance summary
function getPerformanceSummary($conn, $dept_id, $filters) {
    $where = "WHERE s.department_id = $dept_id AND s.is_active = 1";
    
    if (!empty($filters['year_of_study'])) {
        $where .= " AND s.year_of_study = " . intval($filters['year_of_study']);
    }
    
    if (!empty($filters['program'])) {
        $where .= " AND s.program = '" . $conn->real_escape_string($filters['program']) . "'";
    }
    
    $sql = "
        SELECT 
            COUNT(DISTINCT s.student_id) as total_students,
            AVG(overall_percentage) as avg_attendance,
            SUM(CASE WHEN overall_percentage >= {$filters['threshold']} THEN 1 ELSE 0 END) as satisfactory_count,
            SUM(CASE WHEN overall_percentage < {$filters['threshold']} AND overall_percentage >= 50 THEN 1 ELSE 0 END) as warning_count,
            SUM(CASE WHEN overall_percentage < 50 THEN 1 ELSE 0 END) as critical_count,
            ROUND((SUM(CASE WHEN overall_percentage >= {$filters['threshold']} THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as satisfactory_rate
        FROM (
            SELECT 
                s.student_id,
                ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0)), 2) as overall_percentage
            FROM students s
            LEFT JOIN attendance a ON s.student_id = a.student_id
            $where
            GROUP BY s.student_id
            HAVING COUNT(*) > 0
        ) as student_stats
    ";
    
    return $conn->query($sql)->fetch_assoc();
}

$filters = [
    'year_of_study' => $year_of_study,
    'program' => $program,
    'semester' => $semester,
    'threshold' => $threshold
];

$performance_data = getStudentPerformance($conn, $department_id, $filters);
$summary = getPerformanceSummary($conn, $department_id, $filters);

$page_title = "Student Performance Analysis";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include '../includes/header.php'; ?>
    <style>
        .risk-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        
        .risk-low {
            background-color: #d4edda;
            color: #155724;
        }
        
        .risk-medium {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .risk-high {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .performance-card {
            border-left: 4px solid;
            margin-bottom: 15px;
        }
        
        .performance-excellent {
            border-left-color: #28a745;
        }
        
        .performance-good {
            border-left-color: #20c997;
        }
        
        .performance-fair {
            border-left-color: #ffc107;
        }
        
        .performance-poor {
            border-left-color: #fd7e14;
        }
        
        .performance-critical {
            border-left-color: #dc3545;
        }
        
        .summary-card {
            text-align: center;
            padding: 20px;
            border-radius: 10px;
            color: white;
            margin-bottom: 20px;
        }
        
        .summary-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <h2 class="page-title">Student Performance Analysis</h2>
        <p class="text-muted">Department: <?php echo htmlspecialchars($department['department_name'] ?? ''); ?></p>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Year of Study</label>
                        <select name="year_of_study" class="form-select">
                            <option value="">All Years</option>
                            <?php for($i=1; $i<=5; $i++): ?>
                            <option value="<?= $i ?>" <?= $year_of_study == $i ? 'selected' : '' ?>>Year <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Program</label>
                        <select name="program" class="form-select">
                            <option value="">All Programs</option>
                            <?php while($prog = $programs->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($prog['program']) ?>" 
                                <?= $program == $prog['program'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prog['program']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Semester</label>
                        <select name="semester" class="form-select">
                            <option value="1" <?= $semester == 1 ? 'selected' : '' ?>>Semester 1</option>
                            <option value="2" <?= $semester == 2 ? 'selected' : '' ?>>Semester 2</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Threshold %</label>
                        <input type="number" name="threshold" class="form-control" 
                               value="<?= $threshold ?>" min="0" max="100" step="5">
                    </div>
                    
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                        <a href="student_performance.php" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <div class="summary-value"><?= $summary['total_students'] ?? 0 ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="summary-value"><?= round($summary['avg_attendance'] ?? 0, 1) ?>%</div>
                    <div class="summary-label">Average Attendance</div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="summary-value"><?= $summary['satisfactory_rate'] ?? 0 ?>%</div>
                    <div class="summary-label">Satisfactory Rate</div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="summary-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="summary-value"><?= $summary['critical_count'] ?? 0 ?></div>
                    <div class="summary-label">Critical Cases</div>
                </div>
            </div>
        </div>
        
        <!-- Performance Distribution -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Performance Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="performanceChart" height="200"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Risk Assessment</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <tr>
                                    <td><span class="risk-badge risk-low">Low Risk</span></td>
                                    <td class="text-end"><?= $summary['satisfactory_count'] ?? 0 ?> students</td>
                                </tr>
                                <tr>
                                    <td><span class="risk-badge risk-medium">Medium Risk</span></td>
                                    <td class="text-end"><?= $summary['warning_count'] ?? 0 ?> students</td>
                                </tr>
                                <tr>
                                    <td><span class="risk-badge risk-high">High Risk</span></td>
                                    <td class="text-end"><?= $summary['critical_count'] ?? 0 ?> students</td>
                                </tr>
                            </table>
                        </div>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar bg-success" style="width: <?= $summary['satisfactory_rate'] ?? 0 ?>%">
                                <?= round($summary['satisfactory_rate'] ?? 0, 1) ?>%
                            </div>
                        </div>
                        <small class="text-muted">Percentage of students meeting threshold</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detailed Performance Table -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Student Performance Details</h5>
                <div>
                    <button class="btn btn-sm btn-outline-primary" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table" id="performanceTable">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Year</th>
                                <th>Program</th>
                                <th>Courses</th>
                                <th>Attendance %</th>
                                <th>Absent Days</th>
                                <th>Late Days</th>
                                <th>Risk Level</th>
                                <th>Last Attendance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($student = $performance_data->fetch_assoc()): 
                                $performance_class = '';
                                if ($student['overall_percentage'] >= 85) $performance_class = 'performance-excellent';
                                elseif ($student['overall_percentage'] >= 75) $performance_class = 'performance-good';
                                elseif ($student['overall_percentage'] >= 60) $performance_class = 'performance-fair';
                                elseif ($student['overall_percentage'] >= 50) $performance_class = 'performance-poor';
                                else $performance_class = 'performance-critical';
                                
                                $risk_class = strtolower(str_replace(' ', '-', $student['risk_level']));
                            ?>
                            <tr class="<?= $performance_class ?>">
                                <td><?= htmlspecialchars($student['student_id_no']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($student['full_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($student['email']) ?></small>
                                </td>
                                <td>Year <?= $student['year_of_study'] ?></td>
                                <td><?= htmlspecialchars($student['program']) ?></td>
                                <td><?= $student['total_courses'] ?></td>
                                <td>
                                    <div class="progress" style="height: 20px; width: 100px;">
                                        <div class="progress-bar bg-<?= $student['overall_percentage'] >= 75 ? 'success' : ($student['overall_percentage'] >= 50 ? 'warning' : 'danger') ?>" 
                                             style="width: <?= min($student['overall_percentage'], 100) ?>%">
                                            <?= $student['overall_percentage'] ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-danger"><?= $student['absent_days'] ?? 0 ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-warning"><?= $student['late_days'] ?? 0 ?></span>
                                </td>
                                <td>
                                    <span class="risk-badge risk-<?= $risk_class ?>">
                                        <?= $student['risk_level'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $student['last_attendance_date'] ? date('M d, Y', strtotime($student['last_attendance_date'])) : 'No data' ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-info" 
                                            onclick="viewStudentDetails(<?= $student['student_id'] ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning"
                                            onclick="sendWarning(<?= $student['student_id'] ?>, '<?= htmlspecialchars($student['full_name']) ?>')">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="generateReport(<?= $student['student_id'] ?>)">
                                        <i class="fas fa-file-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Analysis and Recommendations -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-chart-line"></i> Analysis & Recommendations</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Key Findings:</h6>
                        <ul>
                            <li>Overall department attendance: <strong><?= round($summary['avg_attendance'] ?? 0, 1) ?>%</strong></li>
                            <li><strong><?= $summary['critical_count'] ?? 0 ?></strong> students need immediate intervention</li>
                            <li><strong><?= $summary['warning_count'] ?? 0 ?></strong> students are at risk of falling below threshold</li>
                            <li>Satisfactory rate: <strong><?= $summary['satisfactory_rate'] ?? 0 ?>%</strong></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Recommended Actions:</h6>
                        <ol>
                            <li>Schedule meetings with high-risk students</li>
                            <li>Implement attendance improvement programs</li>
                            <li>Provide counseling for chronically absent students</li>
                            <li>Review course scheduling and teaching methods</li>
                            <li>Enhance student engagement initiatives</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Student Details Modal -->
    <div class="modal fade" id="studentDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Student Performance Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="studentDetailsContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Send Warning Modal -->
    <div class="modal fade" id="warningModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Send Warning to Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="warningForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Student</label>
                            <input type="text" id="warningStudentName" class="form-control" readonly>
                            <input type="hidden" id="warningStudentId" name="student_id">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Warning Type</label>
                            <select class="form-select" name="warning_type" required>
                                <option value="attendance_low">Low Attendance</option>
                                <option value="chronic_absence">Chronic Absence</option>
                                <option value="late_attendance">Frequent Late Arrival</option>
                                <option value="performance">Academic Performance</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" name="message" rows="4" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Severity</label>
                            <select class="form-select" name="severity" required>
                                <option value="low">Low - Advisory</option>
                                <option value="medium" selected>Medium - Warning</option>
                                <option value="high">High - Final Warning</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Action Required</label>
                            <textarea class="form-control" name="action_required" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Send Warning</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#performanceTable').DataTable({
                pageLength: 25,
                order: [[5, 'asc']] // Sort by attendance percentage ascending
            });
        });
        
        // Performance Chart
        const ctx = document.getElementById('performanceChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Excellent (≥85%)', 'Good (75-84%)', 'Fair (60-74%)', 'Poor (50-59%)', 'Critical (<50%)'],
                datasets: [{
                    label: 'Number of Students',
                    data: [
                        <?= $excellent_count = 0 ?>,
                        <?= $good_count = 0 ?>,
                        <?= $fair_count = 0 ?>,
                        <?= $poor_count = 0 ?>,
                        <?= $critical_count = $summary['critical_count'] ?? 0 ?>
                    ],
                    backgroundColor: [
                        '#28a745',
                        '#20c997',
                        '#ffc107',
                        '#fd7e14',
                        '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Students'
                        }
                    }
                }
            }
        });
        
        // View student details
        function viewStudentDetails(studentId) {
            fetch(`get_student_details.php?id=${studentId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('studentDetailsContent').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('studentDetailsModal')).show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load student details');
                });
        }
        
        // Send warning
        function sendWarning(studentId, studentName) {
            document.getElementById('warningStudentId').value = studentId;
            document.getElementById('warningStudentName').value = studentName;
            new bootstrap.Modal(document.getElementById('warningModal')).show();
        }
        
        // Handle warning form submission
        document.getElementById('warningForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('send_warning.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Warning sent successfully!');
                    bootstrap.Modal.getInstance(document.getElementById('warningModal')).hide();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to send warning');
            });
        });
        
        // Generate student report
        function generateReport(studentId) {
            window.open(`generate_student_report.php?id=${studentId}`, '_blank');
        }
        
        // Export to Excel
        function exportToExcel() {
            const table = document.getElementById('performanceTable');
            const html = table.outerHTML;
            const blob = new Blob([html], {type: 'application/vnd.ms-excel'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            
            a.href = url;
            a.download = 'student_performance_' + new Date().toISOString().split('T')[0] + '.xls';
            a.click();
            
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>