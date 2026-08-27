<?php
/**
 * Punch In / Punch Out made by a supervisor on behalf of an employee, for the
 * Flutter app. Same logic as the web supervisor dashboard - both call
 * supervisor_do_punch() - so a punch made in the app is indistinguishable in
 * the database from one made in the browser.
 *
 * POST employee_id, action=in|out, selfie_image (base64 data URL), lat, lng (optional)
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$supervisor_id = (int)$authUser['id'];
$location = supervisor_location($conn, $supervisor_id);

if ($location === null) {
    echo json_encode(['success' => false, 'message' => 'No location is assigned to your supervisor account. Ask an admin to set one.']);
    exit;
}

$result = supervisor_do_punch(
    $conn,
    $supervisor_id,
    $location,
    isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0,
    $_POST['action'] ?? '',
    $_POST['selfie_image'] ?? '',
    isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null,
    isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null
);

echo json_encode($result);
