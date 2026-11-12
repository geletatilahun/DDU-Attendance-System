<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

if (!is_admin()) {
    header('Location: /ddu_attendance/login.php');
    exit();
}

$page_title = "System Settings";
include '../includes/header.php';

// Initialize variables
$success = '';
$error = '';

// Get all settings from database
$settings = [];
$result = $conn->query("SELECT `key`, `value` FROM settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_type = $_POST['form_type'] ?? '';
    $updated = false;
    
    try {
        $conn->begin_transaction();
        
        switch ($form_type) {
            case 'general':
                $fields = ['system_name', 'institute_name', 'institute_address', 'contact_email', 'contact_phone', 'timezone', 'date_format'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        updateSetting($conn, $field, trim($_POST[$field]));
                        $updated = true;
                    }
                }
                break;
                
            case 'attendance':
                $fields = ['min_attendance_percentage', 'default_attendance_status', 'late_attendance_minutes', 'auto_absent_hours', 'enable_qr_attendance', 'enable_biometric'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        $value = ($field === 'enable_qr_attendance' || $field === 'enable_biometric') 
                            ? (isset($_POST[$field]) ? '1' : '0') 
                            : trim($_POST[$field]);
                        updateSetting($conn, $field, $value);
                        $updated = true;
                    }
                }
                break;
                
            case 'email':
                $fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'from_email', 'from_name', 'smtp_ssl'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        $value = ($field === 'smtp_ssl') 
                            ? (isset($_POST[$field]) ? '1' : '0') 
                            : trim($_POST[$field]);
                        
                        // Only update password if not empty
                        if ($field === 'smtp_password' && empty($value)) {
                            continue;
                        }
                        
                        updateSetting($conn, $field, $value);
                        $updated = true;
                    }
                }
                break;
                
            case 'security':
                $fields = ['session_timeout', 'max_login_attempts', 'lockout_duration', 'password_expiry_days', 'force_password_change', 'enable_2fa', 'enable_ip_whitelist'];
                foreach ($fields as $field) {
                    if (isset($_POST[$field])) {
                        $value = ($field === 'force_password_change' || $field === 'enable_2fa' || $field === 'enable_ip_whitelist') 
                            ? (isset($_POST[$field]) ? '1' : '0') 
                            : trim($_POST[$field]);
                        updateSetting($conn, $field, $value);
                        $updated = true;
                    }
                }
                break;
        }
        
        $conn->commit();
        
        if ($updated) {
            // Refresh settings array
            $settings = [];
            $result = $conn->query("SELECT `key`, `value` FROM settings");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $settings[$row['key']] = $row['value'];
                }
            }
            $success = "Settings updated successfully!";
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error updating settings: " . $e->getMessage();
    }
}

