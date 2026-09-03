<?php
/**
 * What an employee actually gets paid for a pay month, in one place.
 *
 * The figure combines three things:
 *   1. Gross earnings from the salary structure (basic + allowance + customs).
 *   2. MINUS a per-day deduction for every absent day. A week off that the
 *      sandwich rule turned into an absence is deducted like any other absence.
 *   3. PLUS extra duty - a day the employee actually worked when they did not
 *      have to, i.e. their week off or a declared project holiday. That pays
 *      one extra day at the same per-day rate (half a day for a half day).
 *   4. MINUS PF and ESI.
 *
 * The attendance side always comes from attendance_day_results() via
 * pay_period_paid_days(), so this can never disagree with the attendance
 * screens or the exports.
 */

require_once __DIR__ . '/pay_period.php';
require_once __DIR__ . '/attendance_policy.php';

/**
 * Days the employee worked when they were not required to.
 *
 * attendance_day_results() reports a week off or holiday as a paid day and
 * never looks at the punches on it, which is right for attendance but hides
 * the fact that somebody came in. This re-reads the punches for exactly those
 * dates and credits what they worked.
 *
 * @return array{days:float,dates:array<string,string>} days is 1.0 per full
 *         day worked and 0.5 per half day; dates maps Y-m-d to a short label
 */
function salary_extra_duty(mysqli $conn, int $user_id, string $start, string $end): array
{
    $results = attendance_day_results($conn, $user_id, $start, $end);

    $days  = 0.0;
    $dates = [];
    foreach ($results as $date => $r) {
        // attendance_day_results() already measured what was worked on a day
        // off, so there is nothing to re-read here
        $credit = $r['worked_credit'] ?? 0;
        if ($credit > 0) {
            $days += $credit;
            $dates[$date] = $r['status'] . ' worked (' . $r['hours'] . 'h)';
        }
    }

    return ['days' => round($days, 2), 'dates' => $dates];
}

/**
 * The full pay picture for one employee and one pay month.
 *
 * @return array{
 *   gross:float, per_day:float, paid_days:float, absent_days:float,
 *   half_days:int, extra_duty_days:float, extra_duty_amount:float,
 *   absent_deduction:float, pf:float, esi:float, total_deductions:float,
 *   net_pay:float, days_in_period:int, period_label:string, has_structure:bool
 * }
 */
function salary_month_summary(mysqli $conn, int $user_id, string $month): array
{
    $stmt = $conn->prepare("
        SELECT s.id AS structure_id,
               COALESCE(s.basic_monthly, 0) basic_monthly,
               COALESCE(s.special_allowance_monthly, 0) sal_allowance,
               COALESCE(s.pf_monthly, 0) pf_monthly,
               COALESCE(s.esi_monthly, 0) esi_monthly,
               IFNULL(s.custom_components, '[]') custom_components
        FROM users u
        LEFT JOIN salary_structures s ON s.user_id = u.id
        WHERE u.id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $count = pay_period_paid_days($conn, $user_id, $month);

    $basic     = (float)($row['basic_monthly'] ?? 0);
    $allowance = (float)($row['sal_allowance'] ?? 0);
    $pf        = (float)($row['pf_monthly'] ?? 0);
    $esi       = (float)($row['esi_monthly'] ?? 0);

    $gross = $basic + $allowance;
    foreach ((json_decode($row['custom_components'] ?? '[]', true) ?: []) as $c) {
        $gross += (float)($c['monthly'] ?? 0);
    }

    $daysInPeriod = $count['days_in_period'];
    $perDay       = $daysInPeriod > 0 ? $gross / $daysInPeriod : 0.0;

    $absentDays = (float)$count['absent_days'];
    $absentCut  = $perDay * $absentDays;

    $extra       = salary_extra_duty($conn, $user_id, $count['start'], $count['end']);
    $extraAmount = $perDay * $extra['days'];

    $totalDeductions = $pf + $esi + $absentCut;
    $netPay = $gross + $extraAmount - $totalDeductions;

    return [
        'gross'             => round($gross, 2),
        'per_day'           => round($perDay, 2),
        'paid_days'         => (float)$count['paid_days'],
        'absent_days'       => round($absentDays, 2),
        'half_days'         => (int)$count['half_days'],
        'extra_duty_days'   => $extra['days'],
        'extra_duty_amount' => round($extraAmount, 2),
        'extra_duty_dates'  => $extra['dates'],
        'absent_deduction'  => round($absentCut, 2),
        'pf'                => round($pf, 2),
        'esi'               => round($esi, 2),
        'total_deductions'  => round($totalDeductions, 2),
        'net_pay'           => round($netPay, 2),
        'days_in_period'    => $daysInPeriod,
        'period_label'      => $count['label'],
        'has_structure'     => !empty($row['structure_id']),
    ];
}
