<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!is_teacher()) {
    header('Location: /ddu_attendance/login.php');
    exit();
}

$page_title = "Take Attendance";
include '../includes/header.php';

$teacher_id = $_SESSION['user_id'];

// Get course if specified
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get teacher's courses for dropdown
$courses_sql = "SELECT c.*, d.name as department_name
                FROM courses c
                JOIN course_teachers ct ON c.id = ct.course_id
                JOIN departments d ON c.department_id = d.id
                WHERE ct.teacher_id = ? AND c.status = 'active'
                ORDER BY c.semester, c.code";
$courses_stmt = $conn->prepare($courses_sql);
$courses_stmt->bind_param("i", $teacher_id);
$courses_stmt->execute();
$courses = $courses_stmt->get_result();

// If course is selected, get enrolled students
$students = [];
$course_details = null;
if ($course_id > 0) {
    // Get course details
    $course_sql = "SELECT c.*, d.name as department_name FROM courses c
                   JOIN departments d ON c.department_id = d.id
                   WHERE c.id = ?";
    $course_stmt = $conn->prepare($course_sql);
    $course_stmt->bind_param("i", $course_id);
    $course_stmt->execute();
    $course_details = $course_stmt->get_result()->fetch_assoc();
    
    // Get enrolled students
    $students_sql = "SELECT s.*, u.email, 
                     (SELECT status FROM attendance 
                      WHERE student_id = s.id AND course_id = ? 
                      AND DATE(date) = ? LIMIT 1) as attendance_status
                     FROM students s
                     JOIN users u ON s.user_id = u.id
                     JOIN enrollments e ON s.id = e.student_id
                     WHERE e.course_id = ? AND u.status = 'active'
                     ORDER BY s.enrollment_number";
    $students_stmt = $conn->prepare($students_sql);
    $students_stmt->bind_param("isi", $course_id, $selected_date, $course_id);
    $students_stmt->execute();
    $students = $students_stmt->get_result();
    
    // Check if attendance already taken for today
    $check_sql = "SELECT COUNT(*) as count FROM attendance 
                  WHERE course_id = ? AND teacher_id = ? AND DATE(date) = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("iis", $course_id, $teacher_id, $selected_date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    $attendance_taken = $check_result['count'] > 0;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4">Take Attendance</h1>
            
            <!-- Course Selection Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-filter"></i> Select Course and Date
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Select Course</label>
                            <select class="form-control" name="course_id" required>
                                <option value="">Select a course...</option>
                                <?php while ($course = $courses->fetch_assoc()): ?>
                                <option value="<?php echo $course['id']; ?>" 
                                    <?php echo $course_id == $course['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($course['code'] . ' - ' . $course['name'] . ' (' . $course['department_name'] . ')'); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="date" 
                                   value="<?php echo $selected_date; ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block">Load Students</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($course_id > 0 && $course_details): ?>
            <!-- Course Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Course Information
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Course:</strong> <?php echo htmlspecialchars($course_details['code'] . ' - ' . $course_details['name']); ?></p>
                            <p><strong>Department:</strong> <?php echo htmlspecialchars($course_details['department_name']); ?></p>
                            <p><strong>Semester:</strong> <?php echo $course_details['semester']; ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Date:</strong> <?php echo date('F d, Y', strtotime($selected_date)); ?></p>
                            <p><strong>Total Students:</strong> <?php echo $students->num_rows; ?></p>
                            <?php if ($attendance_taken): ?>
                            <p><strong>Status:</strong> <span class="badge bg-success">Attendance Already Taken</span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Form -->
            <?php if (!$attendance_taken): ?>
            <form id="attendanceForm">
                <input type="hidden" name="course_id" value="<?php echo $course_id; ?>">
                <input type="hidden" name="date" value="<?php echo $selected_date; ?>">
                
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="fas fa-user-graduate"></i> Student List
                    </div>
                    <div class="card-body">
                        <?php if ($students->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>Enrollment No.</th>
                                        <th>Student Name</th>
                                        <th>Email</th>
                                        <th>Attendance Status</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($student = $students->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['enrollment_number']); ?></td>
                                        <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email']); ?></td>
                                        <td>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" 
                                                       name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="present" id="present_<?php echo $student['id']; ?>"
                                                       <?php echo ($student['attendance_status'] ?? '') == 'present' ? 'checked' : 'checked'; ?>>
                                                <label class="form-check-label" for="present_<?php echo $student['id']; ?>">Present</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" 
                                                       name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="absent" id="absent_<?php echo $student['id']; ?>"
                                                       <?php echo ($student['attendance_status'] ?? '') == 'absent' ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="absent_<?php echo $student['id']; ?>">Absent</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input class="form-check-input" type="radio" 
                                                       name="attendance[<?php echo $student['id']; ?>]" 
                                                       value="late" id="late_<?php echo $student['id']; ?>"
                                                       <?php echo ($student['attendance_status'] ?? '') == 'late' ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="late_<?php echo $student['id']; ?>">Late</label>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm" 
                                                   name="remarks[<?php echo $student['id']; ?>]" 
                                                   placeholder="Optional remarks">
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="d-flex justify-content-between mt-3">
                            <div>
                                <button type="button" class="btn btn-outline-primary" onclick="markAll('present')">
                                    Mark All Present
                                </button>
                                <button type="button" class="btn btn-outline-danger" onclick="markAll('absent')">
                                    Mark All Absent
                                </button>
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save"></i> Submit Attendance
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            No students enrolled in this course.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            <?php else: ?>
            <div class="alert alert-info">
                Attendance for this course has already been taken for <?php echo date('F d, Y', strtotime($selected_date)); ?>.
                You can <a href="attendance_history.php?course_id=<?php echo $course_id; ?>">view attendance history</a> 
                or <a href="take_attendance.php">take attendance for another course/date</a>.
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Mark all students with specified status
function markAll(status) {
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
        if (radio.value === status) {
            radio.checked = true;
        }
    });
}

// Handle attendance form submission
document.getElementById('attendanceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    // Convert form data to JSON
    const data = {
        course_id: formData.get('course_id'),
        date: formData.get('date'),
        attendance: {},
        remarks: {}
    };
    
    // Process attendance data
    for (let [key, value] of formData.entries()) {
        if (key.startsWith('attendance[')) {
            const studentId = key.match(/\[(\d+)\]/)[1];
            data.attendance[studentId] = value;
        } else if (key.startsWith('remarks[')) {
            const studentId = key.match(/\[(\d+)\]/)[1];
            data.remarks[studentId] = value;
        }
    }
    
    // Submit via AJAX
    fetch('../api/attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Attendance submitted successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while submitting attendance.');
    });
});
</script>

<?php include '../includes/footer.php'; ?>