<!-- sidebar.php -->
<div class="sidebar">
    <div class="logo">
        <h4><i class="fas fa-university"></i> DDU Teacher</h4>
        <small>Attendance System</small>
    </div>
    
    <nav class="nav flex-column mt-3">
        <a href="dashboard.php" class="nav-link">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="courses.php" class="nav-link active">
            <i class="fas fa-book"></i> My Courses
        </a>
        <a href="mark_attendance.php" class="nav-link">
            <i class="fas fa-clipboard-check"></i> Mark Attendance
        </a>
        <a href="attendance_reports.php" class="nav-link">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
        <a href="students.php" class="nav-link">
            <i class="fas fa-user-graduate"></i> Students
        </a>
        <a href="schedule.php" class="nav-link">
            <i class="fas fa-calendar-alt"></i> Schedule
        </a>
        <a href="profile.php" class="nav-link">
            <i class="fas fa-user"></i> Profile
        </a>
        
        <div class="mt-4 px-3">
            <div class="user-info">
                <small>Logged in as:</small>
                <h6><?= htmlspecialchars($teacher['full_name']) ?></h6>
                <small class="text-muted">Teacher | <?= htmlspecialchars($teacher['department_name'] ?? 'Department') ?></small>
            </div>
            <a href="../logout.php" class="btn btn-danger btn-sm mt-3 w-100">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </nav>
</div>