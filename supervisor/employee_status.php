<?php
/**
 * Today's punch state for one employee at the supervisor's location.
 * Drives the punch popup: which button is offered, and the session list.
 *
 * GET employee_id
 */
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

session_start();
require_once '../config/db.php';
require_once '../config/attendance_geo.php';
require_once '../config/supervisor_setup.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please log in as a supervisor.']);
    exit;
}

$supervisor_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT location FROM users WHERE id = ? AND role = 'supervisor'");
$stmt->bind_param("i", $supervisor_id);
$stmt->execute();
$supervisor = $stmt->get_result()->fetch_assoc();
$stmt->close();

$location    = $supervisor['location'] ?? '';
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

$employee = supervisor_can_punch_for($conn, $location, $employee_id);
if (!$employee) {
    echo json_encode(['success' => false, 'message' => 'That employee is not at your location.']);
    exit;
}

$date = attendance_shift_date();
$stmt = $conn->prepare(
    "SELECT punch_in, punch_out FROM attendance
     WHERE user_id = ? AND date = ?
     ORDER BY id"
);
$stmt->bind_param("is", $employee_id, $date);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// An open session (punched in, not yet out) means the next action is punch out
$open = false;
foreach ($sessions as $s) {
    if ($s['punch_in'] !== null && $s['punch_out'] === null) {
        $open = true;
    }
}

echo json_encode([
    'success'      => true,
    'employee'     => ['id' => (int)$employee['id'], 'name' => $employee['name'], 'employee_id' => $employee['employee_id']],
    'date'         => $date,
    'is_punched_in' => $open,
    'next_action'  => $open ? 'out' : 'in',
    'sessions'     => array_map(function ($s) {
        return [
            'punch_in'  => $s['punch_in']  ? date('h:i A', strtotime($s['punch_in']))  : null,
            'punch_out' => $s['punch_out'] ? date('h:i A', strtotime($s['punch_out'])) : null,
        ];
    }, $sessions),
]);
