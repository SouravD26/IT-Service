<?php
/**
 * Today's punch state for one employee, so the app knows whether to offer
 * Punch In or Punch Out before opening its camera.
 *
 * GET employee_id
 * Auth: Bearer token from auth/api_login.php, belonging to a supervisor.
 */
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

require_once '../config/db.php';
require_once '../config/api_auth.php';
require_once '../config/supervisor_punch.php';

$authUser = api_authenticate_flexible($conn);

if (($authUser['role'] ?? '') !== 'supervisor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'This endpoint is for supervisor accounts only.']);
    exit;
}

$supervisor_id = (int)$authUser['id'];
$location = supervisor_location($conn, $supervisor_id);

if ($location === null) {
    echo json_encode(['success' => false, 'message' => 'No location is assigned to your supervisor account. Ask an admin to set one.']);
    exit;
}

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
echo json_encode(supervisor_today_status($conn, $location, $employee_id));
