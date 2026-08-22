<?php
require_once '../config/db.php';
require_once '../config/api_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Check authentication - only admin can delete
    $authUser = api_authenticate_flexible($conn);
    if ($authUser['role'] !== 'admin' && $authUser['role'] !== 'suparadmin') {
        throw new Exception('Unauthorized access');
    }
    
    // Get JSON body
    $input = json_decode(file_get_contents('php://input'), true);
    $employee_id = isset($input['employee_id']) ? intval($input['employee_id']) : null;
    
    if (!$employee_id) {
        throw new Exception('Missing employee_id');
    }
    
    // Verify employee exists
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
    $checkStmt->bind_param('i', $employee_id);
    $checkStmt->execute();
    if ($checkStmt->get_result()->num_rows === 0) {
        throw new Exception('Employee not found');
    }
    $checkStmt->close();
    
    // Find and delete photo file
    $photoDir = '../uploads/employee_photos/';
    $photoPattern = $photoDir . $employee_id . ".*";
    $photos = glob($photoPattern);
    
    $deleted = false;
    if (!empty($photos)) {
        foreach ($photos as $photoFile) {
            if (unlink($photoFile)) {
                $deleted = true;
            }
        }
    }
    
    // Also clear face_descriptor from database if exists
    $clearDescriptor = $conn->prepare("UPDATE users SET face_descriptor = NULL WHERE id = ?");
    $clearDescriptor->bind_param('i', $employee_id);
    $clearDescriptor->execute();
    $clearDescriptor->close();
    
    echo json_encode([
        'success' => true,
        'message' => 'Photo deleted successfully',
        'photo_deleted' => $deleted,
        'descriptor_cleared' => true
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
