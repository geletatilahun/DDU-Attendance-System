<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

/* ========= ADD TEACHER ========= */
if ($_POST['action'] === 'add') {

    $full_name = $_POST['full_name'];
    $username  = $_POST['username'];
    $email     = $_POST['email'];
    $phone     = $_POST['phone'];
    $dept_id   = $_POST['department_id'];
    $password  = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // 1. Insert into users table
    $stmt = $conn->prepare("
        INSERT INTO users (full_name, username, email, phone, password, role)
        VALUES (?, ?, ?, ?, ?, 'teacher')
    ");
    $stmt->bind_param("sssss",
        $full_name, $username, $email, $phone, $password
    );
    $stmt->execute();

    $user_id = $conn->insert_id;

    // 2. Insert into teacher_details
    $stmt2 = $conn->prepare("
        INSERT INTO teacher_details (user_id, department_id)
        VALUES (?, ?)
    ");
    $stmt2->bind_param("ii", $user_id, $dept_id);
    $stmt2->execute();

    $_SESSION['flash_message'] = "Teacher added successfully";
    header("Location: manage_teachers.php");
    exit;
}
