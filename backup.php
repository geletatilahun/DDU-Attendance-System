<?php
session_start();
include('includes/db_connect.php');
include('includes/auth.php');

checkRole(['admin']);

// Create backup
$backup_file = 'backups/db_backup_' . date('Y-m-d_H-i-s') . '.sql';
$command = "mysqldump --user=" . DB_USER . " --password=" . DB_PASS . " --host=" . DB_HOST . " " . DB_NAME . " > " . $backup_file;

system($command, $output);

if ($output === 0) {
    $_SESSION['success'] = "Database backup created successfully: " . basename($backup_file);
} else {
    $_SESSION['error'] = "Backup failed!";
}

header('Location: admin/dashboard.php');
exit;
?>