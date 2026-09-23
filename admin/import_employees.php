<?php
session_start();
include('../config/db.php');
require_once '../vendor/autoload.php';
require_once __DIR__ . '/employee_excel_format.php';
require_once __DIR__ . '/employee_unique_check.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Maximum number of employees admin/suparadmin are allowed to add. Raise this value if a higher limit is ever needed.
define('MAX_EMPLOYEES_LIMIT', 60);

// Make sure every column the Excel format maps to actually exists
$columns_to_add = [
    'employee_id'         => 'VARCHAR(50)',
    'company'             => 'VARCHAR(100)',
    'shift_time'          => 'VARCHAR(50)',
    'location'            => 'VARCHAR(100)',
    'date_of_joining'     => 'DATE',
    'date_of_exit'        => 'DATE',
    'status'              => "ENUM('Working', 'Resign') DEFAULT 'Working'",
    'sex'                 => "ENUM('Male', 'Female', 'Other')",
    'week_off'            => "ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')",
    'password_set'        => 'BOOLEAN DEFAULT FALSE',
    'aadhar_number'       => 'VARCHAR(20)',
    'pan_number'          => 'VARCHAR(20)',
    'family_member_name'  => 'VARCHAR(100)',
    'alternate_number'    => 'VARCHAR(20)',
    'address'             => 'TEXT',
    'bank_account_number' => 'VARCHAR(30)',
    'bank_ifsc_code'      => 'VARCHAR(15)',
];
foreach ($columns_to_add as $col_name => $col_type) {
    $check_col = $conn->query("SHOW COLUMNS FROM users LIKE '$col_name'");
    if ($check_col && $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN $col_name $col_type");
    }
}

$excel_columns = employee_excel_columns();          // header label => db column
$header_labels = array_keys($excel_columns);

/** Normalise a header cell so "Phone No." and "phone number" both match. */
function norm_header($value): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$value));
}

// Older sheets / alternate wordings that should still map onto the same DB columns
$header_aliases = [
    'particulars'         => 'name',
    'employeename'        => 'name',
    'empid'               => 'employee_id',
    'slno'                => 'employee_id',
    'dept'                => 'department',
    'doj'                 => 'date_of_joining',
    'phoneno'             => 'phone',
    'mobile'              => 'phone',
    'offday'              => 'week_off',
    'alternativenumber'   => 'alternate_number',
    'alternatenumber'     => 'alternate_number',
    'gender'              => 'sex',
];
// The canonical export headers always win over the legacy aliases above
foreach ($excel_columns as $label => $db_col) {
    $header_aliases[norm_header($label)] = $db_col;
}

/** Convert an Excel serial date or free-text date into Y-m-d (or null). */
function parse_excel_date($raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (is_numeric($raw)) {
        return ExcelDate::excelToDateTimeObject((float)$raw)->format('Y-m-d');
    }
    // d/m/Y and d-m-Y are the formats used in the existing master lists
    foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'm/d/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $raw);
        if ($d && $d->format($fmt) === $raw) return $d->format('Y-m-d');
    }
    $parsed = date_create($raw);
    return $parsed ? $parsed->format('Y-m-d') : null;
}

/** Raw cell value as text - whole numbers keep every digit (no 7.58E+14). */
function cell_text($v): string {
    if ($v === null) return '';
    if (is_float($v) && floor($v) == $v) return sprintf('%.0f', $v);
    return (string)$v;
}

/** Compare two names ignoring case, extra spaces, punctuation and HTML encoding. */
function same_name($a, $b): bool {
    $clean = function ($s) {
        $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
        return preg_replace('/[^a-z]/', '', strtolower($s));
    };
    return $clean($a) !== '' && $clean($a) === $clean($b);
}

/** "Name (Employee ID)" for conflict messages. */
function same_person_label(array $u): string {
    return html_entity_decode($u['name'], ENT_QUOTES, 'UTF-8') . ' (' . ($u['employee_id'] ?: 'no ID') . ')';
}

/** Keep only values the ENUM accepts, otherwise null. */
function match_enum($value, array $allowed): ?string {
    $value = trim((string)$value);
    foreach ($allowed as $a) {
        if (strcasecmp($value, $a) === 0) return $a;
    }
    return null;
}

