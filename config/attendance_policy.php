<?php
/**
 * Company attendance policy: how raw punches become a paid day.
 *
 * Two rules the company set:
 *   1. A day with only a punch in and no punch out counts as ABSENT.
 *   2. A day needs at least 5 worked hours to earn HALF DAY pay; a full day
 *      needs the employee's full shift length.
 *   3. Sandwich rule: a week off loses its pay when the employee is absent on
 *      the working day before it AND the working day after it. For the usual
 *      Sunday week off that is absent Saturday + absent Monday, which makes the
 *      Sunday absent too instead of a paid holiday.
 *
 * Both numbers are editable at admin/attendance_policy.php - nothing here is
 * hard-coded. Every screen, export, API and the payslip calculation goes
 * through attendance_day_results() so they can never disagree.
 */

/** Creates the single-row policy table on first use. */
function attendance_ensure_policy_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS attendance_policy (
            id TINYINT PRIMARY KEY DEFAULT 1,
            single_punch_absent TINYINT(1) NOT NULL DEFAULT 1,
            half_day_min_hours DECIMAL(4,2) NOT NULL DEFAULT 5.00,
            full_day_basis ENUM('shift','fixed') NOT NULL DEFAULT 'shift',
            full_day_fixed_hours DECIMAL(4,2) NOT NULL DEFAULT 8.00,
            sandwich_absent TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $conn->query("INSERT IGNORE INTO attendance_policy (id) VALUES (1)");

    // Added after the table shipped - bring older installs up to date
    $col = $conn->query("SHOW COLUMNS FROM attendance_policy LIKE 'sandwich_absent'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE attendance_policy ADD COLUMN sandwich_absent TINYINT(1) NOT NULL DEFAULT 1 AFTER full_day_fixed_hours");
    }
}

/** The current policy, read once per request. */
function attendance_policy(mysqli $conn): array
{
    static $policy = null;
    if ($policy !== null) {
        return $policy;
    }
    attendance_ensure_policy_table($conn);
    $row = $conn->query("SELECT * FROM attendance_policy WHERE id = 1")->fetch_assoc();

    $policy = [
        'single_punch_absent'  => (bool)($row['single_punch_absent'] ?? 1),
        'half_day_min_hours'   => (float)($row['half_day_min_hours'] ?? 5),
        'full_day_basis'       => $row['full_day_basis'] ?? 'shift',
        'full_day_fixed_hours' => (float)($row['full_day_fixed_hours'] ?? 8),
        'sandwich_absent'      => (bool)($row['sandwich_absent'] ?? 1),
    ];
    return $policy;
}

/**
 * Length in hours of a shift stored as "09:00 AM - 06:00 PM".
 * Handles shifts that run past midnight. Returns null if unparseable.
 */
function attendance_shift_hours(?string $shift_time): ?float
{
    if (!$shift_time || strpos($shift_time, '-') === false) {
        return null;
    }
    [$from, $to] = array_map('trim', explode('-', $shift_time, 2));
    $start = strtotime($from);
    $end   = strtotime($to);
    if ($start === false || $end === false) {
        return null;
    }
    if ($end <= $start) {
        $end += 86400; // overnight shift
    }
    return round(($end - $start) / 3600, 2);
}

/**
 * Hours that earn a full day for this employee, per the policy.
 */
function attendance_full_day_hours(array $policy, ?string $shift_time): float
{
    if ($policy['full_day_basis'] === 'fixed') {
        return $policy['full_day_fixed_hours'];
    }
    $shift = attendance_shift_hours($shift_time);
    // No usable shift on the employee - fall back to the fixed value
    return $shift ?? $policy['full_day_fixed_hours'];
}

/**
 * Applies the policy to one day's punch sessions.
 *
 * @param array $sessions rows with punch_in / punch_out for that date
 * @return array{status:string,credit:float,hours:float,note:string}
 */
