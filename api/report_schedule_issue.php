<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

checkRole(['teacher']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['course_id'], $data['schedule_id'], $data['teacher_id'], $data['description'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$sql = "INSERT INTO schedule_issues (
    course_id, 
    schedule_id, 
    teacher_id, 
    issue_type, 
    description, 
    status, 
    reported_at
) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "iiiss",
    $data['course_id'],
    $data['schedule_id'],
    $data['teacher_id'],
    $data['issue_type'],
    $data['description']
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Issue reported successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}