// Helper function to update setting
function updateSetting($conn, $key, $value) {
    $stmt = $conn->prepare("
        INSERT INTO settings (`key`, `value`) 
        VALUES (?, ?) 
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
    ");
    $stmt->bind_param("ss", $key, $value);
    return $stmt->execute();
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4">System Settings</h1>
            
            <!-- Messages -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Settings Tabs -->
            <ul class="nav nav-tabs mb-4" id="settingsTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button">
                        <i class="fas fa-cog"></i> General
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button">
                        <i class="fas fa-envelope"></i> Email
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button">
                        <i class="fas fa-shield-alt"></i> Security
                    </button>
                </li>
            </ul>

            <!-- Settings Forms -->
            <div class="tab-content" id="settingsTabContent">
                <!-- General Settings -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-cog"></i> General Settings
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="form_type" value="general">
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">System Name</label>
                                        <input type="text" class="form-control" name="system_name" 
                                               value="<?php echo htmlspecialchars($settings['system_name'] ?? 'DDU Attendance System'); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Institute Name</label>
                                        <input type="text" class="form-control" name="institute_name" 
                                               value="<?php echo htmlspecialchars($settings['institute_name'] ?? 'Dharmsinh Desai University'); ?>">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Institute Address</label>
                                        <textarea class="form-control" name="institute_address" rows="3"><?php echo htmlspecialchars($settings['institute_address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Contact Email</label>
                                        <input type="email" class="form-control" name="contact_email" 
                                               value="<?php echo htmlspecialchars($settings['contact_email'] ?? ''); ?>">
                                        <label class="form-label mt-3">Contact Phone</label>
                                        <input type="text" class="form-control" name="contact_phone" 
                                               value="<?php echo htmlspecialchars($settings['contact_phone'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Default Timezone</label>
                                        <select class="form-control" name="timezone">
                                            <option value="Asia/Kolkata" <?php echo ($settings['timezone'] ?? 'Asia/Kolkata') == 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata (IST)</option>
                                            <option value="Asia/Dubai" <?php echo ($settings['timezone'] ?? '') == 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai</option>
                                            <option value="America/New_York" <?php echo ($settings['timezone'] ?? '') == 'America/New_York' ? 'selected' : ''; ?>>America/New_York</option>
                                            <option value="Europe/London" <?php echo ($settings['timezone'] ?? '') == 'Europe/London' ? 'selected' : ''; ?>>Europe/London</option>
                                            <option value="UTC" <?php echo ($settings['timezone'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Date Format</label>
                                        <select class="form-control" name="date_format">
                                            <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'Y-m-d') == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                            <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                            <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                            <option value="d-m-Y" <?php echo ($settings['date_format'] ?? '') == 'd-m-Y' ? 'selected' : ''; ?>>DD-MM-YYYY</option>
                                            <option value="F j, Y" <?php echo ($settings['date_format'] ?? '') == 'F j, Y' ? 'selected' : ''; ?>>Month Day, Year</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save General Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Attendance Settings -->
                <div class="tab-pane fade" id="attendance" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-calendar-check"></i> Attendance Settings
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="form_type" value="attendance">
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Minimum Attendance Percentage</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" name="min_attendance_percentage" 
                                                   min="0" max="100" value="<?php echo htmlspecialchars($settings['min_attendance_percentage'] ?? 75); ?>">
                                            <span class="input-group-text">%</span>
                                        </div>
                                        <small class="text-muted">Minimum required attendance percentage for students (0-100)</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Default Attendance Status</label>
                                        <select class="form-control" name="default_attendance_status">
                                            <option value="absent" <?php echo ($settings['default_attendance_status'] ?? 'absent') == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                            <option value="present" <?php echo ($settings['default_attendance_status'] ?? '') == 'present' ? 'selected' : ''; ?>>Present</option>
                                            <option value="leave" <?php echo ($settings['default_attendance_status'] ?? '') == 'leave' ? 'selected' : ''; ?>>Leave</option>
                                        </select>
                                        <small class="text-muted">Default status when marking attendance</small>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Allow Late Attendance (minutes)</label>
                                        <input type="number" class="form-control" name="late_attendance_minutes" 
                                               min="0" value="<?php echo htmlspecialchars($settings['late_attendance_minutes'] ?? 15); ?>">
                                        <small class="text-muted">Allow attendance submission after class start time (0 = disabled)</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Auto-mark Absent After (hours)</label>
                                        <input type="number" class="form-control" name="auto_absent_hours" 
                                               min="0" value="<?php echo htmlspecialchars($settings['auto_absent_hours'] ?? 24); ?>">
                                        <small class="text-muted">Automatically mark absent if not marked by teacher (0 = disabled)</small>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="enable_qr_attendance" 
                                                   id="enable_qr_attendance" <?php echo ($settings['enable_qr_attendance'] ?? '0') == '1' ? 'checked' : ''; ?> value="1">
                                            <label class="form-check-label" for="enable_qr_attendance">
                                                Enable QR Code Attendance
                                            </label>
                                        </div>
                                        <small class="text-muted d-block">Allow attendance marking via QR codes</small>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="enable_biometric" 
                                                   id="enable_biometric" <?php echo ($settings['enable_biometric'] ?? '0') == '1' ? 'checked' : ''; ?> value="1">
                                            <label class="form-check-label" for="enable_biometric">
                                                Enable Biometric Attendance
                                            </label>
                                        </div>
                                        <small class="text-muted d-block">Allow attendance marking via biometric devices</small>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Attendance Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Email Settings -->
                <div class="tab-pane fade" id="email" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-envelope"></i> Email Settings
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="form_type" value="email">
                                
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i> For Gmail, use SMTP Host: smtp.gmail.com, Port: 587, Enable SSL
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">SMTP Host</label>
                                        <input type="text" class="form-control" name="smtp_host" 
                                               value="<?php echo htmlspecialchars($settings['smtp_host'] ?? 'smtp.gmail.com'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">SMTP Port</label>
                                        <input type="number" class="form-control" name="smtp_port" 
                                               value="<?php echo htmlspecialchars($settings['smtp_port'] ?? 587); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">SMTP Username</label>
                                        <input type="text" class="form-control" name="smtp_username" 
                                               value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">SMTP Password</label>
                                        <input type="password" class="form-control" name="smtp_password" 
                                               placeholder="Enter new password (leave blank to keep current)">
                                        <small class="text-muted">Leave blank to keep current password</small>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">From Email</label>
                                        <input type="email" class="form-control" name="from_email" 
                                               value="<?php echo htmlspecialchars($settings['from_email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">From Name</label>
                                        <input type="text" class="form-control" name="from_name" 
                                               value="<?php echo htmlspecialchars($settings['from_name'] ?? 'DDU Attendance System'); ?>">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="smtp_ssl" 
                                                   id="smtp_ssl" <?php echo ($settings['smtp_ssl'] ?? '1') == '1' ? 'checked' : ''; ?> value="1">
                                            <label class="form-check-label" for="smtp_ssl">
                                                Use SSL/TLS
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <button type="button" class="btn btn-outline-info" onclick="testEmailSettings()">
                                            <i class="fas fa-paper-plane"></i> Test Email Settings
                                        </button>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Email Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Security Settings -->
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-shield-alt"></i> Security Settings
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <input type="hidden" name="form_type" value="security">
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Session Timeout (minutes)</label>
                                        <input type="number" class="form-control" name="session_timeout" 
                                               min="1" value="<?php echo htmlspecialchars($settings['session_timeout'] ?? 30); ?>">
                                        <small class="text-muted">User session timeout in minutes (1-1440)</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Max Login Attempts</label>
                                        <input type="number" class="form-control" name="max_login_attempts" 
                                               min="1" max="10" value="<?php echo htmlspecialchars($settings['max_login_attempts'] ?? 5); ?>">
                                        <small class="text-muted">Maximum failed login attempts before lockout (1-10)</small>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Account Lockout Duration (minutes)</label>
                                        <input type="number" class="form-control" name="lockout_duration" 
                                               min="1" value="<?php echo htmlspecialchars($settings['lockout_duration'] ?? 15); ?>">
                                        <small class="text-muted">Lockout duration after max failed attempts</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Password Expiry Days</label>
                                        <input type="number" class="form-control" name="password_expiry_days" 
                                               min="0" value="<?php echo htmlspecialchars($settings['password_expiry_days'] ?? 90); ?>">
                                        <small class="text-muted">0 = Never expire, 30-365 recommended</small>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="force_password_change" 
                                                   id="force_password_change" <?php echo ($settings['force_password_change'] ?? '0') == '1' ? 'checked' : ''; ?> value="1">
                                            <label class="form-check-label" for="force_password_change">
                                                Force Password Change on First Login
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="enable_2fa" 
                                                   id="enable_2fa" <?php echo ($settings['enable_2fa'] ?? '0') == '1' ? 'checked' : ''; ?> value="1">
                                            <label class="form-check-label" for="enable_2fa">
                                                Enable Two-Factor Authentication
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="enable_ip_whitelist" 
                                                   id="enable_ip_whitelist" <?php echo ($settings['enable_ip_whitelist'] ?? '0') == '1' ? 'checked' : ''; ?> value="1">
                                            <label class="form-check-label" for="enable_ip_whitelist">
                                                Enable IP Whitelist (Admin Only)
                                            </label>
                                        </div>
                                        <small class="text-muted d-block">Restrict admin access to specific IP addresses</small>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Security Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize tabs
var settingsTab = new bootstrap.Tab(document.getElementById('general-tab'));
settingsTab.show();

// Test email settings function
function testEmailSettings() {
    const form = document.querySelector('form[data-bs-target="#email"]');
    if (!form) {
        alert('Please fill in email settings first');
        return;
    }
    
    const formData = new FormData(form);
    formData.append('test_email', '1');
    
    // Show loading
    const originalText = 'Test Email Settings';
    const testBtn = event.target;
    testBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
    testBtn.disabled = true;
    
    fetch('test_email.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Email test successful! Check your inbox.');
        } else {
            alert('✗ Email test failed: ' + data.message);
        }
    })
    .catch(error => {
        alert('✗ Error: ' + error.message);
    })
    .finally(() => {
        testBtn.innerHTML = originalText;
        testBtn.disabled = false;
    });
}

// Form validation
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const requiredFields = form.querySelectorAll('[required]');
        let valid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                field.classList.add('is-invalid');
                valid = false;
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        if (!valid) {
            e.preventDefault();
            alert('Please fill in all required fields.');
        } else {
            // Show loading
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            // Re-enable after 3 seconds in case of error
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        }
    });
});

// Auto-remove alerts after 5 seconds
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        bootstrap.Alert.getInstance(alert)?.close();
    });
}, 5000);
</script>

<?php include '../includes/footer.php'; ?>