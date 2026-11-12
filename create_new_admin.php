<?php
// create_new_admin.php
include('includes/db_connect.php');

$username = 'ddu_admin'; // New username
$password = 'Ddu@Secure2024!'; // Strong password
$full_name = 'DDU Administrator';
$email = 'admin@ddu.ac.in';
$role = 'admin';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Disable old admin
$disable_sql = "UPDATE users SET is_active = 0 WHERE username = 'admin'";
$conn->query($disable_sql);

// Create new admin
$sql = "INSERT INTO users (username, password, full_name, email, role, is_active) 
        VALUES (?, ?, ?, ?, ?, 1)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sssss", $username, $hashed_password, $full_name, $email, $role);

if ($stmt->execute()) {
    echo "<h3>New Admin Created Successfully!</h3>";
    echo "Username: $username<br>";
    echo "Password: $password<br>";
    echo "Full Name: $full_name<br>";
    echo "Role: $role<br>";
    echo "<strong>Save these credentials and delete this file!</strong>";
} else {
    echo "Error: " . $conn->error;
}

$stmt->close();
?>