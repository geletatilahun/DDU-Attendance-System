<?php
// includes/functions.php
if (!function_exists('is_admin')) {
    function is_admin() {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
}
// includes/functions.php

/* ================= SESSION HELPERS ================= */

if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return isset($_SESSION['user_id']);
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }
}

if (!function_exists('is_teacher')) {
    function is_teacher() {
        return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'teacher';
    }
}

if (!function_exists('is_student')) {
    function is_student() {
        return is_logged_in() && isset($_SESSION['role']) && $_SESSION['role'] === 'student';
    }
}

/* ================= REDIRECT HELPERS ================= */

function require_login() {
    if (!is_logged_in()) {
        header('Location: /ddu_attendance/login.php');
        exit;
    }
}

function require_admin() {
    if (!is_admin()) {
        header('Location: /ddu_attendance/login.php');
        exit;
    }
}
// Sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Validate email
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Generate random password
function generate_password($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

// Format date
function format_date($date, $format = 'F d, Y') {
    if (empty($date) || $date == '0000-00-00') {
        return 'N/A';
    }
    return date($format, strtotime($date));
}

// Calculate age from date of birth
function calculate_age($dob) {
    if (empty($dob)) return null;
    
    $birth_date = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birth_date);
    return $age->y;
}

// Get current academic year
function get_academic_year() {
    $current_year = date('Y');
    $current_month = date('n');
    
    // Assuming academic year starts in June
    if ($current_month >= 6) {
        return $current_year . '-' . ($current_year + 1);
    } else {
        return ($current_year - 1) . '-' . $current_year;
    }
}

// Get semester based on month
function get_current_semester() {
    $month = date('n');
    
    // Assuming semesters:
    // Semester 1: June-September
    // Semester 2: October-January
    // Semester 3: February-May
    
    if ($month >= 6 && $month <= 9) {
        return 1;
    } elseif ($month >= 10 || $month <= 1) {
        return 2;
    } else {
        return 3;
    }
}

// Check if date is weekday
function is_weekday($date) {
    $day_of_week = date('N', strtotime($date));
    return ($day_of_week >= 1 && $day_of_week <= 5); // Monday to Friday
}

// Get attendance percentage
function get_attendance_percentage($present, $total) {
    if ($total == 0) return 0;
    return round(($present / $total) * 100, 2);
}

// Get attendance status color
function get_attendance_status_color($percentage) {
    if ($percentage >= 75) {
        return 'success';
    } elseif ($percentage >= 60) {
        return 'warning';
    } else {
        return 'danger';
    }
}

// Get role display name
function get_role_display_name($role) {
    $roles = [
        'admin' => 'Administrator',
        'teacher' => 'Teacher',
        'student' => 'Student',
        'dean' => 'Dean'
    ];
    
    return $roles[$role] ?? ucfirst($role);
}

// Get status badge
function get_status_badge($status) {
    $badges = [
        'active' => 'success',
        'inactive' => 'danger',
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger'
    ];
    
    $color = $badges[$status] ?? 'secondary';
    return "<span class='badge bg-$color'>" . ucfirst($status) . "</span>";
}

// Redirect with message
function redirect_with_message($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

// Get flash message
function get_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        
        return [
            'message' => $message,
            'type' => $type
        ];
    }
    return null;
}

// Display flash message
function display_flash_message() {
    $flash = get_flash_message();
    if ($flash) {
        echo "<div class='alert alert-{$flash['type']} alert-dismissible fade show' role='alert'>";
        echo htmlspecialchars($flash['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }
}

// Check if string contains HTML
function contains_html($string) {
    return $string != strip_tags($string);
}

// Truncate text
function truncate_text($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

// Format file size
function format_file_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return '1 byte';
    } else {
        return '0 bytes';
    }
}

// Generate UUID
function generate_uuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xfff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

// Validate phone number
function validate_phone($phone) {
    // Basic phone validation - adjust as needed
    $pattern = '/^[\+]?[1-9][0-9]{9,14}$/';
    return preg_match($pattern, $phone);
}

// Get time ago format
function time_ago($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . ' days ago';
    } elseif ($diff < 2592000) {
        return floor($diff / 604800) . ' weeks ago';
    } elseif ($diff < 31536000) {
        return floor($diff / 2592000) . ' months ago';
    } else {
        return floor($diff / 31536000) . ' years ago';
    }
}

// Check if array is associative
function is_associative_array($arr) {
    if (!is_array($arr)) return false;
    if (array() === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

// Get current URL
function current_url() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    return $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

// Get base URL
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $base = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
    $base = rtrim($base, '/') . '/';
    return $base . ltrim($path, '/');
}

// Log activity
function log_activity($user_id, $action, $details = null) {
    global $conn;
    
    $sql = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt->bind_param("issss", $user_id, $action, $details, $ip_address, $user_agent);
    $stmt->execute();
}
?>