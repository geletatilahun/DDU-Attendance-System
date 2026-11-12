<?php
session_start();
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) exit;

if ($_POST['action'] === 'assign') {

    $teacher_id = (int)$_POST['teacher_id'];
    $course_id  = (int)$_POST['course_id'];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO teacher_courses (teacher_id, course_id)
        VALUES (?, ?)
    ");
    $stmt->bind_param("ii", $teacher_id, $course_id);
    $stmt->execute();

    header("Location: assign_teacher_course.php");
    exit;
}
