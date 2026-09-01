<?php
/**
 * The company's payroll cycle.
 *
 * Pay does NOT follow the calendar month. A pay month runs from the 26th of
 * that month to the 25th of the next one:
 *
 *   August 2026    -> 26 Aug 2026 .. 25 Sep 2026
 *   September 2026 -> 26 Sep 2026 .. 25 Oct 2026
 *
 * Everything that reports paid days or prorates salary goes through here, so
 * admin/salary_slip.php and api/salary_slip.php can never disagree.
 *
 * To move the cycle (e.g. to a 1st-of-month or 21st-to-20th scheme) change
 * PAY_CYCLE_START_DAY alone - nothing else needs editing.
 */

require_once __DIR__ . '/attendance_policy.php';

/** Day of the month a pay period opens on. */
const PAY_CYCLE_START_DAY = 26;

/**
 * The date range covered by a pay month.
 *
 * @param  string $month "YYYY-MM"
 * @return array{start:string,end:string,days:int,label:string,short_label:string}
 */
function pay_period_range(string $month): array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
        $month = date('Y-m');
        preg_match('/^(\d{4})-(\d{2})$/', $month, $m);
    }
    $year  = (int)$m[1];
    $mon   = (int)$m[2];

    $start = sprintf('%04d-%02d-%02d', $year, $mon, PAY_CYCLE_START_DAY);

    // The period closes the day before the next period opens
    $endTs = strtotime('+1 month -1 day', strtotime($start));
    $end   = date('Y-m-d', $endTs);

    $days = (int)((strtotime($end) - strtotime($start)) / 86400) + 1;

    return [
        'start'       => $start,
        'end'         => $end,
        'days'        => $days,
        'label'       => date('d M Y', strtotime($start)) . ' – ' . date('d M Y', $endTs),
        'short_label' => date('d M', strtotime($start)) . ' – ' . date('d M Y', $endTs),
    ];
}

/**
 * Counts paid vs absent days for one employee across a pay period, applying the
 * attendance policy (config/attendance_policy.php).
 *
 * Week off, approved paid leave, on duty and comp off are always full days.
 * Worked days earn 1, 0.5 or 0 depending on hours, so paid_days can be
 * fractional (e.g. 24.5).
 *
 * @return array{paid_days:float,absent_days:float,half_days:int,days_in_period:int,start:string,end:string,label:string,days:array}
 */
function pay_period_paid_days(mysqli $conn, int $user_id, string $month): array
{
    $period = pay_period_range($month);

    // The attendance policy decides each day's credit: 1 full, 0.5 half, 0 absent
    $days = attendance_day_results($conn, $user_id, $period['start'], $period['end']);

    $paidDays = 0.0;
    $halfDays = 0;
    $absentDays = 0;
    foreach ($days as $d) {
        $paidDays += $d['credit'];
        if ($d['credit'] == 0.5) $halfDays++;
        if ($d['credit'] == 0.0) $absentDays++;
    }

    return [
        'paid_days'      => round($paidDays, 2),
        'absent_days'    => round($period['days'] - $paidDays, 2),
        'full_absent_days' => $absentDays,
        'half_days'      => $halfDays,
        'days_in_period' => $period['days'],
        'start'          => $period['start'],
        'end'            => $period['end'],
        'label'          => $period['label'],
        'days'           => $days,
    ];
}
