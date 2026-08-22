<?php
// Modified upload handler that extracts and stores face descriptor
require_once '../config/db.php';
require_once '../config/api_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Check authentication
    $authUser = api_authenticate_flexible($conn);
    
    $employee_id = isset($_POST['employee_id']) ? intval($_POST['employee_id']) : null;
    
    if (!$employee_id || !isset($_FILES['photo'])) {
        throw new Exception('Missing employee_id or photo');
    }
    
    // Get employee
    $empQuery = "SELECT id, name, employee_id FROM users WHERE id = ?";
    $stmt = $conn->prepare($empQuery);
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    
    if (!$employee) {
        throw new Exception('Employee not found');
    }
    
    // Handle file upload
    $photo = $_FILES['photo'];
    $uploadDir = '../uploads/employee_photos/';
    
    // Create directory if not exists
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Validate image
    $allowed = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($photo['type'], $allowed)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF allowed');
    }
    
    if ($photo['size'] > 5 * 1024 * 1024) {
        throw new Exception('File too large. Max 5MB');
    }
    
    // Save photo with simple naming
    $filename = $employee_id . '.' . pathinfo($photo['name'], PATHINFO_EXTENSION);
    $filepath = $uploadDir . $filename;
    
    if (!move_uploaded_file($photo['tmp_name'], $filepath)) {
        throw new Exception('Failed to save photo');
    }
    
    // Convert photo to base64 for descriptor extraction on browser
    $photoBase64 = 'data:' . $photo['type'] . ';base64,' . base64_encode(file_get_contents($filepath));
    
    // Clean old photos (optional)
    $oldPhotos = glob($uploadDir . $employee_id . '.*');
    foreach ($oldPhotos as $oldFile) {
        if ($oldFile !== $filepath) {
            @unlink($oldFile);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Photo uploaded successfully',
        'employee_id' => $employee_id,
        'employee_name' => $employee['name'],
        'photo_base64' => $photoBase64,
        'note' => 'Descriptor will be extracted and saved on next face recognition session'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
