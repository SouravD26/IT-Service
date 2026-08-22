<?php
/**
 * Punch In / Punch Out API for the Flutter app.
 * Mirrors employee/punch_in.php and employee/punch_out.php (selfie + GPS
 * geofencing + server-authoritative time), exposed as JSON.
 *
 * POST action=in|out, selfie_image (base64 data URL), lat, lng (optional)
 */
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/api_auth.php';
require_once '../config/attendance_geo.php';

$authUser = api_authenticate_flexible($conn);
$user_id = (int)$authUser['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['in', 'out'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'action must be "in" or "out"']);
    exit;
}

$lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? floatval($_POST['lat']) : null;
$lng = isset($_POST['lng']) && $_POST['lng'] !== '' ? floatval($_POST['lng']) : null;
$selfie = $_POST['selfie_image'] ?? '';

if (empty($selfie)) {
    echo json_encode(['success' => false, 'message' => 'Please capture a selfie before punching ' . $action]);
    exit;
}

$locCheck = attendance_check_location_allowed($conn, $user_id, $lat, $lng);
if (!$locCheck['allowed']) {
    echo json_encode(['success' => false, 'message' => $locCheck['message']]);
    exit;
}

$status_check = $conn->prepare("SELECT status FROM users WHERE id = ?");
$status_check->bind_param("i", $user_id);
$status_check->execute();
$user_status = $status_check->get_result()->fetch_assoc();
$status_check->close();

if ($user_status && $user_status['status'] === 'Resign') {
    echo json_encode(['success' => false, 'message' => 'Your account has been marked as Resign. You cannot punch ' . $action . '.']);
    exit;
}

$date = attendance_shift_date();
$display_date = date('Y-m-d');
$current_time = date('H:i:s');
$location_name = ($lat !== null && $lng !== null) ? attendance_location_name($lat, $lng) : 'Office';

if ($action === 'in') {
    $filename = attendance_save_selfie($selfie, 'selfie', $user_id);
    if (!$filename) {
        echo json_encode(['success' => false, 'message' => 'Error saving selfie image']);
        exit;
    }

    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_in IS NOT NULL");
    $check->bind_param("is", $user_id, $date);
    $check->execute();
    $isFirstPunch = $check->get_result()->num_rows === 0;
    $check->close();

    $stmt = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, status, selfie_punchin, punch_in_location) VALUES (?, ?, ?, 'Present', ?, ?)");
    $stmt->bind_param("issss", $user_id, $date, $current_time, $filename, $location_name);

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'type' => 'punch_in',
            'message' => 'Punch In successful at ' . $current_time . ' on ' . $display_date . ($isFirstPunch ? '' : ' (additional session)'),
            'punch_in' => $current_time,
            'date' => $display_date
        ]);
    } else {
        $isDupError = strpos($stmt->error, 'unique_daily_attendance') !== false || strpos($stmt->error, 'Duplicate entry') !== false;
        echo json_encode([
            'success' => false,
            'message' => $isDupError
                ? 'Database constraint issue detected. Ask your admin to run system setup to enable multiple punch in/out per day.'
                : ('Database error: ' . $stmt->error)
        ]);
    }
    $stmt->close();

} else { // action === 'out'
    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $check->bind_param("is", $user_id, $date);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        echo json_encode(['success' => false, 'message' => 'No punch in record found for today. Please punch in first.']);
        exit;
    }
    $check->close();

    $filename = attendance_save_selfie($selfie, 'selfie_punchout', $user_id);
    if (!$filename) {
        echo json_encode(['success' => false, 'message' => 'Error saving selfie image']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE attendance
        SET punch_out = ?, selfie_punchout = ?, punch_out_location = ?
        WHERE user_id = ? AND date = ? AND punch_out IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("sssis", $current_time, $filename, $location_name, $user_id, $date);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'type' => 'punch_out',
                'message' => 'Punch Out successful at ' . $current_time . ' on ' . $display_date,
                'punch_out' => $current_time,
                'date' => $display_date
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'You have already punched out, or no open punch-in session found.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
}
