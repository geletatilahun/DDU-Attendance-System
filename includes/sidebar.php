<?php
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
?>
<!-- Sidebar -->
<div class="sidebar">
    <div class="logo text-center py-4">
        <h4><i class="fas fa-university"></i> DDU Teacher</h4>
        <small>Attendance System</small>
    </div>
    
    <nav class="nav flex-column px-3">
        <a href="dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>">
            <i class="fas fa-tachometer-alt me-2"></i> Dashboard
        </a>
        <a href="courses.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'courses.php' ? 'active' : '' ?>">
            <i class="fas fa-book me-2"></i> My Courses
        </a>
        <a href="mark_attendance.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'mark_attendance.php' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-check me-2"></i> Mark Attendance
        </a>
        <a href="attendance_reports.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'attendance_reports.php' ? 'active' : '' ?>">
            <i class="fas fa-chart-bar me-2"></i> Attendance Reports
        </a>
        <a href="students.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : '' ?>">
            <i class="fas fa-user-graduate me-2"></i> Students
        </a>
        <a href="schedule.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'schedule.php' ? 'active' : '' ?>">
            <i class="fas fa-calendar-alt me-2"></i> Schedule
        </a>
        <a href="profile.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : '' ?>">
            <i class="fas fa-user me-2"></i> Profile
        </a>
    </nav>
    
    <div class="mt-auto px-3 py-4">
        <div class="user-info text-center">
            <small>Logged in as:</small>
            <h6><?= htmlspecialchars($_SESSION['full_name'] ?? 'Teacher') ?></h6>
            <small class="text-muted">Teacher</small>
        </div>
        <a href="../logout.php" class="btn btn-danger btn-sm mt-3 w-100">
            <i class="fas fa-sign-out-alt me-1"></i> Logout
        </a>
    </div>
</div>