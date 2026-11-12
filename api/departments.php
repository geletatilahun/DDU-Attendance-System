<?php
session_start();
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

/* ========= GET CURRENT DEPARTMENT HEAD ========= */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_head') {
    $department_id = (int)($_GET['department_id'] ?? 0);
    
    if ($department_id <= 0) {
        echo json_encode(['success'=>false,'message'=>'Invalid department ID']);
        exit;
    }
    
    // Get current department head
    $stmt = $conn->prepare("
        SELECT d.department_head_id, u.user_id, u.full_name, u.email
        FROM departments d
        LEFT JOIN users u ON d.department_head_id = u.user_id
        WHERE d.department_id = ?
    ");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        if ($row['department_head_id']) {
            echo json_encode([
                'success' => true,
                'head' => [
                    'user_id' => $row['user_id'],
                    'full_name' => $row['full_name'],
                    'email' => $row['email']
                ]
            ]);
        } else {
            echo json_encode(['success' => true, 'head' => null]);
        }
    } else {
        echo json_encode(['success'=>false,'message'=>'Department not found']);
    }
    
    exit;
}

/* ========= SET/REMOVE DEPARTMENT HEAD ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_head') {
    
    $user_id = (int)($_POST['user_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $remove_head = isset($_POST['remove_head']) && $_POST['remove_head'] == '1';
    
    if ($user_id <= 0) {
        echo json_encode(['success'=>false,'message'=>'Invalid user ID']);
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First, remove user as head from any department they currently head
        $stmt1 = $conn->prepare("
            UPDATE departments 
            SET department_head_id = NULL 
            WHERE department_head_id = ?
        ");
        $stmt1->bind_param("i", $user_id);
        $stmt1->execute();
        
        // Update user's is_department_head flag
        $user_flag = $remove_head ? 0 : 1;
        $stmt2 = $conn->prepare("
            UPDATE users 
            SET is_department_head = ? 
            WHERE user_id = ?
        ");
        $stmt2->bind_param("ii", $user_flag, $user_id);
        $stmt2->execute();
        
        if (!$remove_head) {
            // Set as head of new department
            if ($department_id <= 0) {
                throw new Exception('Invalid department ID');
            }
            
            // First, remove current head from this department if exists
            $stmt3 = $conn->prepare("
                UPDATE departments 
                SET department_head_id = NULL 
                WHERE department_id = ?
            ");
            $stmt3->bind_param("i", $department_id);
            $stmt3->execute();
            
            // Update the old head's flag if they exist
            $old_head_stmt = $conn->prepare("
                UPDATE users 
                SET is_department_head = 0 
                WHERE user_id IN (
                    SELECT department_head_id 
                    FROM departments 
                    WHERE department_id = ?
                )
            ");
            $old_head_stmt->bind_param("i", $department_id);
            $old_head_stmt->execute();
            
            // Set new head
            $stmt4 = $conn->prepare("
                UPDATE departments 
                SET department_head_id = ? 
                WHERE department_id = ?
            ");
            $stmt4->bind_param("ii", $user_id, $department_id);
            $stmt4->execute();
            
            // Update user's department assignment
            $stmt5 = $conn->prepare("
                UPDATE users 
                SET department_id = ? 
                WHERE user_id = ?
            ");
            $stmt5->bind_param("ii", $department_id, $user_id);
            $stmt5->execute();
        } else {
            // Just remove user's department assignment if they're being removed as head
            $stmt5 = $conn->prepare("
                UPDATE users 
                SET department_id = NULL 
                WHERE user_id = ?
            ");
            $stmt5->bind_param("i", $user_id);
            $stmt5->execute();
        }
        
        // Commit transaction
        $conn->commit();
        
        $message = $remove_head 
            ? 'Teacher removed as department head successfully'
            : 'Teacher assigned as department head successfully';
            
        echo json_encode(['success'=>true, 'message'=>$message]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
    }
    
    exit;
}

// Default response for invalid requests
echo json_encode(['success'=>false,'message'=>'Invalid request']);
exit;