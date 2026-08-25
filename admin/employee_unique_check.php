<?php
/**
 * Uniqueness rules for employee identity fields.
 *
 * These four values identify a real person, so no two employees may share one.
 * Used by admin/employees.php (on save) and admin/check_duplicate.php (live
 * check while the admin is still typing), so both always agree.
 */

/** @return array<string,string> users column => label shown to the admin */
function employee_unique_fields(): array
{
    return [
        'phone'               => 'Mobile Number',
        'aadhar_number'       => 'Aadhar Number',
        'pan_number'          => 'PAN Number',
        'bank_account_number' => 'Bank Account Number',
    ];
}

/**
 * Find the employee already holding $value in $field.
 *
 * @param  int|null $exclude_id  Employee being edited - excluded from the search.
 * @return array|null            ['id','name','employee_id'] of the clashing row, or null.
 */
function find_duplicate_employee(mysqli $conn, string $field, string $value, ?int $exclude_id = null): ?array
{
    // Whitelist the column name - it is interpolated into the SQL
    if (!array_key_exists($field, employee_unique_fields())) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;   // blank is never a clash - the field is optional
    }

    $sql = "SELECT id, name, employee_id FROM users WHERE $field = ?";
    if ($exclude_id !== null) {
        $sql .= " AND id <> ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if ($exclude_id !== null) {
        $stmt->bind_param("si", $value, $exclude_id);
    } else {
        $stmt->bind_param("s", $value);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Check every unique field at once.
 *
 * @param  array    $values      users column => submitted value
 * @param  int|null $exclude_id  Employee being edited.
 * @return string[]              One human-readable message per clash (empty = all clear).
 */
function check_employee_duplicates(mysqli $conn, array $values, ?int $exclude_id = null): array
{
    $errors = [];
    foreach (employee_unique_fields() as $field => $label) {
        $value = trim((string)($values[$field] ?? ''));
        $clash = find_duplicate_employee($conn, $field, $value, $exclude_id);
        if ($clash) {
            $errors[] = "$label \"$value\" is already used by {$clash['name']} ({$clash['employee_id']})";
        }
    }
    return $errors;
}