/**
 * Work out - and, when $apply is true, perform - everything one sheet would do.
 *
 * The same code runs for the preview and for the real import, so what the admin
 * confirms is exactly what gets written. In preview mode nothing touches the DB.
 *
 * @return array{plan: array, errors: string[], notices: string[], imported: int,
 *               updated: int, skipped: int, limit_blocked: int, failed: bool}
 */
function run_employee_import(mysqli $conn, array $rows, array $excel_columns, array $header_aliases, bool $apply): array
{
    $out = ['plan' => [], 'errors' => [], 'notices' => [], 'imported' => 0, 'updated' => 0,
            'skipped' => 0, 'limit_blocked' => 0, 'failed' => false];
    $errors  = &$out['errors'];
    $notices = &$out['notices'];

    // Locate the header row: exported files have it on row 1, older
    // master lists carry a title row above it.
    $header_row_index = null;
    $map = [];  // sheet column index => db column
    foreach ($rows as $i => $row) {
        if ($i > 9) break;
        $candidate = [];
        foreach ($row as $ci => $cell) {
            $key = norm_header($cell);
            if ($key !== '' && isset($header_aliases[$key])) {
                $candidate[$ci] = $header_aliases[$key];
            }
        }
        // A real header row maps the name plus at least two other fields
        if (in_array('name', $candidate, true) && count($candidate) >= 3) {
            $header_row_index = $i;
            $map = $candidate;
            break;
        }
    }

    if ($header_row_index === null) {
        throw new Exception("Could not find a header row. Use the format shown above - export an Excel file first to get a ready-made template.");
    }

    $current_employee_count = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role = 'employee'")->fetch_assoc()['c']);
    $bind_cols = array_values($excel_columns);
    $seen_ids    = [];  // employee_id => sheet row, to catch repeats inside one file
    $seen_phones = [];  // phone => sheet row
    $seen_unique = [];  // "field|value" => sheet row, for Aadhar / PAN / bank account

    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex <= $header_row_index) continue;

        $rowValues = array_filter(array_map('trim', array_map('cell_text', $row)));
        if (empty($rowValues)) continue;

        // Pull each mapped cell into its DB column
        $data = array_fill_keys($bind_cols, '');
        foreach ($map as $ci => $db_col) {
            $data[$db_col] = htmlspecialchars(trim(cell_text($row[$ci] ?? null)));
        }

        $name = $data['name'];
        if ($name === '') { $out['skipped']++; continue; }

        // Normalise / validate individual fields
        $data['date_of_joining']  = parse_excel_date(html_entity_decode($data['date_of_joining']));
        $data['date_of_exit']     = parse_excel_date(html_entity_decode($data['date_of_exit']));
        $data['sex']              = match_enum($data['sex'], ['Male', 'Female', 'Other']);
        $data['status']           = match_enum($data['status'], ['Working', 'Resign']) ?? 'Working';
        $data['week_off']         = match_enum($data['week_off'], ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']);
        $data['phone']            = preg_replace('/\D/', '', $data['phone']);
        $data['alternate_number'] = preg_replace('/\D/', '', $data['alternate_number']);
        $data['aadhar_number']    = preg_replace('/\D/', '', $data['aadhar_number']);
        $data['pan_number']       = strtoupper($data['pan_number']);
        $data['bank_ifsc_code']   = strtoupper($data['bank_ifsc_code']);
        if ($data['status'] !== 'Resign') $data['date_of_exit'] = null;

        $row_no    = $rowIndex + 1;
        $row_label = "Row {$row_no} ({$name})";

        // Reject impossible dates (e.g. a typo like 0226-05-01) instead of storing them
        foreach (['date_of_joining', 'date_of_exit'] as $date_col) {
            $d = $data[$date_col];
            if ($d !== null && ((int)substr($d, 0, 4) < 1950 || (int)substr($d, 0, 4) > (int)date('Y') + 1)) {
                $errors[] = "{$row_label}: " . ucwords(str_replace('_', ' ', $date_col)) . " {$d} is not a valid date - not changed.";
                $data[$date_col] = null;
            }
        }

        // The same employee ID or phone twice in one sheet would make the
        // second row overwrite the first - skip the repeat instead.
        $employee_id = trim($data['employee_id']);
        if ($employee_id !== '' && isset($seen_ids[$employee_id])) {
            $errors[] = "{$row_label}: Employee ID {$employee_id} is repeated in this sheet (first used on row {$seen_ids[$employee_id]}) - row skipped.";
            $out['skipped']++;
            continue;
        }
        if ($data['phone'] !== '' && isset($seen_phones[$data['phone']])) {
            $errors[] = "{$row_label}: phone {$data['phone']} is repeated in this sheet (first used on row {$seen_phones[$data['phone']]}) - row skipped.";
            $out['skipped']++;
            continue;
        }
        if ($employee_id !== '')   $seen_ids[$employee_id]     = $row_no;
        if ($data['phone'] !== '') $seen_phones[$data['phone']] = $row_no;

        // Who does the sheet's employee ID point at, and who owns the phone?
        $by_id = null;
        if ($employee_id !== '') {
            $chk = $conn->prepare("SELECT id, name, phone, employee_id FROM users WHERE employee_id = ? AND role = 'employee'");
            $chk->bind_param("s", $employee_id);
            $chk->execute();
            $by_id = $chk->get_result()->fetch_assoc();
            $chk->close();
        }
        $by_phone = null;
        if ($data['phone'] !== '') {
            $chk = $conn->prepare("SELECT id, name, phone, employee_id, role FROM users WHERE phone = ?");
            $chk->bind_param("s", $data['phone']);
            $chk->execute();
            $by_phone = $chk->get_result()->fetch_assoc();
            $chk->close();
        }

        // Photos and face data are stored against the users row, so updating
        // the wrong row puts this person's name on someone else's face.
        // Only update a row when the sheet clearly describes the same person.
        $existing_id = null;
        if ($by_id && $by_phone && (int)$by_id['id'] !== (int)$by_phone['id']) {
            $errors[] = "{$row_label}: CONFLICT - Employee ID {$employee_id} belongs to " . same_person_label($by_id)
                      . " but phone {$data['phone']} belongs to " . same_person_label($by_phone) . " - row skipped, nothing changed.";
            $out['skipped']++;
            continue;
        } elseif ($by_id) {
            if (!same_name($by_id['name'], $name)) {
                $errors[] = "{$row_label}: CONFLICT - Employee ID {$employee_id} already belongs to " . same_person_label($by_id)
                          . " - row skipped, nothing changed. Use a different Employee ID, or rename the employee on the Employees page.";
                $out['skipped']++;
                continue;
            }
            $existing_id = (int)$by_id['id'];
        } elseif ($by_phone) {
            if ($by_phone['role'] !== 'employee' || !same_name($by_phone['name'], $name)) {
                $errors[] = "{$row_label}: CONFLICT - phone {$data['phone']} already belongs to " . same_person_label($by_phone)
                          . " - row skipped, nothing changed.";
                $out['skipped']++;
                continue;
            }
            $existing_id = (int)$by_phone['id'];
            if ($employee_id !== '' && (string)$by_phone['employee_id'] !== '' && $by_phone['employee_id'] !== $employee_id) {
                $notices[] = "{$row_label}: Employee ID changed from {$by_phone['employee_id']} to {$employee_id} (matched by phone).";
            }
        }

        // A new employee with no ID in the sheet gets a free auto-generated one.
        // An existing employee keeps their stored ID (blank = leave alone).
        if ($existing_id === null && $employee_id === '') {
            $seq = $rowIndex - $header_row_index;
            do {
                $employee_id = 'EMP' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                $chk = $conn->prepare("SELECT id FROM users WHERE employee_id = ?");
                $chk->bind_param("s", $employee_id);
                $chk->execute();
                $taken = $chk->get_result()->num_rows > 0 || isset($seen_ids[$employee_id]);
                $chk->close();
                $seq++;
            } while ($taken);
            $seen_ids[$employee_id] = $row_no;
        }
        $data['employee_id'] = $employee_id;

        // Aadhar / PAN / bank account must be unique across employees and
        // within this sheet. A clash drops just that cell so the rest of the row still imports.
        foreach (['aadhar_number', 'pan_number', 'bank_account_number'] as $unique_field) {
            if ($data[$unique_field] === '') continue;
            $label = employee_unique_fields()[$unique_field];
            $key   = $unique_field . '|' . $data[$unique_field];
            if (isset($seen_unique[$key])) {
                $errors[] = "{$row_label}: {$label} {$data[$unique_field]} is repeated in this sheet (first used on row {$seen_unique[$key]}) - not changed.";
                $data[$unique_field] = '';
                continue;
            }
            $seen_unique[$key] = $row_no;
            $clash = find_duplicate_employee($conn, $unique_field, $data[$unique_field], $existing_id);
            if ($clash) {
                $errors[] = "{$row_label}: {$label} {$data[$unique_field]} already belongs to {$clash['name']} ({$clash['employee_id']}) - not changed.";
                $data[$unique_field] = '';
            }
        }

        // Bind values in the exact order of the Excel format
        $values = [];
        foreach ($bind_cols as $c) {
            $values[] = ($data[$c] === '' ? null : $data[$c]);
        }

        if ($existing_id !== null) {
            // What would actually change? Blank cells leave the stored value alone.
            $cur = $conn->query("SELECT " . implode(', ', $bind_cols) . " FROM users WHERE id = " . (int)$existing_id)->fetch_assoc();
            $changes = [];
            foreach ($bind_cols as $i => $c) {
                if ($values[$i] !== null && (string)$cur[$c] !== (string)$values[$i]) {
                    $changes[$c] = [$cur[$c], $values[$i]];
                }
            }
            $out['plan'][] = ['row' => $row_no, 'name' => $name, 'action' => 'update', 'id' => $existing_id, 'changes' => $changes];

            if ($apply && $changes) {
                $set = implode(', ', array_map(function ($c) { return "$c = COALESCE(?, $c)"; }, $bind_cols));
                $stmt = $conn->prepare("UPDATE users SET $set WHERE id = ?");
                $params = $values;
                $params[] = $existing_id;
                $stmt->bind_param(str_repeat('s', count($values)) . 'i', ...$params);
                if (!$stmt->execute()) {
                    $errors[] = "{$row_label}: " . $stmt->error;
                    $out['failed'] = true;
                }
                $stmt->close();
            }
            $out['updated']++;
        } else {
            // INSERT new employee (subject to the licence limit)
            if ($current_employee_count >= MAX_EMPLOYEES_LIMIT) { $out['limit_blocked']++; continue; }

            // phone is NOT NULL + UNIQUE, so a new employee must bring one
            if ($data['phone'] === '') {
                $errors[] = "{$row_label}: a phone number is required to add a new employee.";
                $out['skipped']++;
                continue;
            }

            $changes = [];
            foreach ($bind_cols as $i => $c) {
                if ($values[$i] !== null) $changes[$c] = [null, $values[$i]];
            }
            $out['plan'][] = ['row' => $row_no, 'name' => $name, 'action' => 'insert', 'id' => null, 'changes' => $changes];

            if ($apply) {
                // Placeholder password - the employee sets their own later
                $placeholder_password = password_hash('!' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                $cols = implode(', ', $bind_cols);
                $ph   = implode(', ', array_fill(0, count($bind_cols), '?'));
                $stmt = $conn->prepare("INSERT INTO users ($cols, password, role, password_set) VALUES ($ph, ?, 'employee', FALSE)");
                $params = $values;
                $params[] = $placeholder_password;
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                if (!$stmt->execute()) {
                    $errors[] = "{$row_label}: " . $stmt->error;
                    $out['failed'] = true;
                }
                $stmt->close();
            }
            $out['imported']++;
            $current_employee_count++;
        }
    }
    return $out;
}

