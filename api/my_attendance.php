<?php
/**
 * My Attendance History API for the Flutter app.
 * Mirrors employee/my_attendance.php's core query, exposed as JSON.
 *
 * GET month=8&year=2026 (defaults to current month/year)
 * -> { success, records: [...] }
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/api_auth.php';

$authUser = api_authenticate_flexible($conn);
$user_id = (int)$authUser['id'];

$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$stmt = $conn->prepare("
    SELECT id, date, punch_in, punch_out, punch_in_location, punch_out_location, status
    FROM attendance
    WHERE user_id = ? AND MONTH(date) = ? AND YEAR(date) = ?
    ORDER BY date DESC, punch_in DESC
");
$stmt->bind_param("iii", $user_id, $filter_month, $filter_year);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

foreach ($records as &$r) {
    if ($r['punch_in'] && $r['punch_out']) {
        $in_ts = strtotime($r['date'] . ' ' . $r['punch_in']);
        $out_ts = strtotime($r['date'] . ' ' . $r['punch_out']);
        if ($out_ts < $in_ts) {
            $out_ts = strtotime(date('Y-m-d', strtotime($r['date'] . ' +1 day')) . ' ' . $r['punch_out']);
        }
        $seconds = max(0, $out_ts - $in_ts);
        $r['total_hours'] = round($seconds / 3600, 2);
        $r['total_hours_formatted'] = sprintf('%dh %dm', floor($seconds / 3600), floor(($seconds % 3600) / 60));
    } else {
        $r['total_hours'] = 0;
        $r['total_hours_formatted'] = '-';
    }
}
unset($r);

// The company attendance policy decides the day actually earned, so the app
// shows the same Present / Half Day / Absent the payslip will pay for.
require_once '../config/attendance_policy.php';
$day_status = [];
if (!empty($records)) {
    $dates = array_column($records, 'date');
    $day_status = attendance_day_results($conn, $user_id, min($dates), max($dates));
}
foreach ($records as &$r) {
    $d = $day_status[$r['date']] ?? null;
    $r['day_status'] = $d['status'] ?? null;   // Present | Half Day | Absent | Week Off | Leave | OD | Comp Off
    $r['day_credit'] = $d['credit'] ?? null;   // 1 | 0.5 | 0
    $r['day_note']   = $d['note']   ?? null;
}
unset($r);

echo json_encode([
    'success' => true,
    'month' => $filter_month,
    'year' => $filter_year,
    'count' => count($records),
    'days' => $day_status,
    'records' => $records
]);
