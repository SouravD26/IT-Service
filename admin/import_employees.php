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
define('MAX_EMPLOYEES_LIMIT', 10);

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

/** Keep only values the ENUM accepts, otherwise null. */
function match_enum($value, array $allowed): ?string {
    $value = trim((string)$value);
    foreach ($allowed as $a) {
        if (strcasecmp($value, $a) === 0) return $a;
    }
    return null;
}

$message = "";
$message_type = "";
$imported = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];

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
            $spreadsheet = IOFactory::load($file['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows  = $sheet->toArray(null, true, true, false);

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
            $limit_blocked = 0;

            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex <= $header_row_index) continue;

                $rowValues = array_filter(array_map('trim', array_map('strval', $row)));
                if (empty($rowValues)) continue;

                // Pull each mapped cell into its DB column
                $data = array_fill_keys(array_values($excel_columns), '');
                foreach ($map as $ci => $db_col) {
                    $data[$db_col] = htmlspecialchars(trim((string)($row[$ci] ?? '')));
                }

                $name = $data['name'];
                if ($name === '') { $skipped++; continue; }

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

                // Employee ID: use the sheet value, else auto-generate a free one
                $employee_id = trim($data['employee_id']);
                if ($employee_id === '') {
                    $seq = $rowIndex - $header_row_index;
                    do {
                        $employee_id = 'EMP' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                        $chk = $conn->prepare("SELECT id FROM users WHERE employee_id = ?");
                        $chk->bind_param("s", $employee_id);
                        $chk->execute();
                        $taken = $chk->get_result()->num_rows > 0;
                        $chk->close();
                        $seq++;
                    } while ($taken);
                }
                $data['employee_id'] = $employee_id;

                // Does this employee already exist? (match on employee_id first)
                $existing_id = null;
                $chk = $conn->prepare("SELECT id FROM users WHERE employee_id = ? AND role = 'employee'");
                $chk->bind_param("s", $employee_id);
                $chk->execute();
                if ($r = $chk->get_result()->fetch_assoc()) $existing_id = (int)$r['id'];
                $chk->close();

                // Phone is UNIQUE - make sure it is not held by a different user
                if ($data['phone'] !== '') {
                    $chk = $conn->prepare("SELECT id FROM users WHERE phone = ?");
                    $chk->bind_param("s", $data['phone']);
                    $chk->execute();
                    $owner = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($owner && (int)$owner['id'] !== ($existing_id ?? 0)) {
                        if ($existing_id === null) {
                            $existing_id = (int)$owner['id']; // same person, blank/different ID - update them
                        } else {
                            $errors[] = "Row " . ($rowIndex + 1) . " ({$name}): phone {$data['phone']} already belongs to another employee - phone not changed.";
                            $data['phone'] = '';
                        }
                    }
                }

                // Aadhar / PAN / bank account must be unique across employees.
                // A clash drops just that cell so the rest of the row still imports.
                foreach (['aadhar_number', 'pan_number', 'bank_account_number'] as $unique_field) {
                    if ($data[$unique_field] === '') continue;
                    $clash = find_duplicate_employee($conn, $unique_field, $data[$unique_field], $existing_id);
                    if ($clash) {
                        $label = employee_unique_fields()[$unique_field];
                        $errors[] = "Row " . ($rowIndex + 1) . " ({$name}): {$label} {$data[$unique_field]} already belongs to {$clash['name']} ({$clash['employee_id']}) - not changed.";
                        $data[$unique_field] = '';
                    }
                }

                // Bind values in the exact order of the Excel format
                $bind_cols = array_values($excel_columns);
                $values = [];
                foreach ($bind_cols as $c) {
                    $values[] = ($data[$c] === '' ? null : $data[$c]);
                }

                if ($existing_id !== null) {
                    // UPDATE existing employee - blank cells leave the stored value alone
                    $set = implode(', ', array_map(function ($c) { return "$c = COALESCE(?, $c)"; }, $bind_cols));
                    $stmt = $conn->prepare("UPDATE users SET $set WHERE id = ?");
                    $params = $values;
                    $params[] = $existing_id;
                    $stmt->bind_param(str_repeat('s', count($values)) . 'i', ...$params);
                    if ($stmt->execute()) {
                        $updated++;
                    } else {
                        $errors[] = "Row " . ($rowIndex + 1) . " ({$name}): " . $stmt->error;
                        $skipped++;
                    }
                    $stmt->close();
                } else {
                    // INSERT new employee (subject to the licence limit)
                    if ($current_employee_count >= MAX_EMPLOYEES_LIMIT) { $limit_blocked++; continue; }

                    // phone is NOT NULL + UNIQUE, so a new employee must bring one
                    if ($data['phone'] === '') {
                        $errors[] = "Row " . ($rowIndex + 1) . " ({$name}): a phone number is required to add a new employee.";
                        $skipped++;
                        continue;
                    }

                    // Placeholder password - the employee sets their own later
                    $placeholder_password = password_hash('!' . bin2hex(random_bytes(8)), PASSWORD_BCRYPT);
                    $cols = implode(', ', $bind_cols);
                    $ph   = implode(', ', array_fill(0, count($bind_cols), '?'));
                    $stmt = $conn->prepare("INSERT INTO users ($cols, password, role, password_set) VALUES ($ph, ?, 'employee', FALSE)");
                    $params = $values;
                    $params[] = $placeholder_password;
                    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                    if ($stmt->execute()) {
                        $imported++;
                        $current_employee_count++;
                    } else {
                        $errors[] = "Row " . ($rowIndex + 1) . " ({$name}): " . $stmt->error;
                        $skipped++;
                    }
                    $stmt->close();
                }
            }

            $summary = "Imported {$imported} new employee(s), updated {$updated} existing"
                     . ($skipped > 0 ? ", skipped {$skipped} row(s)" : "") . ".";

            if ($limit_blocked > 0) {
                $message = "Employee limit reached (" . MAX_EMPLOYEES_LIMIT . "). {$summary} {$limit_blocked} row(s) were not imported because the limit was reached. Contact the developer to raise this limit.";
                $message_type = "danger";
            } elseif ($imported > 0 || $updated > 0) {
                $message = "&#10003; " . $summary;
                $message_type = "success";
            } else {
                $message = "No employees imported. {$skipped} row(s) were skipped (empty name).";
                $message_type = "warning";
            }
        } catch (Exception $e) {
            $message = "Failed to read Excel file: " . htmlspecialchars($e->getMessage());
            $message_type = "danger";
        }
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
                    <button type="submit" class="btn btn-success px-4">&#128229; Import Employees</button>
                    <a href="employees.php" class="btn btn-secondary ms-2">Cancel</a>
                </form>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($imported > 0 || $updated > 0): ?>
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