/** Fingerprint of a preview, so Confirm only applies what the admin actually saw. */
function import_plan_hash(array $result): string {
    return md5(serialize([$result['plan'], $result['errors'], $result['limit_blocked']]));
}

/** Load a sheet as stored cell values (not display text - see cell_text()). */
function load_import_rows(string $path): array {
    // Display text would turn a number formatted as a date into "8/23/2504820355",
    // and a date shown as m/d/yyyy would be guessed as d/m.
    return IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
}

$message = "";
$message_type = "";
$imported = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];
$notices  = [];
$preview  = null;   // result of a dry run, shown for confirmation
$import_tmp_dir = sys_get_temp_dir();

// Step 1: upload -> dry run only. The file is parked in the system temp dir
// (not under the web root) until the admin confirms or cancels.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = "Error uploading file. Please try again.";
        $message_type = "danger";
    } elseif (!in_array($ext, ['xlsx', 'xls'])) {
        $message = "Only .xlsx and .xls files are allowed.";
        $message_type = "danger";
    } else {
        try {
            $preview = run_employee_import($conn, load_import_rows($file['tmp_name']), $excel_columns, $header_aliases, false);

            $token = bin2hex(random_bytes(16));
            $parked = $import_tmp_dir . DIRECTORY_SEPARATOR . "emp_import_{$token}.{$ext}";
            if (!move_uploaded_file($file['tmp_name'], $parked)) {
                throw new Exception("Could not store the uploaded file for confirmation.");
            }
            $_SESSION['emp_import'] = ['token' => $token, 'path' => $parked, 'name' => $file['name'], 'hash' => import_plan_hash($preview)];
            $errors  = $preview['errors'];
            $notices = $preview['notices'];
            $message = "PREVIEW ONLY - nothing has been saved yet. Check the changes below, then press Confirm Import.";
            $message_type = "info";
        } catch (Throwable $e) {
            $preview = null;
            $message = "Failed to read Excel file: " . htmlspecialchars($e->getMessage());
            $message_type = "danger";
        }
    }
}

