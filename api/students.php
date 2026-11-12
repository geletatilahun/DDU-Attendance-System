<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

/* ========= ADD STUDENT ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $stmt = $conn->prepare("
        INSERT INTO students 
        (student_id_no, full_name, email, phone, department_id, program, year_of_study, semester)
        VALUES (?,?,?,?,?,?,?,?)
    ");

    $stmt->bind_param(
        "ssssissi",
        $_POST['student_id_no'],
        $_POST['full_name'],
        $_POST['email'],
        $_POST['phone'],
        $_POST['department_id'],
        $_POST['program'],
        $_POST['year_of_study'],
        $_POST['semester']
    );

    if ($stmt->execute()) {
        echo json_encode(['success'=>true]);
    } else {
        echo json_encode(['success'=>false,'message'=>'Insert failed']);
    }
    exit;
}

/* ========= DELETE (SOFT) ========= */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    parse_str($_SERVER['QUERY_STRING'], $q);
    $id = (int)($q['id'] ?? 0);

    $conn->query("UPDATE students SET is_active=0 WHERE student_id=$id");

    echo json_encode(['success'=>true]);
    exit;
}
