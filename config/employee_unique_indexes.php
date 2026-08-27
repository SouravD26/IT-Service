<?php
/**
 * Adds the database-level guarantee behind admin/employee_unique_check.php:
 * no two employees may share a mobile number, Aadhar number, PAN number or
 * bank account number.
 *
 * The PHP checks catch duplicates while the admin types; this index catches
 * anything that slips past them - a race between two admins saving at once,
 * a bulk import, a direct SQL edit.
 *
 * Run once from the browser:  /config/employee_unique_indexes.php
 * Safe to re-run: it skips indexes that already exist, and refuses to add one
 * while real duplicates are still in the table (it lists them instead).
 *
 * Blank values are normalised to NULL first, because MySQL treats every NULL
 * as distinct - so the many employees with no PAN on file stay legal, while
 * two employees with the SAME PAN do not.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../admin/employee_unique_check.php';

header('Content-Type: text/plain; charset=utf-8');

$fields = employee_unique_fields();
$report = [];
$blocked = false;

foreach ($fields as $column => $label) {
    // Does the column exist at all?
    $res = $conn->query("SHOW COLUMNS FROM users LIKE '" . $conn->real_escape_string($column) . "'");
    if (!$res || $res->num_rows === 0) {
        $report[] = "SKIP  $label - users.$column does not exist";
        continue;
    }

    $index = 'uniq_users_' . $column;

    // Already done on a previous run?
    $res = $conn->query("SHOW INDEX FROM users WHERE Key_name = '" . $conn->real_escape_string($index) . "'");
    if ($res && $res->num_rows > 0) {
        $report[] = "OK    $label - unique index $index already present";
        continue;
    }

    // Blank means "not on file", not "the empty value" - only NULL expresses
    // that in a unique index, so normalise before indexing.
    $conn->query("UPDATE users SET $column = NULL WHERE $column = '' OR TRIM($column) = ''");

    // A unique index cannot be created over existing duplicates. Report them
    // so an admin can fix the data, rather than failing with a bare SQL error.
    $dupes = $conn->query(
        "SELECT $column AS value, COUNT(*) AS n,
                GROUP_CONCAT(CONCAT(name, ' (', COALESCE(employee_id, 'no id'), ')') SEPARATOR ', ') AS who
         FROM users
         WHERE $column IS NOT NULL
         GROUP BY $column
         HAVING n > 1"
    );

    if ($dupes && $dupes->num_rows > 0) {
        $blocked = true;
        $report[] = "FAIL  $label - " . $dupes->num_rows . " duplicate value(s) must be fixed first:";
        while ($row = $dupes->fetch_assoc()) {
            $report[] = "        \"{$row['value']}\" used by {$row['n']} employees: {$row['who']}";
        }
        continue;
    }

    if ($conn->query("ALTER TABLE users ADD UNIQUE KEY $index ($column)")) {
        $report[] = "ADDED $label - unique index $index created";
    } else {
        $blocked = true;
        $report[] = "FAIL  $label - " . $conn->error;
    }
}

echo "Employee unique-field indexes\n";
echo str_repeat('=', 60) . "\n";
echo implode("\n", $report) . "\n";
echo str_repeat('=', 60) . "\n";
echo $blocked
    ? "Some indexes were not created. Fix the duplicates listed above, then re-run this script.\n"
    : "All done. Duplicate mobile / Aadhar / PAN / bank account numbers are now impossible.\n";
