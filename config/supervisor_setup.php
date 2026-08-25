<?php
/**
 * Schema needed by the Supervisor feature.
 *
 * A supervisor is a `users` row with role='supervisor' and a `location`. They
 * punch attendance on behalf of the employees at that location - the staff who
 * do not carry a phone of their own.
 *
 * Safe to call on every request; each step is checked before it runs.
 */
function supervisor_ensure_schema(mysqli $conn): void
{
    // 1. 'supervisor' must be a valid role
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
    $row = $col ? $col->fetch_assoc() : null;
    if ($row && strpos($row['Type'], "'supervisor'") === false) {
        $conn->query(
            "ALTER TABLE users MODIFY COLUMN role
             ENUM('suparadmin','admin','employee','face_operator','supervisor')
             NOT NULL DEFAULT 'employee'"
        );
    }

    // 2. Record which supervisor punched, so a supervisor-made record is
    //    always distinguishable from one the employee made themselves.
    foreach (['punch_in_by', 'punch_out_by'] as $audit_col) {
        $chk = $conn->query("SHOW COLUMNS FROM attendance LIKE '$audit_col'");
        if ($chk && $chk->num_rows === 0) {
            $conn->query("ALTER TABLE attendance ADD COLUMN $audit_col INT(11) DEFAULT NULL");
        }
    }
}

/**
 * The employees a supervisor is responsible for: everyone still working at
 * the supervisor's own location.
 *
 * @return array<int,array> id, name, employee_id, department, shift_time, profile photo flag
 */
function supervisor_employees(mysqli $conn, string $location): array
{
    if (trim($location) === '') {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT id, name, employee_id, department, company, shift_time, phone
         FROM users
         WHERE role = 'employee' AND status = 'Working' AND location = ?
         ORDER BY name"
    );
    $stmt->bind_param("s", $location);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Confirms an employee really is at the supervisor's location before any
 * attendance is written for them.
 */
function supervisor_can_punch_for(mysqli $conn, string $location, int $employee_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, name, employee_id, status
         FROM users
         WHERE id = ? AND role = 'employee' AND location = ?
         LIMIT 1"
    );
    $stmt->bind_param("is", $employee_id, $location);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
