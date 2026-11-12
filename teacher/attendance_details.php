<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

checkRole(['teacher']);

if (!isset($_GET['id'])) {
    header("Location: attendance_reports.php");
    exit;
}

$attendance_id = (int) $_GET['id'];
$teacher_id = (int) $_SESSION['user_id'];

// Verify teacher has access to this attendance record
$verify_sql = "
    SELECT a.*, c.course_code, c.course_name
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    JOIN course_teachers ct ON c.course_id = ct.course_id
    WHERE a.attendance_id = ? AND ct.teacher_id = ?
";

$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param("ii", $attendance_id, $teacher_id);
$verify_stmt->execute();
$attendance = $verify_stmt->get_result()->fetch_assoc();

if (!$attendance) {
    die("Attendance record not found or access denied.");
}

// Fetch attendance details
$details_sql = "
    SELECT 
        ad.*,
        s.roll_number,
        s.full_name,
        s.email
    FROM attendance_details ad
    JOIN students s ON ad.student_id = s.student_id
    WHERE ad.attendance_id = ?
    ORDER BY s.roll_number
";

$details_stmt = $conn->prepare($details_sql);
$details_stmt->bind_param("i", $attendance_id);
$details_stmt->execute();
$details = $details_stmt->get_result();

$page_title = "Attendance Details";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0">
                    <i class="fas fa-clipboard-list me-2"></i>
                    Attendance Details
                </h4>
            </div>
            <div class="card-body">
                <!-- Attendance Summary -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card text-white bg-success">
                            <div class="card-body text-center">
                                <h2><?= $attendance['total_present'] ?></h2>
                                <p class="mb-0">Present</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-danger">
                            <div class="card-body text-center">
                                <h2><?= $attendance['total_absent'] ?></h2>
                                <p class="mb-0">Absent</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card text-white bg-info">
                            <div class="card-body text-center">
                                <h2><?= $attendance['total_present'] + $attendance['total_absent'] ?></h2>
                                <p class="mb-0">Total</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Details -->
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-bordered">
                            <tr>
                                <th>Course:</th>
                                <td><?= htmlspecialchars($attendance['course_name']) ?> (<?= htmlspecialchars($attendance['course_code']) ?>)</td>
                            </tr>
                            <tr>
                                <th>Date:</th>
                                <td><?= date('F j, Y', strtotime($attendance['attendance_date'])) ?></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="badge bg-<?= $attendance['status'] == 'marked' ? 'success' : 'warning' ?>">
                                        <?= ucfirst($attendance['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Marked At:</th>
                                <td><?= date('F j, Y h:i A', strtotime($attendance['created_at'])) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Students List -->
                <h5 class="mt-4">Student Attendance</h5>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Roll No.</th>
                                <th>Student Name</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($detail = $details->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($detail['roll_number']) ?></td>
                                    <td><?= htmlspecialchars($detail['full_name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $detail['status'] == 'present' ? 'success' : 'danger' ?>">
                                            <?= ucfirst($detail['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($detail['remarks'] ?? '') ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4">
                    <a href="attendance_reports.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Reports
                    </a>
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>