function attendance_evaluate_day(array $sessions, array $policy, float $fullDayHours): array
{
    if (empty($sessions)) {
        return ['status' => 'Absent', 'credit' => 0.0, 'hours' => 0.0, 'note' => 'No punch record'];
    }

    $seconds = 0;
    $completed = 0;
    $dangling = 0;

    foreach ($sessions as $s) {
        if (empty($s['punch_in'])) {
            continue;
        }
        if (empty($s['punch_out'])) {
            $dangling++;
            continue;
        }
        $in  = strtotime($s['punch_in']);
        $out = strtotime($s['punch_out']);
        if ($out <= $in) {
            $out += 86400; // session crossed midnight
        }
        $seconds += ($out - $in);
        $completed++;
    }

    $hours = round($seconds / 3600, 2);

    // Rule 1: punched in but never out, with no other completed session
    if ($completed === 0) {
        if ($dangling > 0 && $policy['single_punch_absent']) {
            return ['status' => 'Absent', 'credit' => 0.0, 'hours' => 0.0, 'note' => 'Single punch - no punch out'];
        }
        return ['status' => 'Absent', 'credit' => 0.0, 'hours' => 0.0, 'note' => 'No completed session'];
    }

    $note = $dangling > 0 ? "{$hours}h worked (an unclosed session was ignored)" : "{$hours}h worked";

    // Rule 2: full shift = full day, at least the half-day minimum = half day
    if ($hours >= $fullDayHours) {
        return ['status' => 'Present', 'credit' => 1.0, 'hours' => $hours, 'note' => $note];
    }
    if ($hours >= $policy['half_day_min_hours']) {
        return ['status' => 'Half Day', 'credit' => 0.5, 'hours' => $hours, 'note' => $note];
    }
    return [
        'status' => 'Absent',
        'credit' => 0.0,
        'hours'  => $hours,
        'note'   => "{$hours}h worked - under the {$policy['half_day_min_hours']}h half-day minimum",
    ];
}

/**
 * Derived status for every day in a range, for one employee.
 *
 * Week offs, approved paid leave, on-duty and comp-off days are paid in full
 * regardless of punches, exactly as the payroll always treated them. Everything
 * else is decided by the policy above.
 *
 * The sandwich rule is applied afterwards by attendance_day_results(), which is
 * what callers should use - this pass decides each day on its own.
 *
 * @return array<string,array{status:string,credit:float,hours:float,note:string}> keyed by Y-m-d
 */
