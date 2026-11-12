<?php
/**
 * Admin Sidebar Navigation
 * Navigation menu for the admin panel
 */
?>
<!-- Sidebar -->
<ul class="navbar-nav bg-gradient-primary sidebar sidebar-dark accordion" id="accordionSidebar">

    <!-- Sidebar - Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="index.php">
        <div class="sidebar-brand-icon rotate-n-15">
            <i class="fas fa-university"></i>
        </div>
        <div class="sidebar-brand-text mx-3">DDU Admin</div>
    </a>

    <!-- Divider -->
    <hr class="sidebar-divider my-0">

    <!-- Nav Item - Dashboard -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>">
        <a class="nav-link" href="index.php">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        User Management
    </div>

    <!-- Nav Item - Users -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'manage_users.php' ? 'active' : '' ?>">
        <a class="nav-link" href="manage_users.php">
            <i class="fas fa-fw fa-users"></i>
            <span>Manage Users</span>
        </a>
    </li>

    <!-- Nav Item - Students -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'students.php' ? 'active' : '' ?>">
        <a class="nav-link" href="students.php">
            <i class="fas fa-fw fa-user-graduate"></i>
            <span>Students</span>
        </a>
    </li>

    <!-- Nav Item - Teachers -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'teachers.php' ? 'active' : '' ?>">
        <a class="nav-link" href="teachers.php">
            <i class="fas fa-fw fa-chalkboard-teacher"></i>
            <span>Teachers</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Attendance Management
    </div>

    <!-- Nav Item - Attendance -->
    <li class="nav-item <?= in_array(basename($_SERVER['PHP_SELF']), ['attendance.php', 'take_attendance.php', 'view_attendance.php']) ? 'active' : '' ?>">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseAttendance" 
           aria-expanded="true" aria-controls="collapseAttendance">
            <i class="fas fa-fw fa-calendar-check"></i>
            <span>Attendance</span>
        </a>
        <div id="collapseAttendance" class="collapse <?= in_array(basename($_SERVER['PHP_SELF']), ['attendance.php', 'take_attendance.php', 'view_attendance.php']) ? 'show' : '' ?>" 
             aria-labelledby="headingAttendance" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <h6 class="collapse-header">Attendance Options:</h6>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'take_attendance.php' ? 'active' : '' ?>" 
                   href="take_attendance.php">Take Attendance</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'view_attendance.php' ? 'active' : '' ?>" 
                   href="view_attendance.php">View Attendance</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'attendance_reports.php' ? 'active' : '' ?>" 
                   href="attendance_reports.php">Reports</a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Courses -->
    <li class="nav-item <?= in_array(basename($_SERVER['PHP_SELF']), ['courses.php', 'course_management.php']) ? 'active' : '' ?>">
        <a class="nav-link" href="courses.php">
            <i class="fas fa-fw fa-book"></i>
            <span>Courses</span>
        </a>
    </li>

    <!-- Nav Item - Departments -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'departments.php' ? 'active' : '' ?>">
        <a class="nav-link" href="departments.php">
            <i class="fas fa-fw fa-building"></i>
            <span>Departments</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        Reports & Analytics
    </div>

    <!-- Nav Item - Reports -->
    <li class="nav-item <?= in_array(basename($_SERVER['PHP_SELF']), ['reports.php', 'analytics.php']) ? 'active' : '' ?>">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseReports" 
           aria-expanded="true" aria-controls="collapseReports">
            <i class="fas fa-fw fa-chart-bar"></i>
            <span>Reports</span>
        </a>
        <div id="collapseReports" class="collapse <?= in_array(basename($_SERVER['PHP_SELF']), ['reports.php', 'analytics.php']) ? 'show' : '' ?>" 
             aria-labelledby="headingReports" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <h6 class="collapse-header">Report Types:</h6>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'daily_reports.php' ? 'active' : '' ?>" 
                   href="daily_reports.php">Daily Reports</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'weekly_reports.php' ? 'active' : '' ?>" 
                   href="weekly_reports.php">Weekly Reports</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'monthly_reports.php' ? 'active' : '' ?>" 
                   href="monthly_reports.php">Monthly Reports</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'semester_reports.php' ? 'active' : '' ?>" 
                   href="semester_reports.php">Semester Reports</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'analytics.php' ? 'active' : '' ?>" 
                   href="analytics.php">Analytics Dashboard</a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Export -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'export_data.php' ? 'active' : '' ?>">
        <a class="nav-link" href="export_data.php">
            <i class="fas fa-fw fa-file-export"></i>
            <span>Export Data</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider">

    <!-- Heading -->
    <div class="sidebar-heading">
        System Settings
    </div>

    <!-- Nav Item - Settings -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
        <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseSettings" 
           aria-expanded="true" aria-controls="collapseSettings">
            <i class="fas fa-fw fa-cogs"></i>
            <span>Settings</span>
        </a>
        <div id="collapseSettings" class="collapse <?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'show' : '' ?>" 
             aria-labelledby="headingSettings" data-parent="#accordionSidebar">
            <div class="bg-white py-2 collapse-inner rounded">
                <h6 class="collapse-header">Settings Options:</h6>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'system_settings.php' ? 'active' : '' ?>" 
                   href="system_settings.php">System Settings</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'email_settings.php' ? 'active' : '' ?>" 
                   href="email_settings.php">Email Settings</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'api_settings.php' ? 'active' : '' ?>" 
                   href="api_settings.php">API Settings</a>
                <a class="collapse-item <?= basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'active' : '' ?>" 
                   href="backup.php">Backup & Restore</a>
            </div>
        </div>
    </li>

    <!-- Nav Item - Audit Log -->
    <li class="nav-item <?= basename($_SERVER['PHP_SELF']) == 'audit_log.php' ? 'active' : '' ?>">
        <a class="nav-link" href="audit_log.php">
            <i class="fas fa-fw fa-clipboard-list"></i>
            <span>Audit Log</span>
        </a>
    </li>

    <!-- Divider -->
    <hr class="sidebar-divider d-none d-md-block">

    <!-- Sidebar Toggler (Sidebar) -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>

</ul>
<!-- End of Sidebar -->