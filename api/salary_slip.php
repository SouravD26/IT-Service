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
require_once '../config/pay_period.php';
require_once '../config/salary_summary.php';

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
$period      = pay_period_range($month);
$start       = $period['start'];
$end         = $period['end'];
$daysInMonth = $period['days'];

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

// Paid vs absent days across the pay period (shared with admin/salary_slip.php)
$dayCount    = pay_period_paid_days($conn, $user_id, $month);
$paidDays    = $dayCount['paid_days'];
$absentDays  = $dayCount['absent_days'];
$halfDays    = $dayCount['half_days'];

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

// Extra duty: a week off or project holiday the employee actually worked earns
// one more day at the same rate (half a day for a half day).
$extraDuty       = salary_extra_duty($conn, $user_id, $start, $end);
$extraDutyDays   = $extraDuty['days'];
$extraDutyAmount = $perDaySalary * $extraDutyDays;
if ($extraDutyDays > 0) {
    $earnings[] = ['name' => "Extra Duty ($extraDutyDays day" . ($extraDutyDays > 1 ? 's' : '') . ' worked on an off day)', 'amount' => $extraDutyAmount];
}

$deductions = [];
if ($pf > 0) $deductions[] = ['name' => 'Provident Fund (' . $emp['pf_calc'] . ')', 'amount' => $pf];
if ($esi > 0) $deductions[] = ['name' => 'ESI (' . $emp['esi_calc'] . ')', 'amount' => $esi];
if ($absentDays > 0) $deductions[] = ['name' => "Leave Deduction ($absentDays day" . ($absentDays > 1 ? 's' : '') . ' absent)', 'amount' => $leaveDeduction];

$netPay = $grossEarnings + $extraDutyAmount - $totalDeductions;

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
    'period_start' => $start,
    'period_end' => $end,
    'period_label' => $period['label'],
    'days_in_month' => $daysInMonth,
    'days_in_period' => $daysInMonth,
    'paid_days' => $paidDays,
    'absent_days' => $absentDays,
    'half_days' => $halfDays,
    'extra_duty_days' => $extraDutyDays,
    'extra_duty_amount' => round($extraDutyAmount, 2),
    'earnings' => $earnings,
    'gross_earnings' => round($grossEarnings + $extraDutyAmount, 2),
    'deductions' => $deductions,
    'total_deductions' => round($totalDeductions, 2),
    'net_pay' => round($netPay, 2)
]);
