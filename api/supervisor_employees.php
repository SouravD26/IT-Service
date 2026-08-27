<?php
/**
 * The employees a supervisor may punch for - everyone still working at their
 * location - each with today's punch state so the app can render its search
 * list and the correct button in one call.
 *
 * GET (no parameters)
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

echo json_encode([
    'success'   => true,
    'location'  => $location,
    'date'      => attendance_shift_date(),
    'employees' => supervisor_employee_list($conn, $location),
]);
