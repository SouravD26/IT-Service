<?php
require_once '../config/db.php';
require_once '../config/api_auth.php';

header('Content-Type: application/json');
$authUser = api_authenticate_flexible($conn);

try {
    $today = date('Y-m-d');
    
    // Get today's attendance records
    $query = "SELECT 
        a.id,
        a.user_id,
        u.employee_id,
        u.name,
        a.punch_in,
        a.punch_out
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE DATE(a.punch_in) = ?
    ORDER BY a.punch_in DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'records' => $records,
        'count' => count($records)
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
