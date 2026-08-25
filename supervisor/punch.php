<?php
/**
 * Records a punch made by a supervisor on behalf of an employee.
 *
 * Same rules as the employee's own punch (employee/punch_in.php and api/punch.php)
 * - selfie required, geofence enforced, server-authoritative time - except the
 * attendance row is written for the employee while punch_in_by / punch_out_by
 * records which supervisor did it.
 *
 * POST employee_id, action=in|out, selfie_image (base64 data URL), lat, lng
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$supervisor_id = (int)$_SESSION['user_id'];

// The supervisor's own location decides who they may punch for
$stmt = $conn->prepare("SELECT location FROM users WHERE id = ? AND role = 'supervisor'");
$stmt->bind_param("i", $supervisor_id);
$stmt->execute();
$supervisor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$supervisor || trim((string)$supervisor['location']) === '') {
    echo json_encode(['success' => false, 'message' => 'No location is assigned to your supervisor account. Ask an admin to set one.']);
    exit;
}
$location = $supervisor['location'];

$employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
$action      = $_POST['action'] ?? '';
$selfie      = $_POST['selfie_image'] ?? '';
$lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? floatval($_POST['lat']) : null;
$lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? floatval($_POST['lng']) : null;

if (!in_array($action, ['in', 'out'], true)) {
    echo json_encode(['success' => false, 'message' => 'action must be "in" or "out"']);
    exit;
}

$employee = supervisor_can_punch_for($conn, $location, $employee_id);
if (!$employee) {
    echo json_encode(['success' => false, 'message' => 'That employee is not at your location.']);
    exit;
}
if ($employee['status'] === 'Resign') {
    echo json_encode(['success' => false, 'message' => $employee['name'] . ' is marked as Resign and cannot be punched ' . $action . '.']);
    exit;
}
if ($selfie === '') {
    echo json_encode(['success' => false, 'message' => 'Please capture a photo before punching ' . $action . '.']);
    exit;
}

// Geofence uses the employee's own rules, exactly as if they punched themselves
$locCheck = attendance_check_location_allowed($conn, $employee_id, $lat, $lng);
if (!$locCheck['allowed']) {
    echo json_encode(['success' => false, 'message' => $locCheck['message']]);
    exit;
}

$date          = attendance_shift_date();
$display_date  = date('Y-m-d');
$current_time  = date('H:i:s');
$location_name = ($lat !== null && $lng !== null) ? attendance_location_name($lat, $lng) : $location;

if ($action === 'in') {
    // Refuse a second punch in while an earlier session is still open
    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL");
    $check->bind_param("is", $employee_id, $date);
    $check->execute();
    $open = $check->get_result()->num_rows > 0;
    $check->close();

    if ($open) {
        echo json_encode(['success' => false, 'message' => $employee['name'] . ' is already punched in. Punch out first.']);
        exit;
    }

    $filename = attendance_save_selfie($selfie, 'selfie', $employee_id);
    if (!$filename) {
        echo json_encode(['success' => false, 'message' => 'Error saving the photo. Please try again.']);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO attendance (user_id, date, punch_in, status, selfie_punchin, punch_in_location, punch_in_by)
         VALUES (?, ?, ?, 'Present', ?, ?, ?)"
    );
    $stmt->bind_param("issssi", $employee_id, $date, $current_time, $filename, $location_name, $supervisor_id);

    if ($stmt->execute()) {
        echo json_encode([
            'success'  => true,
            'type'     => 'punch_in',
            'message'  => $employee['name'] . ' punched IN at ' . date('h:i A', strtotime($current_time)),
            'time'     => $current_time,
            'date'     => $display_date,
            'location' => $location_name,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();

} else { // out
    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL");
    $check->bind_param("is", $employee_id, $date);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => $employee['name'] . ' has no open punch in for today. Punch in first.']);
        exit;
    }
    $check->close();

    $filename = attendance_save_selfie($selfie, 'selfie_punchout', $employee_id);
    if (!$filename) {
        echo json_encode(['success' => false, 'message' => 'Error saving the photo. Please try again.']);
        exit;
    }

    $stmt = $conn->prepare(
        "UPDATE attendance
         SET punch_out = ?, selfie_punchout = ?, punch_out_location = ?, punch_out_by = ?
         WHERE user_id = ? AND date = ? AND punch_out IS NULL
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param("sssiis", $current_time, $filename, $location_name, $supervisor_id, $employee_id, $date);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode([
            'success'  => true,
            'type'     => 'punch_out',
            'message'  => $employee['name'] . ' punched OUT at ' . date('h:i A', strtotime($current_time)),
            'time'     => $current_time,
            'date'     => $display_date,
            'location' => $location_name,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not punch out - the session may already be closed.']);
    }
    $stmt->close();
}
