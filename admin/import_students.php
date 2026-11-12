<?php
session_start();
include('../includes/db_connect.php');
include('../includes/auth.php');

checkRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (($handle = fopen($file, "r")) !== FALSE) {
        $conn->begin_transaction();
        $success = 0;
        $failed = 0;
        
        // Skip header
        fgetcsv($handle);
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            // Expected columns: student_id_no, full_name, email, phone, gender, department_id, program, year_of_study, semester
            $student_id_no = $conn->real_escape_string($data[0]);
            $full_name = $conn->real_escape_string($data[1]);
            $email = $conn->real_escape_string($data[2]);
            $phone = $conn->real_escape_string($data[3]);
            $gender = $conn->real_escape_string($data[4]);
            $department_id = intval($data[5]);
            $program = $conn->real_escape_string($data[6]);
            $year_of_study = intval($data[7]);
            $semester = intval($data[8]);
            
            // Default password: Student ID
            $password = password_hash($student_id_no, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("INSERT INTO students (student_id_no, full_name, email, phone, gender, department_id, program, year_of_study, semester, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssisss", $student_id_no, $full_name, $email, $phone, $gender, $department_id, $program, $year_of_study, $semester, $password);
            
            if ($stmt->execute()) {
                $success++;
            } else {
                $failed++;
            }
            $stmt->close();
        }
        
        fclose($handle);
        $conn->commit();
        
        $_SESSION['success'] = "Imported $success students. Failed: $failed";
    }
    
    header('Location: import_students.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2>Import Students</h2>
        
        <div class="alert alert-info">
            <strong>CSV Format:</strong> student_id_no, full_name, email, phone, gender, department_id, program, year_of_study, semester<br>
            <strong>Default Password:</strong> Student ID number<br>
            <a href="student_template.csv" class="btn btn-sm btn-success mt-2">Download Template</a>
        </div>
        
        <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); endif; ?>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label>Select CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Import Students</button>
                    <a href="manage_students.php" class="btn btn-secondary">View Students</a>
                </form>
            </div>
        </div>
    </div>
</body>
</html>