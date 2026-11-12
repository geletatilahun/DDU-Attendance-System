<?php
session_start();
include('../includes/db_connect.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'msg' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'add';

/* ================= FETCH USERS ================= */
if ($action === 'fetch') {
    $sql = "SELECT user_id, full_name, username, role, is_active
            FROM users
            ORDER BY user_id DESC";
    $res = $conn->query($sql);

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode($data);
    exit;
}

/* ================= ADD USER ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {

    $full_name = trim($_POST['full_name']);
    $username  = trim($_POST['username']);
    $password  = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role      = $_POST['role'];

    // Username unique check
    $check = $conn->prepare("SELECT user_id FROM users WHERE username=?");
    $check->bind_param("s", $username);
    $check->execute();
    if ($check->get_result()->num_rows) {
        echo json_encode(['success' => false, 'msg' => 'Username already exists']);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, username, password, role, is_active)
         VALUES (?,?,?,?,1)"
    );
    $stmt->bind_param("ssss", $full_name, $username, $password, $role);

    if (!$stmt->execute()) {
        echo json_encode(['success' => false, 'msg' => 'User insert failed']);
        exit;
    }

    /* ===== OPTIONAL STUDENT INSERT ===== */
    if (!empty($_POST['student_id_no'])) {

        $stmt2 = $conn->prepare(
            "INSERT INTO students
            (student_id_no, full_name, department_id, program, year_of_study, semester, is_active)
            VALUES (?,?,?,?,?,?,1)"
        );

        $stmt2->bind_param(
            "ssissi",
            $_POST['student_id_no'],
            $full_name,
            $_POST['department_id'],
            $_POST['program'],
            $_POST['year_of_study'],
            $_POST['semester']
        );
        $stmt2->execute();
    }

    echo json_encode(['success' => true]);
    exit;
}

/* ================= UPDATE USER ================= */
if ($action === 'update') {

    $user_id   = $_POST['user_id'];
    $full_name = $_POST['full_name'];
    $username  = $_POST['username'];
    $role      = $_POST['role'];

    $stmt = $conn->prepare(
        "UPDATE users SET full_name=?, username=?, role=? WHERE user_id=?"
    );
    $stmt->bind_param("sssi", $full_name, $username, $role, $user_id);
    $stmt->execute();

    // Update student if exists
    if (!empty($_POST['student_id_no'])) {

        $check = $conn->prepare(
            "SELECT student_id FROM students WHERE student_id_no=?"
        );
        $check->bind_param("s", $_POST['student_id_no']);
        $check->execute();

        if ($check->get_result()->num_rows) {
            $stmt2 = $conn->prepare(
                "UPDATE students
                 SET full_name=?, department_id=?, program=?, year_of_study=?, semester=?
                 WHERE student_id_no=?"
            );
            $stmt2->bind_param(
                "sissis",
                $full_name,
                $_POST['department_id'],
                $_POST['program'],
                $_POST['year_of_study'],
                $_POST['semester'],
                $_POST['student_id_no']
            );
            $stmt2->execute();
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

/* ================= SOFT DELETE ================= */
if ($action === 'delete') {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE users SET is_active=0 WHERE user_id=$id");
    echo json_encode(['success' => true]);
    exit;
}

// After adding user:
log_action('user_create', "Created user: $username ($role)");

// After updating user:
log_action('user_update', "Updated user: $username");

// After deleting user:
log_action('user_delete', "Deleted user ID: $user_id");

// In login.php after successful login:
log_action('login', 'User logged in successfully');

// In logout.php:
log_action('logout', 'User logged out');