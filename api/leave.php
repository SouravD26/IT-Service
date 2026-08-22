<?php
/**
 * Leave Application API for the Flutter app.
 * Mirrors employee/leave_application.php, exposed as JSON.
 *
 * GET  -> list the logged-in user's leave applications
 * POST leave_type, start_date, end_date, reason -> submit a new application
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/api_auth.php';

$authUser = api_authenticate_flexible($conn);
$user_id = (int)$authUser['id'];

$conn->query("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type ENUM('Casual Leave','Sick Leave','Earned Leave','Maternity Leave','Paternity Leave','Unpaid Leave') NOT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL, days_count INT NOT NULL DEFAULT 1,
    reason TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    admin_notes TEXT, reviewed_by INT, reviewed_at TIMESTAMP NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leave_type = $_POST['leave_type'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $reason = $_POST['reason'] ?? '';

    $validTypes = ['Casual Leave', 'Sick Leave', 'Earned Leave', 'Maternity Leave', 'Paternity Leave', 'Unpaid Leave'];
    if (!in_array($leave_type, $validTypes, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid leave_type']);
        exit;
    }
    if (!$start_date || !$end_date) {
        echo json_encode(['success' => false, 'message' => 'start_date and end_date are required']);
        exit;
    }
    if ($start_date > $end_date) {
        echo json_encode(['success' => false, 'message' => 'End date must be after start date']);
        exit;
    }

    $days = (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;

    $stmt = $conn->prepare("INSERT INTO leave_applications (user_id, leave_type, start_date, end_date, days_count, reason) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssis", $user_id, $leave_type, $start_date, $end_date, $days, $reason);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Leave application submitted successfully',
            'id' => $stmt->insert_id,
            'days_count' => $days
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

// GET: list my leave applications
$stmt = $conn->prepare("SELECT id, leave_type, start_date, end_date, days_count, reason, status, admin_notes, created_at FROM leave_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$leaves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success' => true,
    'count' => count($leaves),
    'leaves' => $leaves
]);