// Step 2: confirm -> back up the users table, then apply inside one transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_token'])) {
    $pending = $_SESSION['emp_import'] ?? null;
    unset($_SESSION['emp_import']);

    if (isset($_POST['cancel_import'])) {
        if ($pending && is_file($pending['path'])) @unlink($pending['path']);
        $message = "Import cancelled - nothing was changed.";
        $message_type = "secondary";
    } elseif (!$pending || !hash_equals($pending['token'], (string)$_POST['confirm_token']) || !is_file($pending['path'])) {
        $message = "This preview has expired. Please upload the file again.";
        $message_type = "warning";
    } else {
        try {
            $rows = load_import_rows($pending['path']);

            // The database may have changed since the preview (another admin, a
            // second tab). If the plan is no longer identical, apply nothing.
            $recheck = run_employee_import($conn, $rows, $excel_columns, $header_aliases, false);
            if (import_plan_hash($recheck) !== $pending['hash']) {
                throw new Exception("Employee data changed after the preview was made. Nothing was saved - please upload the file again and re-check the preview.");
            }

            // Full copy of the users table (incl. photos) so any import can be undone
            $backup_table = 'users_backup_' . date('Ymd_His');
            if (!$conn->query("CREATE TABLE `$backup_table` LIKE users") || !$conn->query("INSERT INTO `$backup_table` SELECT * FROM users")) {
                throw new Exception("Could not create the safety backup ({$conn->error}). Nothing was saved.");
            }

            $conn->begin_transaction();
            $result = run_employee_import($conn, $rows, $excel_columns, $header_aliases, true);
            if ($result['failed']) {
                $conn->rollback();
                $errors = $result['errors'];
                $message = "The import hit a database error, so ALL changes were rolled back - nothing was saved. See the row errors below.";
                $message_type = "danger";
            } else {
                $conn->commit();
                $imported = $result['imported'];
                $updated  = $result['updated'];
                $skipped  = $result['skipped'];
                $errors   = $result['errors'];
                $notices  = $result['notices'];
                $summary  = "Imported {$imported} new employee(s), updated {$updated} existing"
                          . ($skipped > 0 ? ", skipped {$skipped} row(s)" : "") . ".";
                if ($result['limit_blocked'] > 0) {
                    $message = "Employee limit reached (" . MAX_EMPLOYEES_LIMIT . "). {$summary} {$result['limit_blocked']} row(s) were not imported because the limit was reached. Contact the developer to raise this limit.";
                    $message_type = "danger";
                } elseif ($imported > 0 || $updated > 0) {
                    $message = "&#10003; " . $summary;
                    $message_type = "success";
                } else {
                    $message = "No employees imported. {$skipped} row(s) were skipped.";
                    $message_type = "warning";
                }
                $message .= " Backup of the previous data saved as table <code>{$backup_table}</code>.";
            }
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            $message = htmlspecialchars($e->getMessage());
            $message_type = "danger";
        }
        @unlink($pending['path']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Employees</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { background: #f4f6f9; }
        .layout-table th { background: #ffc107; color: #212529; font-weight: 600; white-space: nowrap; }
        .layout-table td, .layout-table th { border: 1px solid #dee2e6; padding: 6px 10px; font-size: 12px; white-space: nowrap; }
        .required-badge { background:#dc3545; color:#fff; font-size:10px; padding:1px 5px; border-radius:3px; margin-left:4px; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container-fluid mt-4" style="max-width:1200px;">

        <!-- Required Excel Format -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center">
                <span>&#128203; Required Excel Format</span>
                <a href="export_employees.php" class="btn btn-sm btn-dark">&#128228; Download Template</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="layout-table w-100 text-center">
                        <thead>
                            <tr>
                                <?php $ci = 0; foreach ($header_labels as $label): ?>
                                    <th>
                                        <?php echo \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(++$ci); ?>
                                        <br><small><?php echo htmlspecialchars($label); ?></small>
                                        <?php if ($label === 'Full Name'): ?><span class="required-badge">Required</span><?php endif; ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-muted">
                                <td colspan="<?php echo count($header_labels); ?>" class="text-start p-2" style="white-space:normal;">
                                    <em>Row 1 is the header row shown above &mdash; it is matched automatically (a title row above it is also allowed).</em>
                                </td>
                            </tr>
                            <tr>
                                <td>EMP0001</td><td>Md Kalamuddin</td><td>kalam@example.com</td><td>8101388042</td>
                                <td>Nasir Uddin</td><td>9830011223</td><td>Male</td><td>Art</td><td>Bina News Agency</td>
                                <td>123456789012</td><td>ABCDE1234F</td><td>General Shift</td><td>Asansol</td>
                                <td>12/08/2017</td><td>Working</td><td></td><td>Sunday</td>
                                <td>12 Main Road, Asansol</td><td>1234567890123</td><td>SBIN0001234</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-muted small">
                &#9888; This is exactly the layout produced by <strong>Export Excel</strong> &mdash; export a file, edit it, and import it straight back.
                Only <strong>Full Name</strong> is required; every other cell may be left blank.
                Rows whose <strong>Employee ID</strong> (or phone number) already exists are <strong>updated</strong>; the rest are added as new employees.
                Gender must be Male / Female / Other, Status must be Working / Resign, and Week Off must be a full day name.
            </div>
        </div>

        <!-- Upload Form -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white fw-bold">
                &#128229; Upload Excel File (.xlsx / .xls)
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-warning">
                        <strong>Row errors:</strong><ul class="mb-0 mt-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if (!empty($notices)): ?>
                    <div class="alert alert-info">
                        <strong>Changes to note:</strong><ul class="mb-0 mt-1">
                        <?php foreach ($notices as $n): ?>
                            <li><?php echo htmlspecialchars($n); ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($preview !== null && isset($_SESSION['emp_import'])):
                    $col_labels = array_flip($excel_columns);
                    $n_insert = count(array_filter($preview['plan'], fn($p) => $p['action'] === 'insert'));
                    $n_change = count(array_filter($preview['plan'], fn($p) => $p['action'] === 'update' && $p['changes']));
                    $n_same   = count(array_filter($preview['plan'], fn($p) => $p['action'] === 'update' && !$p['changes']));
                    $show = function ($v) { return $v === null || $v === '' ? '<em class="text-muted">(blank)</em>' : htmlspecialchars(html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8')); };
                ?>
                    <div class="card border-primary mb-3">
                        <div class="card-header bg-primary text-white fw-bold">
                            Preview: <?php echo htmlspecialchars($_SESSION['emp_import']['name']); ?>
                        </div>
                        <div class="card-body">
                            <p class="mb-2">
                                <span class="badge bg-success"><?php echo $n_insert; ?> new</span>
                                <span class="badge bg-warning text-dark"><?php echo $n_change; ?> will be changed</span>
                                <span class="badge bg-secondary"><?php echo $n_same; ?> unchanged</span>
                                <span class="badge bg-danger"><?php echo $preview['skipped']; ?> skipped</span>
                                <?php if ($preview['limit_blocked']): ?><span class="badge bg-danger"><?php echo $preview['limit_blocked']; ?> over the employee limit</span><?php endif; ?>
                            </p>
                            <?php if ($n_insert + $n_change > 0): ?>
                            <div class="table-responsive" style="max-height:480px;">
                                <table class="table table-sm table-bordered small align-middle">
                                    <thead class="table-light"><tr><th>Row</th><th>Employee</th><th>Action</th><th>Field</th><th>Now</th><th>After import</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($preview['plan'] as $p): if (!$p['changes']) continue; $first = true; ?>
                                        <?php foreach ($p['changes'] as $col => [$old, $new]): ?>
                                        <tr>
                                            <?php if ($first): ?>
                                                <td rowspan="<?php echo count($p['changes']); ?>"><?php echo $p['row']; ?></td>
                                                <td rowspan="<?php echo count($p['changes']); ?>"><?php echo $show($p['name']); ?></td>
                                                <td rowspan="<?php echo count($p['changes']); ?>">
                                                    <?php echo $p['action'] === 'insert' ? '<span class="badge bg-success">NEW</span>' : '<span class="badge bg-warning text-dark">UPDATE</span>'; ?>
                                                </td>
                                            <?php $first = false; endif; ?>
                                            <td><?php echo htmlspecialchars($col_labels[$col] ?? $col); ?></td>
                                            <td class="<?php echo $p['action'] === 'update' ? 'text-danger' : ''; ?>"><?php echo $p['action'] === 'update' ? $show($old) : ''; ?></td>
                                            <td class="text-success fw-semibold"><?php echo $show($new); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <div class="alert alert-secondary mb-2">This file would not change anything.</div>
                            <?php endif; ?>

                            <form method="POST" class="mt-2">
                                <input type="hidden" name="confirm_token" value="<?php echo htmlspecialchars($_SESSION['emp_import']['token']); ?>">
                                <?php if ($n_insert + $n_change > 0): ?>
                                <button type="submit" class="btn btn-danger px-4"
                                        onclick="return confirm('Apply <?php echo $n_insert; ?> new and <?php echo $n_change; ?> changed employee(s)? A backup of the users table is taken first.');">
                                    &#10003; Confirm Import
                                </button>
                                <?php endif; ?>
                                <button type="submit" name="cancel_import" value="1" class="btn btn-secondary ms-2">Cancel</button>
                                <div class="form-text">Rows listed under "Row errors" above are skipped (or that cell is left unchanged) - fix them in the Excel file and upload again if needed.</div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                $__existing_count = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role = 'employee'")->fetch_assoc()['c']);
                $__limit_reached = $__existing_count >= MAX_EMPLOYEES_LIMIT;
                ?>
                <?php if ($__limit_reached): ?>
                    <div class="alert alert-danger">&#9888; Employee limit reached (<?php echo $__existing_count; ?> / <?php echo MAX_EMPLOYEES_LIMIT; ?>). Existing employees can still be updated by import, but no new ones can be added. Contact the developer to raise this limit.</div>
                <?php else: ?>
                    <div class="alert alert-secondary py-2"><?php echo $__existing_count; ?> / <?php echo MAX_EMPLOYEES_LIMIT; ?> employees used. Up to <?php echo MAX_EMPLOYEES_LIMIT - $__existing_count; ?> more can be imported.</div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Excel File</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
                        <div class="form-text">Accepted formats: .xlsx, .xls</div>
                    </div>
                    <button type="submit" class="btn btn-success px-4">&#128269; Upload &amp; Preview (nothing is saved yet)</button>
                    <a href="employees.php" class="btn btn-secondary ms-2">Cancel</a>
                </form>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if (($imported > 0 || $updated > 0) && !empty($errors)): ?>
        Swal.fire({
            icon: 'warning',
            title: 'Import finished with problems',
            text: '<?php echo addslashes(html_entity_decode(strip_tags($message))); ?> <?php echo count($errors); ?> row problem(s) are listed on the page - please review them.',
        });
        <?php elseif ($imported > 0 || $updated > 0): ?>
        Swal.fire({
            icon: 'success',
            title: 'Import Complete',
            text: '<?php echo addslashes(html_entity_decode(strip_tags($message))); ?>',
            confirmButtonText: 'Go to Employees'
        }).then(() => { window.location.href = 'employees.php'; });
        <?php elseif ($message && $message_type === 'danger'): ?>
        Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo addslashes(strip_tags($message)); ?>' });
        <?php endif; ?>
    </script>
</body>
</html>