function attendance_base_day_results(mysqli $conn, int $user_id, string $start, string $end): array
{
    $policy = attendance_policy($conn);

    $ust = $conn->prepare("SELECT week_off, shift_time, location FROM users WHERE id = ?");
    $ust->bind_param("i", $user_id);
    $ust->execute();
    $user = $ust->get_result()->fetch_assoc() ?: [];
    $ust->close();

    $weekOff      = $user['week_off'] ?? '';
    $fullDayHours = attendance_full_day_hours($policy, $user['shift_time'] ?? null);

    // Days the superadmin declared off for this employee's whole project
    require_once __DIR__ . '/project_holiday.php';
    $holidays = project_holidays_between($conn, (string)($user['location'] ?? ''), $start, $end);

    // All punch sessions in range, grouped by date
    $byDate = [];
    $ast = $conn->prepare("SELECT date, punch_in, punch_out FROM attendance WHERE user_id=? AND date BETWEEN ? AND ? ORDER BY id");
    $ast->bind_param("iss", $user_id, $start, $end);
    $ast->execute();
    foreach ($ast->get_result() as $r) {
        $byDate[$r['date']][] = $r;
    }
    $ast->close();

    // Days paid regardless of punches
    $odDates = [];
    $ost = $conn->prepare("SELECT DISTINCT od_date FROM od_records WHERE user_id=? AND od_date BETWEEN ? AND ?");
    $ost->bind_param("iss", $user_id, $start, $end);
    $ost->execute();
    foreach ($ost->get_result() as $r) { $odDates[$r['od_date']] = true; }
    $ost->close();

    $adjDates = [];
    $cst = $conn->prepare("SELECT DISTINCT comp_off_date FROM comp_off_requests WHERE user_id=? AND comp_off_date BETWEEN ? AND ?");
    $cst->bind_param("iss", $user_id, $start, $end);
    $cst->execute();
    foreach ($cst->get_result() as $r) { $adjDates[$r['comp_off_date']] = true; }
    $cst->close();

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

    $results = [];
    for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        $sessions = $byDate[$date] ?? [];

        if (isset($paidLeaveDates[$date])) {
            $results[$date] = ['status' => 'Leave', 'credit' => 1.0, 'hours' => 0.0, 'note' => 'Approved paid leave'];
        } elseif (isset($odDates[$date])) {
            $results[$date] = ['status' => 'OD', 'credit' => 1.0, 'hours' => 0.0, 'note' => 'On duty'];
        } elseif (isset($adjDates[$date])) {
            $results[$date] = ['status' => 'Comp Off', 'credit' => 1.0, 'hours' => 0.0, 'note' => 'Comp off adjusted'];
        } elseif (date('l', $ts) === $weekOff || isset($holidays[$date])) {
            // A day off. It is paid whether or not they came in, but record what
            // they actually worked: the sandwich rule must not void a day the
            // employee turned up for, and payroll pays it again as extra duty.
            $worked = $sessions ? attendance_evaluate_day($sessions, $policy, $fullDayHours) : null;
            $workedCredit = $worked ? $worked['credit'] : 0.0;

            // Their own week off wins the label - it is already a paid day, and
            // it is the more accurate thing to show this particular employee.
            $isWeekOff = date('l', $ts) === $weekOff;
            $note = $isWeekOff ? 'Weekly off' : $holidays[$date];
            if ($workedCredit > 0) {
                $note .= " - worked {$worked['hours']}h (extra duty)";
            }

            $results[$date] = [
                'status'        => $isWeekOff ? 'Week Off' : 'Holiday',
                'credit'        => 1.0,
                'hours'         => $worked ? $worked['hours'] : 0.0,
                'note'          => $note,
                'worked_credit' => $workedCredit,
            ];
        } else {
            $results[$date] = attendance_evaluate_day($sessions, $policy, $fullDayHours);
        }
    }

    return $results;
}

/**
 * Applies the sandwich rule in place, over a run of days in date order.
 *
 * A week off is only paid if the employee actually worked around it. When the
 * working day immediately before a week off AND the one immediately after it
 * are both Absent, the week off is treated as Absent too - the employee took
 * the holiday as part of an unapproved break rather than earning it.
 *
 * Consecutive week offs (a Saturday + Sunday pair, say) are handled as one
 * block: the whole block turns absent only if the days flanking the block are.
 * Approved leave, OD and comp off never trigger it - only a real Absent does.
 *
 * @param array<string,array> $results keyed by Y-m-d, must cover a padded range
 *                                     so the days flanking each block are present
 */
function attendance_apply_sandwich_rule(array &$results): void
{
    $dates = array_keys($results);
    sort($dates);
    $n = count($dates);

    for ($i = 0; $i < $n; $i++) {
        if ($results[$dates[$i]]['status'] !== 'Week Off') {
            continue;
        }
        // Extend to the end of this block of consecutive week offs
        $j = $i;
        while ($j + 1 < $n && $results[$dates[$j + 1]]['status'] === 'Week Off') {
            $j++;
        }

        // A day off the employee actually worked is earned, not taken as part
        // of a break, so the whole block keeps its pay.
        $worked = false;
        for ($k = $i; $k <= $j; $k++) {
            if (($results[$dates[$k]]['worked_credit'] ?? 0) > 0) {
                $worked = true;
                break;
            }
        }

        // Both flanking days must exist in range and both must be Absent
        $before = $i - 1;
        $after  = $j + 1;
        if (!$worked && $before >= 0 && $after < $n
            && $results[$dates[$before]]['status'] === 'Absent'
            && $results[$dates[$after]]['status']  === 'Absent') {

            $prevDay = date('D', strtotime($dates[$before]));
            $nextDay = date('D', strtotime($dates[$after]));
            for ($k = $i; $k <= $j; $k++) {
                $results[$dates[$k]] = [
                    'status' => 'Absent',
                    'credit' => 0.0,
                    'hours'  => 0.0,
                    'note'   => "Sandwich leave - absent on $prevDay and $nextDay, so this week off is not paid",
                ];
            }
        }

        $i = $j; // skip past the block we just examined
    }
}

