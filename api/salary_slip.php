<?php
/**
 * Salary Slip API for the Flutter app.
 * Mirrors the paid-days computation and net-pay formula in
 * admin/salary_slip.php (server-side "ajax=leaves" block + its client-side
 * generateSlip() calculation), exposed as one JSON response.
 *
 * GET month=2026-08 (defaults to current month)
 *     user_id (admin/suparadmin only — employees always get their own slip)
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/api_auth.php';

$authUser = api_authenticate_flexible($conn);

$requested_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($requested_user_id > 0 && $requested_user_id !== (int)$authUser['id']) {
    if ($authUser['role'] !== 'admin' && $authUser['role'] !== 'suparadmin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not allowed to view another employee\'s salary slip']);
        exit;
    }
    $user_id = $requested_user_id;
} else {
    $user_id = (int)$authUser['id'];
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo json_encode(['success' => false, 'message' => 'month must be in YYYY-MM format']);
    exit;
}
[$y, $m] = explode('-', $month);
$start = "$y-$m-01";
$end = date('Y-m-t', strtotime($start));
$daysInMonth = (int)date('t', strtotime($start));

// Employee + salary structure
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.employee_id, u.department, u.date_of_joining, u.week_off,
           COALESCE(s.basic_monthly, 0) basic_monthly,
           COALESCE(s.special_allowance_monthly, 0) sal_allowance,
           COALESCE(s.pf_monthly, 0) pf_monthly,
           COALESCE(s.esi_monthly, 0) esi_monthly,
           IFNULL(s.pf_calc, '') pf_calc, IFNULL(s.esi_calc, '') esi_calc,
           IFNULL(s.custom_components, '[]') custom_components
    FROM users u
    LEFT JOIN salary_structures s ON s.user_id = u.id
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$emp = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$emp) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Employee not found']);
    exit;
}

$weekOff = $emp['week_off'] ?? '';

// Dates the employee actually attended
$presentDates = [];
$ast = $conn->prepare("SELECT DISTINCT date FROM attendance WHERE user_id=? AND date BETWEEN ? AND ? AND status IN ('Present','Late')");
$ast->bind_param("iss", $user_id, $start, $end);
$ast->execute();
foreach ($ast->get_result() as $r) { $presentDates[$r['date']] = true; }
$ast->close();

// On-Duty dates (paid)
$odDates = [];
$ost = $conn->prepare("SELECT DISTINCT od_date FROM od_records WHERE user_id=? AND od_date BETWEEN ? AND ?");
$ost->bind_param("iss", $user_id, $start, $end);
$ost->execute();
foreach ($ost->get_result() as $r) { $odDates[$r['od_date']] = true; }
$ost->close();

// Comp-off adjusted dates (paid)
$adjDates = [];
$cst = $conn->prepare("SELECT DISTINCT comp_off_date FROM comp_off_requests WHERE user_id=? AND comp_off_date BETWEEN ? AND ?");
$cst->bind_param("iss", $user_id, $start, $end);
$cst->execute();
foreach ($cst->get_result() as $r) { $adjDates[$r['comp_off_date']] = true; }
$cst->close();

// Approved paid leave dates (excludes Unpaid Leave)
$paidLeaveDates = [];
$lst = $conn->prepare("SELECT leave_type, start_date, end_date FROM leave_applications WHERE user_id=? AND status='Approved' AND start_date <= ? AND end_date >= ?");
$lst->bind_param("iss", $user_id, $end, $start);
$lst->execute();
foreach ($lst->get_result() as $r) {
    if ($r['leave_type'] === 'Unpaid Leave') continue;
    $d = max(strtotime($r['start_date']), strtotime($start));
    $lastDay = min(strtotime($r['end_date']), strtotime($end));
    while ($d <= $lastDay) {
        $paidLeaveDates[date('Y-m-d', $d)] = true;
        $d = strtotime('+1 day', $d);
    }
}
$lst->close();

$paidDays = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf('%s-%s-%02d', $y, $m, $d);
    $dayName = date('l', strtotime($dateStr));
    if ($dayName === $weekOff
        || isset($presentDates[$dateStr])
        || isset($odDates[$dateStr])
        || isset($adjDates[$dateStr])
        || isset($paidLeaveDates[$dateStr])) {
        $paidDays++;
    }
}
$absentDays = $daysInMonth - $paidDays;

// Earnings
$basic = (float)$emp['basic_monthly'];
$allowance = (float)$emp['sal_allowance'];
$pf = (float)$emp['pf_monthly'];
$esi = (float)$emp['esi_monthly'];
$customComponents = json_decode($emp['custom_components'] ?: '[]', true) ?: [];

$grossEarnings = $basic + $allowance;
$earnings = [
    ['name' => 'Basic Salary', 'amount' => $basic],
    ['name' => 'Special Allowance', 'amount' => $allowance],
];
foreach ($customComponents as $c) {
    $amt = (float)($c['monthly'] ?? 0);
    $earnings[] = ['name' => $c['name'] ?? 'Component', 'amount' => $amt];
    $grossEarnings += $amt;
}

// Per-day rate from gross earnings (not CTC — see admin/salary_slip.php comment)
$perDaySalary = $daysInMonth > 0 ? $grossEarnings / $daysInMonth : 0;
$leaveDeduction = $perDaySalary * $absentDays;
$totalDeductions = $pf + $esi + $leaveDeduction;

$deductions = [];
if ($pf > 0) $deductions[] = ['name' => 'Provident Fund (' . $emp['pf_calc'] . ')', 'amount' => $pf];
if ($esi > 0) $deductions[] = ['name' => 'ESI (' . $emp['esi_calc'] . ')', 'amount' => $esi];
if ($absentDays > 0) $deductions[] = ['name' => "Leave Deduction ($absentDays day" . ($absentDays > 1 ? 's' : '') . ' absent)', 'amount' => $leaveDeduction];

$netPay = $grossEarnings - $totalDeductions;

echo json_encode([
    'success' => true,
    'employee' => [
        'id' => (int)$emp['id'],
        'name' => $emp['name'],
        'employee_id' => $emp['employee_id'],
        'department' => $emp['department'],
        'date_of_joining' => $emp['date_of_joining'],
    ],
    'month' => $month,
    'days_in_month' => $daysInMonth,
    'paid_days' => $paidDays,
    'absent_days' => $absentDays,
    'earnings' => $earnings,
    'gross_earnings' => round($grossEarnings, 2),
    'deductions' => $deductions,
    'total_deductions' => round($totalDeductions, 2),
    'net_pay' => round($netPay, 2)
]);
