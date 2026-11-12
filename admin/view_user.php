<?php
require_once '../includes/db_connect.php';
$id=(int)$_GET['id'];

$s=$conn->query("
SELECT s.*,d.department_name 
FROM students s 
LEFT JOIN departments d ON d.department_id=s.department_id
WHERE s.student_id=$id
")->fetch_assoc();
?>

<h3><?= $s['full_name'] ?></h3>
<p>ID: <?= $s['student_id_no'] ?></p>
<p>Email: <?= $s['email'] ?></p>
<p>Department: <?= $s['department_name'] ?></p>
