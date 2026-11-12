<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

// Check admin role
checkRole(['admin']);

// Get statistics
$stats = [];

// Total students
$result = $conn->query("SELECT COUNT(*) as total FROM students WHERE is_active = 1");
$stats['total_students'] = $result->fetch_assoc()['total'];

// Total teachers
$result = $conn->query("SELECT COUNT(*) as total FROM users WHERE role = 'teacher' AND is_active = 1");
$stats['total_teachers'] = $result->fetch_assoc()['total'];

// Total courses
$result = $conn->query("SELECT COUNT(*) as total FROM courses WHERE is_active = 1");
$stats['total_courses'] = $result->fetch_assoc()['total'];

// Today's attendance
$today = date('Y-m-d');
$result = $conn->query("SELECT COUNT(*) as total FROM attendance WHERE attendance_date = '$today'");
$stats['today_attendance'] = $result->fetch_assoc()['total'];

// Departments
$result = $conn->query("SELECT COUNT(*) as total FROM departments");
$stats['total_departments'] = $result->fetch_assoc()['total'];

// Recent activities
$activities = $conn->query("
    SELECT 'New Student' as type, full_name, created_at 
    FROM students 
    WHERE DATE(created_at) = CURDATE()
    UNION
    SELECT 'Attendance Marked' as type, CONCAT('Course: ', c.course_name) as full_name, a.marked_at as created_at
    FROM attendance a
    JOIN courses c ON a.course_id = c.course_id
    WHERE DATE(a.marked_at) = CURDATE()
    LIMIT 10
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - DDU Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-green: #28a745;
            --ddu-red: #dc3545;
            --ddu-orange: #fd7e14;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .sidebar {
            background: var(--ddu-blue);
            color: white;
            min-height: 100vh;
            position: fixed;
            width: 250px;
            transition: all 0.3s;
            z-index: 1000;
        }
        
        .sidebar .logo {
            padding: 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 15px 20px;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--ddu-gold);
        }
        
        .sidebar .nav-link i {
            width: 25px;
            margin-right: 10px;
        }
        
        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        .navbar-top {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 15px 20px;
            margin: -20px -20px 20px -20px;
        }
        
        .stat-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        
        .bg-student { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-teacher { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .bg-course { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .bg-attendance { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .bg-department { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--ddu-red);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                margin-left: -250px;
            }
            .main-content {
                margin-left: 0;
            }
            .sidebar.active {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo">
            <h4><i class="fas fa-university"></i> DDU Admin</h4>
            <small>Attendance System</small>
        </div>
        
        <nav class="nav flex-column mt-3">
            <a href="dashboard.php" class="nav-link active">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a href="manage_users.php" class="nav-link">
                <i class="fas fa-users"></i> Manage Users
            </a>
            <a href="manage_students.php" class="nav-link">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <a href="manage_teachers.php" class="nav-link">
                <i class="fas fa-chalkboard-teacher"></i> Teachers
            </a>
            <a href="manage_courses.php" class="nav-link">
                <i class="fas fa-book"></i> Courses
            </a>
            <a href="manage_departments.php" class="nav-link">
                <i class="fas fa-building"></i> Departments
            </a>
            <a href="attendance_reports.php" class="nav-link">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="settings.php" class="nav-link">
                <i class="fas fa-cog"></i> Settings
            </a>
            
            <div class="mt-4 px-3">
                <div class="user-info">
                    <small>Logged in as:</small>
                    <h6><?= htmlspecialchars($_SESSION['full_name']) ?></h6>
                    <small class="text-muted"><?= ucfirst($_SESSION['role']) ?></small>
                </div>
                <a href="../logout.php" class="btn btn-danger btn-sm mt-3 w-100">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Navbar -->
        <div class="navbar-top d-flex justify-content-between align-items-center">
            <div>
                <h4 class="mb-0" style="color: var(--ddu-blue);">
                    <i class="fas fa-tachometer-alt"></i> Admin Dashboard
                </h4>
                <small class="text-muted">Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?></small>
            </div>
            
            <div class="d-flex align-items-center">
                <div class="dropdown me-3">
                    <a href="#" class="position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell fs-4" style="color: var(--ddu-blue);"></i>
                        <span class="notification-badge">3</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">Notifications</h6>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-user-plus text-success"></i> 5 new students registered
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-exclamation-triangle text-warning"></i> 3 students below threshold
                        </a>
                        <a class="dropdown-item" href="#">
                            <i class="fas fa-clock text-info"></i> 10 pending leave requests
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center" href="notifications.php">View All</a>
                    </div>
                </div>
                
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-decoration-none" data-bs-toggle="dropdown">
                        <img src="<?= !empty($_SESSION['profile_image']) ? '../uploads/profiles/' . $_SESSION['profile_image'] : 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['full_name']) . '&background=003366&color=fff' ?>" 
                             class="rounded-circle" width="40" height="40">
                        <div class="ms-2 d-none d-md-block">
                            <div class="fw-bold"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
                            <small class="text-muted">Admin</small>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end">
                        <a class="dropdown-item" href="profile.php">
                            <i class="fas fa-user"></i> Profile
                        </a>
                        <a class="dropdown-item" href="settings.php">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="../logout.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Statistics Cards -->
        <div class="row mt-4">
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card text-white bg-student">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_students']) ?></h2>
                                <p class="mb-0">Total Students</p>
                            </div>
                            <i class="fas fa-user-graduate stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card text-white bg-teacher">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_teachers']) ?></h2>
                                <p class="mb-0">Teachers</p>
                            </div>
                            <i class="fas fa-chalkboard-teacher stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card text-white bg-course">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_courses']) ?></h2>
                                <p class="mb-0">Active Courses</p>
                            </div>
                            <i class="fas fa-book stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card text-white bg-attendance">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['today_attendance']) ?></h2>
                                <p class="mb-0">Today's Attendance</p>
                            </div>
                            <i class="fas fa-clipboard-check stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card text-white bg-department">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0"><?= number_format($stats['total_departments']) ?></h2>
                                <p class="mb-0">Departments</p>
                            </div>
                            <i class="fas fa-building stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-xl-2 col-md-4 col-sm-6">
                <div class="card stat-card text-white" style="background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); color: #333;">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="mb-0">95%</h2>
                                <p class="mb-0">System Uptime</p>
                            </div>
                            <i class="fas fa-server stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Charts and Tables -->
        <div class="row mt-4">
            <div class="col-lg-8">
                <div class="chart-container">
                    <h5>Attendance Overview (Last 7 Days)</h5>
                    <canvas id="attendanceChart" height="250"></canvas>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="chart-container">
                    <h5>Department Distribution</h5>
                    <canvas id="departmentChart" height="250"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Recent Activities -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <h5>Recent Activities</h5>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Description</th>
                                    <th>Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($activity = $activities->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary"><?= $activity['type'] ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($activity['full_name']) ?></td>
                                    <td><?= date('h:i A', strtotime($activity['created_at'])) ?></td>
                                    <td>
                                           <a href="view_student.php?action=view">
                                            <button class="btn btn-info btn-sm" onclick="viewStudent(<?= $row['student_id'] ?>)">👁</button>
                                           </a> 
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <h5>Quick Actions</h5>
                    <div class="row">
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="manage_students.php?action=add" class="btn btn-primary w-100">
                                <i class="fas fa-user-plus"></i><br>Add Student
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="manage_teachers.php?action=add" class="btn btn-success w-100">
                                <i class="fas fa-chalkboard-teacher"></i><br>Add Teacher
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="manage_courses.php?action=add" class="btn btn-info w-100">
                                <i class="fas fa-book"></i><br>Add Course
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="reports.php" class="btn btn-warning w-100">
                                <i class="fas fa-chart-pie"></i><br>Generate Report
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="settings.php" class="btn btn-dark w-100">
                                <i class="fas fa-cog"></i><br>System Settings
                            </a>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3">
                            <a href="../backup.php" class="btn btn-secondary w-100">
                                <i class="fas fa-database"></i><br>Backup Data
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Attendance Chart
        const ctx1 = document.getElementById('attendanceChart').getContext('2d');
        const attendanceChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Present',
                    data: [120, 115, 125, 130, 110, 90, 95],
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4
                }, {
                    label: 'Absent',
                    data: [15, 20, 10, 5, 25, 35, 30],
                    borderColor: '#dc3545',
                    backgroundColor: 'rgba(220, 53, 69, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
        
        // Department Chart
        const ctx2 = document.getElementById('departmentChart').getContext('2d');
        const departmentChart = new Chart(ctx2, {
            type: 'doughnut',
            data: {
                labels: ['Computer Science', 'Software Eng', 'IT', 'Electrical', 'Business'],
                datasets: [{
                    data: [300, 250, 200, 180, 220],
                    backgroundColor: [
                        '#003366',
                        '#0066cc',
                        '#4facfe',
                        '#667eea',
                        '#764ba2'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
        
        // Toggle sidebar on mobile
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('active');
        }
    </script>
</body>
</html>