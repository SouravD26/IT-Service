<?php
/**
 * The supervisor punch logic itself, with no assumption about how the caller
 * authenticated.
 *
 * Two front doors use it:
 *   - supervisor/punch.php + supervisor/employee_status.php  (browser, PHP session)
 *   - api/supervisor_punch.php + api/supervisor_employees.php (Flutter app, Bearer token)
 *
 * Both therefore write identical rows, enforce identical rules, and cannot
 * drift apart as the app changes.
 */
require_once __DIR__ . '/attendance_geo.php';
require_once __DIR__ . '/supervisor_setup.php';

/**
 * The location a supervisor covers, or null if the user is not a supervisor.
 */
function supervisor_location(mysqli $conn, int $user_id): ?string
{
    $stmt = $conn->prepare("SELECT location FROM users WHERE id = ? AND role = 'supervisor'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }
    $location = trim((string)$row['location']);
    return $location === '' ? null : $location;
}

/**
 * Today's punch state for one employee, and therefore which action comes next.
 *
 * @return array {success, employee, date, is_punched_in, next_action, sessions}
 */
function supervisor_today_status(mysqli $conn, string $location, int $employee_id): array
{
    $employee = supervisor_can_punch_for($conn, $location, $employee_id);
    if (!$employee) {
        return ['success' => false, 'message' => 'That employee is not allocated to your project.'];
    }

    $date = attendance_shift_date();
    $stmt = $conn->prepare(
        "SELECT punch_in, punch_out FROM attendance
         WHERE user_id = ? AND date = ?
         ORDER BY id"
    );
    $stmt->bind_param("is", $employee_id, $date);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $open = false;
    foreach ($rows as $r) {
        if ($r['punch_in'] !== null && $r['punch_out'] === null) {
            $open = true;
        }
    }

    return [
        'success'       => true,
        'employee'      => [
            'id'          => (int)$employee['id'],
            'name'        => $employee['name'],
            'employee_id' => $employee['employee_id'],
        ],
        'date'          => $date,
        'is_punched_in' => $open,
        'next_action'   => $open ? 'out' : 'in',
        'sessions'      => array_map(function ($r) {
            return [
                'punch_in'  => $r['punch_in']  ? date('h:i A', strtotime($r['punch_in']))  : null,
                'punch_out' => $r['punch_out'] ? date('h:i A', strtotime($r['punch_out'])) : null,
            ];
        }, $rows),
    ];
}

/**
 * Records one punch made by a supervisor on behalf of an employee.
 *
 * Same rules as an employee's own punch (employee/punch_in.php, api/punch.php):
 * photo required, employee's geofence enforced, server-authoritative time. The
 * attendance row belongs to the employee; punch_in_by / punch_out_by record
 * which supervisor made it.
 *
 * @param string $action 'in' or 'out'
 * @param string $selfie base64 data URL
 * @return array {success, type, message, time, date, location}
 */
function supervisor_do_punch(
    mysqli $conn,
    int $supervisor_id,
    string $location,
    int $employee_id,
    string $action,
    string $selfie,
    ?float $lat,
    ?float $lng
): array {
    if (!in_array($action, ['in', 'out'], true)) {
        return ['success' => false, 'message' => 'action must be "in" or "out"'];
    }

    $employee = supervisor_can_punch_for($conn, $location, $employee_id);
    if (!$employee) {
        return ['success' => false, 'message' => 'That employee is not allocated to your project.'];
    }
    if ($employee['status'] === 'Resign') {
        return ['success' => false, 'message' => $employee['name'] . ' is marked as Resign and cannot be punched ' . $action . '.'];
    }
    if (trim($selfie) === '') {
        return ['success' => false, 'message' => 'Please capture a photo before punching ' . $action . '.'];
    }

    // Geofence uses the employee's own rules, exactly as if they punched themselves
    $locCheck = attendance_check_location_allowed($conn, $employee_id, $lat, $lng);
    if (!$locCheck['allowed']) {
        return ['success' => false, 'message' => $locCheck['message']];
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
            return ['success' => false, 'message' => $employee['name'] . ' is already punched in. Punch out first.'];
        }

        $filename = attendance_save_selfie($selfie, 'selfie', $employee_id);
        if (!$filename) {
            return ['success' => false, 'message' => 'Error saving the photo. Please try again.'];
        }

        $stmt = $conn->prepare(
            "INSERT INTO attendance (user_id, date, punch_in, status, selfie_punchin, punch_in_location, punch_in_by)
             VALUES (?, ?, ?, 'Present', ?, ?, ?)"
        );
        $stmt->bind_param("issssi", $employee_id, $date, $current_time, $filename, $location_name, $supervisor_id);
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
            return ['success' => false, 'message' => 'Database error: ' . $err];
        }

        return [
            'success'  => true,
            'type'     => 'punch_in',
            'message'  => $employee['name'] . ' punched IN at ' . date('h:i A', strtotime($current_time)),
            'time'     => $current_time,
            'date'     => $display_date,
            'location' => $location_name,
        ];
    }

    // action === 'out'
    $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL");
    $check->bind_param("is", $employee_id, $date);
    $check->execute();
    $hasOpen = $check->get_result()->num_rows > 0;
    $check->close();

    if (!$hasOpen) {
        return ['success' => false, 'message' => $employee['name'] . ' has no open punch in for today. Punch in first.'];
    }

    $filename = attendance_save_selfie($selfie, 'selfie_punchout', $employee_id);
    if (!$filename) {
        return ['success' => false, 'message' => 'Error saving the photo. Please try again.'];
    }

    $stmt = $conn->prepare(
        "UPDATE attendance
         SET punch_out = ?, selfie_punchout = ?, punch_out_location = ?, punch_out_by = ?
         WHERE user_id = ? AND date = ? AND punch_out IS NULL
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->bind_param("sssiis", $current_time, $filename, $location_name, $supervisor_id, $employee_id, $date);
    $ok = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if (!$ok || $affected === 0) {
        return ['success' => false, 'message' => 'Could not punch out - the session may already be closed.'];
    }

    return [
        'success'  => true,
        'type'     => 'punch_out',
        'message'  => $employee['name'] . ' punched OUT at ' . date('h:i A', strtotime($current_time)),
        'time'     => $current_time,
        'date'     => $display_date,
        'location' => $location_name,
    ];
}