/**
 * Derived status for every day in a range, for one employee.
 *
 * This is the function every screen, export, API and payslip calls. It runs the
 * per-day evaluation over a padded range so the sandwich rule can see the days
 * just outside the window, then returns only the dates that were asked for.
 *
 * @return array<string,array{status:string,credit:float,hours:float,note:string}> keyed by Y-m-d
 */
function attendance_day_results(mysqli $conn, int $user_id, string $start, string $end): array
{
    $policy = attendance_policy($conn);

    if (!$policy['sandwich_absent']) {
        return attendance_base_day_results($conn, $user_id, $start, $end);
    }

    // A week off sitting on the edge of the window still needs the day either
    // side of it to be judged, so widen the range and trim back afterwards.
    $padStart = date('Y-m-d', strtotime($start . ' -7 days'));
    $padEnd   = date('Y-m-d', strtotime($end . ' +7 days'));

    $padded = attendance_base_day_results($conn, $user_id, $padStart, $padEnd);
    attendance_apply_sandwich_rule($padded);

    $results = [];
    for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
        $date = date('Y-m-d', $ts);
        if (isset($padded[$date])) {
            $results[$date] = $padded[$date];
        }
    }
    return $results;
}

/**
 * The same evaluation for a single day - convenient for attendance screens.
 */
function attendance_day_result(mysqli $conn, int $user_id, string $date): array
{
    $all = attendance_day_results($conn, $user_id, $date, $date);
    return $all[$date] ?? ['status' => 'Absent', 'credit' => 0.0, 'hours' => 0.0, 'note' => 'No punch record'];
}

/**
 * Derived statuses for many (employee, date) pairs at once.
 *
 * Listing screens are paginated and mix employees, so this groups the pairs by
 * employee and evaluates each employee's date span in one pass rather than
 * querying per row.
 *
 * @param array $pairs list of ['user_id' => int, 'date' => 'Y-m-d']
 * @return array<string,array> keyed "userId|date"
 */
function attendance_status_map(mysqli $conn, array $pairs): array
{
    $byUser = [];
    foreach ($pairs as $p) {
        $uid  = (int)$p['user_id'];
        $date = $p['date'];
        if (!$uid || !$date) continue;
        $byUser[$uid][] = $date;
    }

    $map = [];
    foreach ($byUser as $uid => $dates) {
        $results = attendance_day_results($conn, $uid, min($dates), max($dates));
        foreach ($dates as $d) {
            if (isset($results[$d])) {
                $map[$uid . '|' . $d] = $results[$d];
            }
        }
    }
    return $map;
}

/** Bootstrap badge class for a derived status, so screens style it alike. */
function attendance_status_badge(string $status): string
{
    switch ($status) {
        case 'Present':  return 'bg-success';
        case 'Half Day': return 'bg-warning text-dark';
        case 'Week Off': return 'bg-info';
        case 'Holiday':  return 'bg-info text-dark';
        case 'Leave':    return 'bg-primary';
        case 'OD':       return 'bg-secondary';
        case 'Comp Off': return 'bg-secondary';
        default:         return 'bg-danger';
    }
}
