<?php
/**
 * Browser front door for a supervisor punch (session-authenticated).
 * The logic lives in config/supervisor_punch.php, shared with
 * api/supervisor_punch.php so the app and the web write identical rows.
 *
 * POST employee_id, action=in|out, selfie_image (base64 data URL), lat, lng
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$supervisor_id = (int)$_SESSION['user_id'];
$location = supervisor_location($conn, $supervisor_id);

if ($location === null) {
    echo json_encode(['success' => false, 'message' => 'No project is assigned to your supervisor account. Ask an admin to allocate you to one.']);
    exit;
}

echo json_encode(supervisor_do_punch(
    $conn,
    $supervisor_id,
    $location,
    isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0,
    $_POST['action'] ?? '',
    $_POST['selfie_image'] ?? '',
    isset($_POST['lat']) && $_POST['lat'] !== '' ? (float)$_POST['lat'] : null,
    isset($_POST['lng']) && $_POST['lng'] !== '' ? (float)$_POST['lng'] : null
));
