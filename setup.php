<?php
session_start();

// Check if already installed
if (file_exists('includes/db_connect.php')) {
    $config = file_get_contents('includes/db_connect.php');
    if (strpos($config, 'define(\'DB_NAME\'') !== false) {
        header('Location: index.php?message=System already installed');
        exit;
    }
}

$step = $_GET['step'] ?? 1;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Database configuration
        $db_host = $_POST['db_host'] ?? 'localhost';
        $db_name = $_POST['db_name'] ?? 'ddu_attendance_system';
        $db_user = $_POST['db_user'] ?? 'root';
        $db_pass = $_POST['db_pass'] ?? '';
        
        // Test connection
        try {
            $test_conn = new mysqli($db_host, $db_user, $db_pass);
            if ($test_conn->connect_error) {
                $errors[] = "Database connection failed: " . $test_conn->connect_error;
            } else {
                // Create database if not exists
                $test_conn->query("CREATE DATABASE IF NOT EXISTS $db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $test_conn->select_db($db_name);
                
                // Save configuration
                $config_content = "<?php
// Database configuration
define('DB_HOST', '$db_host');
define('DB_USER', '$db_user');
define('DB_PASS', '$db_pass');
define('DB_NAME', '$db_name');

// Create connection
\$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if (\$conn->connect_error) {
    die(\"Connection failed: \" . \$conn->connect_error);
}

// Set charset to UTF-8
\$conn->set_charset(\"utf8mb4\");

// Set timezone for Ethiopia
date_default_timezone_set('Africa/Addis_Ababa');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>";

                file_put_contents('includes/db_connect.php', $config_content);
                $step = 2;
            }
        } catch (Exception $e) {
            $errors[] = "Error: " . $e->getMessage();
        }
    } elseif ($step == 2) {
        // Admin account setup
        $admin_username = $_POST['admin_username'] ?? 'admin';
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_email = $_POST['admin_email'] ?? 'admin@ddu.edu.et';
        $admin_name = $_POST['admin_name'] ?? 'System Administrator';
        
        if (empty($admin_password)) {
            $errors[] = "Admin password is required";
        } else {
            include('includes/db_connect.php');
            
            // Run installation SQL
            $sql_file = file_get_contents('install.sql');
            $queries = explode(';', $sql_file);
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    $conn->query($query);
                }
            }
            
            // Create admin user
            $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (?, ?, ?, ?, 'admin', 1)");
            $stmt->bind_param("ssss", $admin_username, $hashed_password, $admin_name, $admin_email);
            $stmt->execute();
            
            $step = 3;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DDU Attendance System - Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .setup-container {
            max-width: 800px;
            margin: 50px auto;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            text-align: center;
            flex: 1;
            padding: 10px;
        }
        .step.active {
            background: #007bff;
            color: white;
            border-radius: 5px;
        }
        .step-number {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="setup-container">
        <div class="card shadow">
            <div class="card-header bg-primary text-white text-center">
                <h3>DDU Attendance System Installation</h3>
            </div>
            
            <!-- Step Indicator -->
            <div class="step-indicator px-4 pt-4">
                <div class="step <?= $step >= 1 ? 'active' : '' ?>">
                    <div class="step-number">1</div>
                    <div>Database</div>
                </div>
                <div class="step <?= $step >= 2 ? 'active' : '' ?>">
                    <div class="step-number">2</div>
                    <div>Admin Account</div>
                </div>
                <div class="step <?= $step >= 3 ? 'active' : '' ?>">
                    <div class="step-number">3</div>
                    <div>Complete</div>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if ($step == 1): ?>
                <!-- Step 1: Database Configuration -->
                <form method="POST">
                    <h4>Database Configuration</h4>
                    <p class="text-muted">Enter your database connection details</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Database Host</label>
                        <input type="text" class="form-control" name="db_host" value="localhost" required>
                        <small class="text-muted">Usually 'localhost'</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Database Name</label>
                        <input type="text" class="form-control" name="db_name" value="ddu_attendance_system" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Database Username</label>
                        <input type="text" class="form-control" name="db_user" value="root" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Database Password</label>
                        <input type="password" class="form-control" name="db_pass">
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">Continue →</button>
                    </div>
                </form>
                
                <?php elseif ($step == 2): ?>
                <!-- Step 2: Admin Account Setup -->
                <form method="POST">
                    <h4>Admin Account Setup</h4>
                    <p class="text-muted">Create the administrator account</p>
                    
                    <div class="mb-3">
                        <label class="form-label">Admin Username</label>
                        <input type="text" class="form-control" name="admin_username" value="admin" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Admin Password</label>
                        <input type="password" class="form-control" name="admin_password" required>
                        <small class="text-muted">Minimum 8 characters with uppercase, lowercase, number and special character</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Admin Full Name</label>
                        <input type="text" class="form-control" name="admin_name" value="System Administrator" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Admin Email</label>
                        <input type="email" class="form-control" name="admin_email" value="admin@ddu.edu.et" required>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">Continue →</button>
                    </div>
                </form>
                
                <?php elseif ($step == 3): ?>
                <!-- Step 3: Installation Complete -->
                <div class="text-center py-4">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h3 class="text-success">Installation Complete!</h3>
                    <p class="text-muted">DDU Attendance System has been successfully installed.</p>
                    
                    <div class="alert alert-info mt-4">
                        <h5>Important Information:</h5>
                        <ul class="text-start">
                            <li>Default Admin Login: admin / [your password]</li>
                            <li>Change default passwords immediately</li>
                            <li>Configure email settings in Admin Panel</li>
                            <li>Regularly backup your database</li>
                        </ul>
                    </div>
                    
                    <div class="mt-4">
                        <a href="login.php" class="btn btn-success btn-lg">
                            <i class="fas fa-sign-in-alt"></i> Go to Login Page
                        </a>
                        <a href="admin/dashboard.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-tachometer-alt"></i> Go to Admin Panel
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>
</html>