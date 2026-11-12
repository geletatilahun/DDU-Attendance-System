<?php
// DDU Attendance System Configuration File

// Application Settings
define('APP_NAME', 'Dambi Dollo University Attendance System');
define('APP_VERSION', '2.0');
define('APP_URL', 'http://localhost/ddu_attendance'); // Change in production
define('TIMEZONE', 'Africa/Addis_Ababa');

// Database Settings (Overrides db_connect.php in production)
define('DB_HOST', 'localhost');
define('DB_USER', 'ddu_admin');
define('DB_PASS', 'secure_password_here');
define('DB_NAME', 'ddu_attendance_system');
define('DB_CHARSET', 'utf8mb4');

// Security Settings
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('SESSION_REGENERATE', 300); // Regenerate ID every 5 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes
define('PASSWORD_MIN_LENGTH', 8);
define('CSRF_TOKEN_LIFETIME', 3600); // 1 hour

// Attendance Settings
define('ATTENDANCE_THRESHOLD', 75); // Minimum percentage required
define('LATE_THRESHOLD_MINUTES', 15);
define('MAX_ABSENT_DAYS', 10);
define('ACADEMIC_YEAR', '2024');
define('CURRENT_SEMESTER', 1);
define('CLASS_START_TIME', '08:00:00');
define('CLASS_END_TIME', '17:00:00');

// File Upload Settings
define('MAX_FILE_SIZE', 5242880); // 5MB
define('MAX_IMAGE_SIZE', 2097152); // 2MB for profile images
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('ALLOWED_DOC_TYPES', ['pdf', 'doc', 'docx', 'txt']);
define('UPLOAD_PROFILE_PATH', 'uploads/profiles/');
define('UPLOAD_DOC_PATH', 'uploads/documents/');

// Email Settings
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'noreply@ddu.edu.et');
define('SMTP_PASS', 'your_smtp_password');
define('SMTP_SECURE', 'tls');
define('FROM_EMAIL', 'noreply@ddu.edu.et');
define('FROM_NAME', 'DDU Attendance System');
define('ADMIN_EMAIL', 'admin@ddu.edu.et');

// Notification Settings
define('SEND_EMAIL_NOTIFICATIONS', true);
define('SEND_LOW_ATTENDANCE_ALERTS', true);
define('LOW_ATTENDANCE_THRESHOLD', 60);
define('SEND_DAILY_SUMMARY', false);
define('SEND_WEEKLY_REPORT', true);

// QR Code Settings
define('QR_CODE_SIZE', 200);
define('QR_CODE_MARGIN', 4);
define('QR_CODE_FORMAT', 'png');
define('QR_CODE_EXPIRE_MINUTES', 15); // QR code validity time

// Report Settings
define('REPORT_ITEMS_PER_PAGE', 25);
define('AUTO_GENERATE_MONTHLY_REPORTS', true);
define('REPORT_RETENTION_DAYS', 365); // Keep reports for 1 year

// Backup Settings
define('BACKUP_ENABLED', true);
define('BACKUP_PATH', 'backups/');
define('BACKUP_RETENTION_DAYS', 30);
define('AUTO_BACKUP_SCHEDULE', 'daily'); // daily, weekly, monthly

// API Settings
define('API_ENABLED', true);
define('API_KEY_LENGTH', 32);
define('API_RATE_LIMIT', 100); // requests per hour per key
define('API_ALLOWED_IPS', ['127.0.0.1', '::1']); // Restrict API access

// Debug Settings (Set to false in production)
define('DEBUG_MODE', true);
define('LOG_ERRORS', true);
define('ERROR_LOG_PATH', 'logs/error.log');
define('ACTIVITY_LOG_PATH', 'logs/activity.log');

// System Maintenance
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'System is under maintenance. Please try again later.');

// Timezone Setting
date_default_timezone_set(TIMEZONE);

// Error Reporting Configuration
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', ERROR_LOG_PATH);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);

// Set custom error handler if needed
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    if (LOG_ERRORS) {
        $log_msg = "[" . date('Y-m-d H:i:s') . "] Error $errno: $errstr in $errfile on line $errline\n";
        error_log($log_msg, 3, ERROR_LOG_PATH);
    }
    
    if (DEBUG_MODE) {
        // Show error in development
        echo "<div class='alert alert-danger'>";
        echo "<strong>Error $errno:</strong> $errstr<br>";
        echo "File: $errfile (Line: $errline)";
        echo "</div>";
    }
}

set_error_handler("customErrorHandler");

// Maintenance mode check
if (MAINTENANCE_MODE && !defined('MAINTENANCE_BYPASS')) {
    http_response_code(503);
    die(MAINTENANCE_MESSAGE);
}

// Create necessary directories if they don't exist
$directories = [
    'uploads/profiles',
    'uploads/documents',
    'backups',
    'logs',
    'temp'
];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

// Auto-load required classes
spl_autoload_register(function ($class_name) {
    $directories = [
        'includes/classes/',
        'includes/'
    ];
    
    foreach ($directories as $directory) {
        $file = $directory . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Include database connection if not already included
if (!isset($conn)) {
    require_once 'includes/db_connect.php';
}

// Check for required PHP extensions
$required_extensions = ['mysqli', 'gd', 'mbstring', 'json', 'zip'];
foreach ($required_extensions as $extension) {
    if (!extension_loaded($extension)) {
        die("Error: PHP extension '$extension' is not loaded. Please enable it in your php.ini file.");
    }
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Set content type
header('Content-Type: text/html; charset=utf-8');
?>