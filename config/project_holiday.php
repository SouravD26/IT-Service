<?php
/**
 * Project holidays - a day the superadmin declares off for one whole project.
 *
 * Every employee allocated to that project is marked Holiday for that date and
 * is paid for it, without needing any punch. A project is the `location` value
 * on the users row (see config/supervisor_setup.php), so a holiday is simply a
 * project name plus a date.
 *
 * Declared here rather than per employee so one entry covers the whole project,
 * and so attendance_day_results() can apply it everywhere at once - the
 * attendance screens, the exports, the API and the payslip.
 */

/** Creates the holiday table on first use. */
function project_holiday_ensure_schema(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS project_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project VARCHAR(100) NOT NULL,
            holiday_date DATE NOT NULL,
            title VARCHAR(150) NOT NULL DEFAULT 'Holiday',
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_project_date (project, holiday_date),
            KEY idx_date (holiday_date)
        ) ENGINE=InnoDB"
    );
}

/**
 * Holidays for one project inside a date range.
 *
 * @return array<string,string> holiday title keyed by Y-m-d
 */
function project_holidays_between(mysqli $conn, string $project, string $start, string $end): array
{
    if (trim($project) === '') {
        return [];
    }
    project_holiday_ensure_schema($conn);

    $stmt = $conn->prepare(
        "SELECT holiday_date, title FROM project_holidays
         WHERE project = ? AND holiday_date BETWEEN ? AND ?"
    );
    $stmt->bind_param("sss", $project, $start, $end);
    $stmt->execute();

    $out = [];
    foreach ($stmt->get_result() as $r) {
        $out[$r['holiday_date']] = $r['title'];
    }
    $stmt->close();
    return $out;
}

/**
 * Every declared holiday, newest first, optionally limited to one project.
 */
function project_holiday_all(mysqli $conn, string $project = ''): array
{
    project_holiday_ensure_schema($conn);

    if (trim($project) !== '') {
        $stmt = $conn->prepare(
            "SELECT h.*, u.name AS created_by_name
             FROM project_holidays h
             LEFT JOIN users u ON u.id = h.created_by
             WHERE h.project = ?
             ORDER BY h.holiday_date DESC"
        );
        $stmt->bind_param("s", $project);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    $res = $conn->query(
        "SELECT h.*, u.name AS created_by_name
         FROM project_holidays h
         LEFT JOIN users u ON u.id = h.created_by
         ORDER BY h.holiday_date DESC, h.project"
    );
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * How many working employees a holiday would cover, so the admin sees the
 * effect before and after saving.
 */
function project_holiday_employee_count(mysqli $conn, string $project): int
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM users
         WHERE role = 'employee' AND status = 'Working' AND location = ?"
    );
    $stmt->bind_param("s", $project);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
}
