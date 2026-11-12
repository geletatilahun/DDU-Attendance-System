<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

// Debug: Show errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!is_admin()) {
    header('Location: /ddu_attendance/login.php');
    exit();
}

// Get current admin user data
$user_id = $_SESSION['user_id'];

// First, let's check what's in the session
echo "<!-- Debug: Session user_id = " . $user_id . " -->";

// Check if users table exists
$table_check = $conn->query("SHOW TABLES LIKE 'users'");
if ($table_check->num_rows == 0) {
    die("Error: 'users' table doesn't exist in the database.");
}

// Describe the table to see its structure
$desc_result = $conn->query("DESCRIBE users");
echo "<!-- Debug: Users table structure: -->";
while ($row = $desc_result->fetch_assoc()) {
    echo "<!-- " . $row['Field'] . " (" . $row['Type'] . ") -->";
}

// Prepare the query with error checking
$sql = "SELECT * FROM users WHERE user_id = ?";
echo "<!-- Debug: SQL = " . $sql . " -->";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Prepare failed: " . $conn->error . " <!-- SQL: " . $sql . " -->");
}

// Check if bind_param works
if (!$stmt->bind_param("i", $user_id)) {
    die("Bind param failed: " . $stmt->error);
}

// Execute the query
if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

// Get result
$result = $stmt->get_result();
if ($result === false) {
    die("Get result failed: " . $stmt->error);
}

// Check if we got a user
if ($result->num_rows == 0) {
    die("Error: No user found with ID " . $user_id);
}

$user = $result->fetch_assoc();
echo "<!-- Debug: User found: " . $user['full_name'] . " -->";

// Handle form submission
$message = '';
$message_type = ''; // success or danger

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate input
    $errors = [];
    
    if (empty($full_name)) {
        $errors[] = "Full name is required";
    }
    
    if (empty($email)) {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Check if email already exists (excluding current user)
    $check_email_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
    $check_stmt = $conn->prepare($check_email_sql);
    if ($check_stmt === false) {
        $errors[] = "Database error: " . $conn->error;
    } else {
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $errors[] = "Email already exists";
        }
        $check_stmt->close();
    }
    
    // Handle password change if provided
    if (!empty($new_password)) {
        if (empty($current_password)) {
            $errors[] = "Current password is required to set new password";
        } elseif (!password_verify($current_password, $user['password'])) {
            $errors[] = "Current password is incorrect";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters long";
        } elseif ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }
    }
    
    // If no errors, update profile
    if (empty($errors)) {
        // Prepare update query
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET full_name = ?, email = ?, password = ?, updated_at = NOW() WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt === false) {
                $message = "Database error: " . $conn->error;
                $message_type = "danger";
            } else {
                $update_stmt->bind_param("sssi", $full_name, $email, $hashed_password, $user_id);
                if ($update_stmt->execute()) {
                    $_SESSION['user_fullname'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    $message = "Profile updated successfully!";
                    $message_type = "success";
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                } else {
                    $message = "Failed to update profile: " . $update_stmt->error;
                    $message_type = "danger";
                }
                $update_stmt->close();
            }
        } else {
            $update_sql = "UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt === false) {
                $message = "Database error: " . $conn->error;
                $message_type = "danger";
            } else {
                $update_stmt->bind_param("ssi", $full_name, $email, $user_id);
                if ($update_stmt->execute()) {
                    $_SESSION['user_fullname'] = $full_name;
                    $_SESSION['user_email'] = $email;
                    $message = "Profile updated successfully!";
                    $message_type = "success";
                    $user['full_name'] = $full_name;
                    $user['email'] = $email;
                } else {
                    $message = "Failed to update profile: " . $update_stmt->error;
                    $message_type = "danger";
                }
                $update_stmt->close();
            }
        }
    } else {
        $message = implode("<br>", $errors);
        $message_type = "danger";
    }
}

$page_title = "My Profile";
// Temporarily remove header include to see debug output
// include '../includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - DDU Attendance</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        body {
            background-color: #f8f9fa;
        }
        .profile-pic-placeholder {
            width: 150px;
            height: 150px;
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: bold;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .profile-pic-placeholder:hover {
            transform: scale(1.05);
            opacity: 0.9;
        }
        .card {
            border: none;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e3e6f0;
            font-weight: 700;
            color: #4e73df;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-university me-2"></i>DDU Attendance Admin
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="profile.php"><i class="fas fa-user"></i> Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4">My Profile</h1>
            
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Profile</li>
                </ol>
            </nav>
            
            <!-- Message Alert -->
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Profile Information Card -->
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <!-- Profile Picture -->
                            <div class="mb-3">
                                <div class="profile-pic-wrapper mx-auto">
                                    <div class="profile-pic-placeholder rounded-circle">
                                        <?php 
                                        // Display initials
                                        $names = explode(' ', $user['full_name']);
                                        $initials = '';
                                        foreach ($names as $name) {
                                            $initials .= strtoupper(substr($name, 0, 1));
                                            if (strlen($initials) >= 2) break;
                                        }
                                        echo $initials ?: 'U';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- User Info -->
                            <h4 class="card-title"><?php echo htmlspecialchars($user['full_name']); ?></h4>
                            <p class="text-muted"><?php echo ucfirst($user['role'] ?? 'admin'); ?></p>
                            
                            <hr>
                            
                            <!-- Additional Info -->
                            <div class="text-start">
                                <p><strong><i class="fas fa-envelope me-2"></i>Email:</strong><br>
                                <?php echo htmlspecialchars($user['email']); ?></p>
                                
                                <p><strong><i class="fas fa-calendar-alt me-2"></i>Member Since:</strong><br>
                                <?php echo date('F d, Y', strtotime($user['created_at'] ?? date('Y-m-d'))); ?></p>
                                
                                <?php if (!empty($user['last_login'])): ?>
                                <p><strong><i class="fas fa-clock me-2"></i>Last Login:</strong><br>
                                <?php echo date('F d, Y H:i', strtotime($user['last_login'])); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Edit Form -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-user-edit me-2"></i>Edit Profile
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Full Name *</label>
                                        <input type="text" class="form-control" name="full_name" 
                                               value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Email *</label>
                                        <input type="email" class="form-control" name="email" 
                                               value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                    </div>
                                </div>
                                
                                <!-- Role and Status (Read-only) -->
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Role</label>
                                        <input type="text" class="form-control" value="<?php echo ucfirst($user['role'] ?? 'admin'); ?>" readonly>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Status</label>
                                        <div>
                                            <span class="badge bg-<?php echo ($user['status'] ?? 'active') == 'active' ? 'success' : 'danger'; ?>">
                                                <?php echo ucfirst($user['status'] ?? 'active'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Password Change Section -->
                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0">Change Password</h6>
                                        <small class="text-muted">Leave blank if you don't want to change password</small>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-12 mb-3">
                                                <label class="form-label">Current Password</label>
                                                <input type="password" class="form-control" name="current_password" 
                                                       placeholder="Enter current password to change password">
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">New Password</label>
                                                <input type="password" class="form-control" name="new_password" 
                                                       placeholder="At least 6 characters">
                                            </div>
                                            
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label">Confirm New Password</label>
                                                <input type="password" class="form-control" name="confirm_password" 
                                                       placeholder="Confirm new password">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Form Actions -->
                                <div class="d-flex justify-content-between">
                                    <a href="dashboard.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Update Profile
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Simple profile picture click handler
    document.querySelector('.profile-pic-placeholder').addEventListener('click', function() {
        alert('Profile picture upload feature will be added soon!');
    });
});
</script>

</body>
</html>