/**
 * The employee list a supervisor works from, each with today's punch state.
 * Shared by the web dashboard and the app's search screen.
 */
function supervisor_employee_list(mysqli $conn, string $location): array
{
    $employees = supervisor_employees($conn, $location);
    if (empty($employees)) {
        return [];
    }

    $date = attendance_shift_date();
    $ids  = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $state = [];
    $res = $conn->query(
        "SELECT user_id,
                MAX(punch_in)  AS last_in,
                MAX(punch_out) AS last_out,
                SUM(punch_in IS NOT NULL AND punch_out IS NULL) AS open_sessions
         FROM attendance
         WHERE date = '" . $conn->real_escape_string($date) . "' AND user_id IN ($ids)
         GROUP BY user_id"
    );
    while ($row = $res->fetch_assoc()) {
        $state[(int)$row['user_id']] = $row;
    }

    return array_map(function ($e) use ($state) {
        $st = $state[(int)$e['id']] ?? null;
        return [
            'id'          => (int)$e['id'],
            'name'        => $e['name'],
            'employee_id' => $e['employee_id'] ?? '',
            'department'  => $e['department'] ?? '',
            'shift_time'  => $e['shift_time'] ?? '',
            'punched_in'  => $st ? ((int)$st['open_sessions'] > 0) : false,
            'next_action' => ($st && (int)$st['open_sessions'] > 0) ? 'out' : 'in',
            'last_in'     => $st && $st['last_in']  ? date('h:i A', strtotime($st['last_in']))  : null,
            'last_out'    => $st && $st['last_out'] ? date('h:i A', strtotime($st['last_out'])) : null,
        ];
    }, $employees);
}
