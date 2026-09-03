<?php
/**
 * Schema needed by the Supervisor feature.
 *
 * A supervisor is a `users` row with role='supervisor' and a `location`. They
 * punch attendance on behalf of the employees allocated to that same value -
 * the staff who do not carry a phone of their own.
 *
 * `location` is the PROJECT an employee is allocated to. The column keeps its
 * original name so no data had to move, but everywhere a person can see it the
 * word is "project". A project has many employees and at most two supervisors;
 * an employee belongs to exactly one project. A supervisor therefore sees only
 * the employees allocated to their own project - never the whole company.
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

/** Most supervisors a single project may have. */
const SUPERVISORS_PER_PROJECT_MAX = 2;

/**
 * How many supervisors are already assigned to a project, so the admin screen
 * can stop a third being added.
 *
 * @param int $ignore_user_id supervisor being edited, excluded from the count
 */
function supervisor_project_count(mysqli $conn, string $project, int $ignore_user_id = 0): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM users
         WHERE role = 'supervisor' AND location = ? AND id <> ?"
    );
    $stmt->bind_param("si", $project, $ignore_user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}

/**
 * The employees a supervisor is responsible for: everyone still working who is
 * allocated to the supervisor's own project.
 *
 * A supervisor with no project gets an empty list rather than every employee
 * whose project is blank - an unassigned account must never see anybody.
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
 * Confirms an employee really is allocated to the supervisor's project before
 * any attendance is written for them.
 *
 * This must scope exactly like supervisor_employees() above, including the
 * blank-project and status guards: anything it lets through can be punched for
 * over the API even when it never appeared in the supervisor's list.
 */
function supervisor_can_punch_for(mysqli $conn, string $location, int $employee_id): ?array
{
    if (trim($location) === '') {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT id, name, employee_id, status
         FROM users
         WHERE id = ? AND role = 'employee' AND status = 'Working' AND location = ?
         LIMIT 1"
    );
    $stmt->bind_param("is", $employee_id, $location);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
