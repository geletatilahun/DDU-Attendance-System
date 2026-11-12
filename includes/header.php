<?php
// includes/header.php

// START SESSION SAFELY
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// PAGE TITLE DEFAULT
if (!isset($page_title)) {
    $page_title = 'DDU Attendance System';
}

// SAFE SESSION VALUES
$user_id   = $_SESSION['user_id']   ?? null;
$username  = $_SESSION['username']  ?? '';
$full_name = $_SESSION['full_name'] ?? 'User';
$role      = $_SESSION['role']      ?? '';

// LOGIN CHECK (NO FUNCTION DEPENDENCY)
$is_logged_in = isset($_SESSION['user_id']);

// ROLE CHECKS (SAFE)
$is_admin   = ($role === 'admin');
$is_teacher = ($role === 'teacher');
$is_student = ($role === 'student');
$is_dean    = ($role === 'dean');

if (!function_exists('display_flash_message')) {
    function display_flash_message() {
        if (!empty($_SESSION['flash_message'])) {
            echo '<div class="alert alert-info alert-dismissible fade show" role="alert">';
            echo htmlspecialchars($_SESSION['flash_message']);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
            echo '</div>';
            unset($_SESSION['flash_message']);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($page_title) ?> - DDU Attendance System</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="../assets/css/style.css" rel="stylesheet">
<link rel="icon" href="../assets/images/favicon.ico">

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
<div class="container-fluid">

<a class="navbar-brand" href="../dashboard.php">
<i class="fas fa-calendar-check"></i> DDU Attendance System
</a>

<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
<span class="navbar-toggler-icon"></span>
</button>

<div class="collapse navbar-collapse" id="navbarNav">

<ul class="navbar-nav me-auto">

<?php if ($is_logged_in): ?>

<li class="nav-item">
<a class="nav-link" href="../dashboard.php">
<i class="fas fa-home"></i> Dashboard
</a>
</li>

<?php if ($is_admin): ?>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
<i class="fas fa-user-shield"></i> Admin
</a>
<ul class="dropdown-menu">
<li><a class="dropdown-item" href="../admin/dashboard.php">Admin Dashboard</a></li>
<li><a class="dropdown-item" href="../admin/manage_users.php">Manage Users</a></li>
<li><a class="dropdown-item" href="../admin/manage_teachers.php">Manage Teachers</a></li>
<li><a class="dropdown-item" href="../admin/manage_students.php">Manage Students</a></li>
<li><a class="dropdown-item" href="../admin/manage_courses.php">Manage Courses</a></li>
</ul>
</li>
<?php endif; ?>

<?php if ($is_teacher): ?>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
<i class="fas fa-chalkboard-teacher"></i> Teacher
</a>
<ul class="dropdown-menu">
<li><a class="dropdown-item" href="../teacher/dashboard.php">Dashboard</a></li>
<li><a class="dropdown-item" href="../teacher/my_courses.php">My Courses</a></li>
<li><a class="dropdown-item" href="../teacher/take_attendance.php">Take Attendance</a></li>
</ul>
</li>
<?php endif; ?>

<?php if ($is_student): ?>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
<i class="fas fa-user-graduate"></i> Student
</a>
<ul class="dropdown-menu">
<li><a class="dropdown-item" href="../student/dashboard.php">Dashboard</a></li>
<li><a class="dropdown-item" href="../student/my_attendance.php">My Attendance</a></li>
</ul>
</li>
<?php endif; ?>

<?php if ($is_dean): ?>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
<i class="fas fa-user-tie"></i> Dean
</a>
<ul class="dropdown-menu">
<li><a class="dropdown-item" href="../dean/dashboard.php">Dashboard</a></li>
</ul>
</li>
<?php endif; ?>

<?php endif; ?>
</ul>

<ul class="navbar-nav">
<?php if ($is_logged_in): ?>
<li class="nav-item dropdown">
<a class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
<i class="fas fa-user-circle"></i> <?= htmlspecialchars($full_name) ?>
</a>
<ul class="dropdown-menu dropdown-menu-end">
<li><a class="dropdown-item" href="../profile.php">Profile</a></li>
<li><a class="dropdown-item" href="../logout.php">Logout</a></li>
</ul>
</li>
<?php else: ?>
<li class="nav-item">
<a class="nav-link" href="../login.php">Login</a>
</li>
<?php endif; ?>
</ul>

</div>
</div>
</nav>

<div class="container-fluid mt-4">
<?php display_flash_message(); ?>
