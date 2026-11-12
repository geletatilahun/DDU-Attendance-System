<?php
// upload_profile.php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Check if file was uploaded
if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit();
}

$user_id = $_SESSION['user_id'];
$file = $_FILES['profile_picture'];

// Validate file
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 5 * 1024 * 1024; // 5MB

if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
    exit();
}

if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB.']);
    exit();
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'profile_' . $user_id . '_' . time() . '.' . $extension;

// Define upload directory
$upload_dir = '../uploads/profiles/';

// Create directory if it doesn't exist
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Move uploaded file
$destination = $upload_dir . $filename;

if (move_uploaded_file($file['tmp_name'], $destination)) {
    // Resize image for better performance (optional)
    resizeImage($destination, 200, 200);
    
    // Update database with profile picture path
    $relative_path = 'uploads/profiles/' . $filename;
    $sql = "UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $relative_path, $user_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Profile picture updated successfully', 'path' => $relative_path]);
    } else {
        // Delete the uploaded file if database update fails
        unlink($destination);
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
}

function resizeImage($file, $width, $height) {
    // This is a basic resizing function - you might want to use a library like GD or Imagick
    $info = getimagesize($file);
    if (!$info) return false;
    
    $mime = $info['mime'];
    
    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($file);
            break;
        case 'image/png':
            $image = imagecreatefrompng($file);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($file);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($file);
            break;
        default:
            return false;
    }
    
    // Resize the image
    $new_image = imagescale($image, $width, $height);
    
    // Save the resized image
    switch ($mime) {
        case 'image/jpeg':
            imagejpeg($new_image, $file, 85);
            break;
        case 'image/png':
            imagepng($new_image, $file, 8);
            break;
        case 'image/gif':
            imagegif($new_image, $file);
            break;
        case 'image/webp':
            imagewebp($new_image, $file, 85);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($new_image);
    
    return true;
}
?>