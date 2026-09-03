<?php
/**
 * Browser front door for one employee's punch state (session-authenticated).
 * Shares supervisor_today_status() with api/supervisor_employee_status.php.
 *
 * GET employee_id
 */
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

session_start();
require_once '../config/db.php';
require_once '../config/supervisor_punch.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please log in as a supervisor.']);
    exit;
}

$supervisor_id = (int)$_SESSION['user_id'];
$location = supervisor_location($conn, $supervisor_id);

if ($location === null) {
    echo json_encode(['success' => false, 'message' => 'No project is assigned to your supervisor account.']);
    exit;
}

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
echo json_encode(supervisor_today_status($conn, $location, $employee_id));
