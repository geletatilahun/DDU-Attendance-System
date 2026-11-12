<?php
session_start();
include('includes/db_connect.php');

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dambi Dollo University - Attendance Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ddu-blue: #003366;
            --ddu-gold: #FFD700;
            --ddu-green: #008000;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .hero-section {
            background: linear-gradient(rgba(0, 51, 102, 0.9), rgba(0, 51, 102, 0.8)), url('assets/images/ddu-campus.jpg');
            background-size: cover;
            background-position: center;
            color: white;
            padding: 100px 0;
            border-radius: 0 0 50px 50px;
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo {
            max-width: 120px;
            margin-bottom: 20px;
        }
        
        .feature-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            margin-bottom: 20px;
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
        }
        
        .feature-icon {
            font-size: 3rem;
            color: var(--ddu-blue);
            margin-bottom: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            padding: 40px;
            margin-top: -100px;
        }
        
        .btn-ddu {
            background: var(--ddu-blue);
            color: white;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-ddu:hover {
            background: var(--ddu-gold);
            color: var(--ddu-blue);
            transform: translateY(-2px);
        }
        
        .footer {
            background: var(--ddu-blue);
            color: white;
            padding: 30px 0;
            margin-top: 100px;
        }
        
        .stats-counter {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--ddu-blue);
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="logo-container">
                <img src="assets/images/bhu_logo.png" alt="DDU Logo" class="logo">
                <h1 class="display-4 fw-bold">Bule Hora University</h1>
                <h2 class="h3">Attendance Management System</h2>
                <p class="lead">Digitalizing attendance tracking for academic excellence</p>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <div class="container">
        <div class="row">
            <!-- Login Section -->
            <div class="col-lg-5 mx-auto">
                <div class="login-container">
                    <h3 class="text-center mb-4" style="color: var(--ddu-blue);">
                        <i class="fas fa-sign-in-alt"></i> Login to System
                    </h3>
                    
                    <?php if(isset($_GET['error'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
                    <?php endif; ?>
                    
                    <?php if(isset($_GET['success'])): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
                    <?php endif; ?>
                    
                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username / Student ID</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-user"></i></span>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                            <a href="forgot_password.php" class="float-end">Forgot Password?</a>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-ddu btn-lg">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </button>
                        </div>
                    </form>
                    
                    <hr class="my-4">
                    
                    <div class="text-center">
                        <h5>Login As:</h5>
                        <div class="btn-group" role="group">
                            <button class="btn btn-outline-primary" onclick="demoLogin('admin')">Admin</button>
                            <button class="btn btn-outline-success" onclick="demoLogin('teacher')">Teacher</button>
                            <button class="btn btn-outline-info" onclick="demoLogin('student')">Student</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Features Section -->
        <div class="row mt-5">
            <div class="col-12 text-center mb-5">
                <h2 class="display-5 fw-bold" style="color: var(--ddu-blue);">System Features</h2>
                <p class="lead">Comprehensive attendance management for modern education</p>
            </div>
            
            <div class="col-md-4">
                <div class="feature-card card p-4">
                    <div class="text-center">
                        <i class="fas fa-qrcode feature-icon"></i>
                        <h4>QR Code Attendance</h4>
                        <p>Quick attendance marking using QR codes for each session</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="feature-card card p-4">
                    <div class="text-center">
                        <i class="fas fa-chart-line feature-icon"></i>
                        <h4>Real-time Analytics</h4>
                        <p>Monitor attendance patterns and generate instant reports</p>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="feature-card card p-4">
                    <div class="text-center">
                        <i class="fas fa-mobile-alt feature-icon"></i>
                        <h4>Mobile Friendly</h4>
                        <p>Access system from any device - desktop, tablet, or mobile</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Counter -->
        <div class="row mt-5 bg-white py-4 rounded shadow">
            <div class="col-md-3 text-center">
                <div class="stats-counter">1,500+</div>
                <p>Active Students</p>
            </div>
            <div class="col-md-3 text-center">
                <div class="stats-counter">120+</div>
                <p>Faculty Members</p>
            </div>
            <div class="col-md-3 text-center">
                <div class="stats-counter">85+</div>
                <p>Courses</p>
            </div>
            <div class="col-md-3 text-center">
                <div class="stats-counter">15,000+</div>
                <p>Attendance Records</p>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5>Bule Hora University</h5>
                    <p>P.O. Box 000, Bule Hora, Ethiopia</p>
                    <p>Email: info@bhu.edu.et | Phone: +251 00 000 0001</p>
                </div>
                <div class="col-md-6 text-end">
                    <h5>Attendance Management System</h5>
                    <p>Version 2.0 | © 2026 BHU</p>
                    <p>Developed by IT Student</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function demoLogin(role) {
            const credentials = {
                'admin': {username: 'admin', password: 'admin123'},
                'teacher': {username: 'teacher01', password: 'teacher123'},
                'student': {username: 'DDU/CS/001/14', password: 'student123'}
            };
            
            document.getElementById('username').value = credentials[role].username;
            document.getElementById('password').value = credentials[role].password;
        }
    </script>
</body>
</html>