<?php
session_start();
include('../config/db.php');
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Maximum number of employees admin/suparadmin are allowed to add. Raise this value if a higher limit is ever needed.
define('MAX_EMPLOYEES_LIMIT', 10);

// Ensure designation column exists
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS designation VARCHAR(100) DEFAULT NULL");

$message = "";
$message_type = "";
$imported = 0;
$skipped = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];

    $allowed_types = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/octet-stream'
    ];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

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
            $rows = $sheet->toArray(null, true, true, false);

            // Row 0 = "Employee Master List" title
            // Row 1 = headers (Sl.No, Particulars, Dept, Location, Designation, Company, DOJ, Phone No., Offday)
            // Data starts from Row 2 (0-indexed)

            $current_employee_count = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role = 'employee'")->fetch_assoc()['c']);
            $limit_blocked = 0;

            foreach ($rows as $rowIndex => $row) {
                // Skip title row (index 0) and header row (index 1)
                if ($rowIndex < 2) continue;

                // Skip completely empty rows
                $rowValues = array_filter(array_map('trim', array_map('strval', $row)));
                if (empty($rowValues)) {
                    $skipped++;
                    continue;
                }

                // Stop importing once the employee cap is reached
                if ($current_employee_count >= MAX_EMPLOYEES_LIMIT) {
                    $limit_blocked++;
                    continue;
                }

                // Column mapping (0-indexed):
                // 0=Sl.No, 1=Particulars(Name), 2=Dept, 3=Location, 4=Designation, 5=Company, 6=DOJ, 7=Phone, 8=Offday
                $sl_no       = isset($row[0]) ? trim((string)$row[0]) : '';
                $name        = isset($row[1]) ? htmlspecialchars(trim((string)$row[1])) : '';
                $department  = isset($row[2]) ? htmlspecialchars(trim((string)$row[2])) : '';
                $location    = isset($row[3]) ? htmlspecialchars(trim((string)$row[3])) : '';
                $designation = isset($row[4]) ? htmlspecialchars(trim((string)$row[4])) : '';
                $company     = isset($row[5]) ? htmlspecialchars(trim((string)$row[5])) : '';
                $doj_raw     = isset($row[6]) ? trim((string)$row[6]) : '';
                $phone       = isset($row[7]) ? htmlspecialchars(trim((string)$row[7])) : '';
                $week_off    = isset($row[8]) ? htmlspecialchars(trim((string)$row[8])) : '';

                // Skip if name is completely empty
                if (empty($name)) {
                    $skipped++;
                    continue;
                }

                // Parse date of joining (handle Excel numeric date or string)
                $date_of_joining = null;
                if (!empty($doj_raw)) {
                    if (is_numeric($doj_raw)) {
                        // Excel stores dates as numbers
                        $date_of_joining = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$doj_raw)->format('Y-m-d');
                    } else {
                        $parsed = date_create($doj_raw);
                        $date_of_joining = $parsed ? $parsed->format('Y-m-d') : null;
                    }
                }

                // Use Sl.No as employee_id if numeric, else auto-generate
                $employee_id = '';
                if (!empty($sl_no) && is_numeric($sl_no)) {
                    $employee_id = 'EMP' . str_pad((int)$sl_no, 4, '0', STR_PAD_LEFT);
                } else {
                    $employee_id = 'EMP' . str_pad(($rowIndex + 1), 4, '0', STR_PAD_LEFT);
                }

                // Validate week_off against allowed ENUM values
                $valid_days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
                if (!in_array($week_off, $valid_days)) {
                    $week_off = null;
                }

                // Use placeholder password (employee sets their own later)
                $placeholder_password = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT);

                $stmt = $conn->prepare(
                    "INSERT INTO users 
                        (name, email, password, role, employee_id, department, location, designation, company, date_of_joining, phone, week_off, status, password_set) 
                     VALUES 
                        (?, '', ?, 'employee', ?, ?, ?, ?, ?, ?, ?, ?, 'Working', FALSE)"
                );
                $stmt->bind_param(
                    "ssssssssss",
                    $name,
                    $placeholder_password,
                    $employee_id,
                    $department,
                    $location,
                    $designation,
                    $company,
                    $date_of_joining,
                    $phone,
                    $week_off
                );

                if ($stmt->execute()) {
                    $imported++;
                    $current_employee_count++;
                } else {
                    $errors[] = "Row " . ($rowIndex + 1) . " ({$name}): " . $stmt->error;
                    $skipped++;
                }
                $stmt->close();
            }

            if ($limit_blocked > 0) {
                $message = "Employee limit reached (" . MAX_EMPLOYEES_LIMIT . "). Imported {$imported} employee(s); {$limit_blocked} row(s) were not imported because the limit was reached. Contact the developer to raise this limit.";
                $message_type = "danger";
            } elseif ($imported > 0) {
                $message = "Successfully imported {$imported} employee(s)." . ($skipped > 0 ? " Skipped: {$skipped} row(s)." : "");
                $message_type = "success";
            } elseif ($skipped > 0) {
                $message = "No employees imported. {$skipped} row(s) were skipped (empty name or duplicate).";
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
        .layout-table th { background: #ffc107; color: #212529; font-weight: 600; }
        .layout-table td, .layout-table th { border: 1px solid #dee2e6; padding: 6px 10px; font-size: 13px; }
        .required-badge { background:#dc3545; color:#fff; font-size:10px; padding:1px 5px; border-radius:3px; margin-left:4px; }
        .optional-badge { background:#6c757d; color:#fff; font-size:10px; padding:1px 5px; border-radius:3px; margin-left:4px; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4" style="max-width:860px;">

        <!-- Required Excel Format -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-warning text-dark fw-bold">
                &#128203; Required Excel Format (Employee Master List)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="layout-table w-100 text-center">
                        <thead>
                            <tr>
                                <th>A<br><small>Sl. No.</small></th>
                                <th>B<br><small>Particulars</small> <span class="required-badge">Required</span></th>
                                <th>C<br><small>Dept</small> <span class="optional-badge">Optional</span></th>
                                <th>D<br><small>Location</small> <span class="optional-badge">Optional</span></th>
                                <th>E<br><small>Designation</small> <span class="optional-badge">Optional</span></th>
                                <th>F<br><small>Company</small> <span class="optional-badge">Optional</span></th>
                                <th>G<br><small>DOJ</small> <span class="optional-badge">Optional</span></th>
                                <th>H<br><small>Phone No.</small> <span class="optional-badge">Optional</span></th>
                                <th>I<br><small>Offday</small> <span class="optional-badge">Optional</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-muted">
                                <td colspan="9" class="text-start p-2"><em>Row 1: Title row (e.g., "Employee Master List") — skipped automatically</em></td>
                            </tr>
                            <tr class="text-muted">
                                <td colspan="9" class="text-start p-2"><em>Row 2: Header row (Sl. No., Particulars, Dept…) — skipped automatically</em></td>
                            </tr>
                            <tr>
                                <td>1</td><td>Md Kalamuddin</td><td>Art</td><td>Asansol</td><td>Paginator</td><td>Bina News Agency</td><td>12/08/2017</td><td>8101388042</td><td>Sunday</td>
                            </tr>
                            <tr class="table-light">
                                <td>2</td><td>Abhijit Kumar Singh</td><td>Art</td><td>Kolkata</td><td>Designer</td><td>Sanmarg Pvt. Ltd.</td><td>21/08/2023</td><td>9830494266</td><td>Saturday</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer text-muted small">
                &#9888; Only <strong>Particulars (Name)</strong> is required. All other fields can be blank — the row will still be imported.
                Offday must be a full day name: Monday, Tuesday, Wednesday, Thursday, Friday, Saturday, or Sunday.
            </div>
        </div>

        <!-- Upload Form -->
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white fw-bold">
                &#128229; Upload Excel File (.xlsx / .xls)
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type ?>"><?= $message ?></div>
                <?php endif; ?>
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-warning">
                        <strong>Row errors:</strong><ul class="mb-0 mt-1">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php
                $__existing_count = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role = 'employee'")->fetch_assoc()['c']);
                $__limit_reached = $__existing_count >= MAX_EMPLOYEES_LIMIT;
                ?>
                <?php if ($__limit_reached): ?>
                    <div class="alert alert-danger">&#9888; Employee limit reached (<?= $__existing_count ?> / <?= MAX_EMPLOYEES_LIMIT ?>). Contact the developer to raise this limit before importing more employees.</div>
                <?php else: ?>
                    <div class="alert alert-secondary py-2"><?= $__existing_count ?> / <?= MAX_EMPLOYEES_LIMIT ?> employees used. Up to <?= MAX_EMPLOYEES_LIMIT - $__existing_count ?> more can be imported.</div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Excel File</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required <?= $__limit_reached ? 'disabled' : '' ?>>
                        <div class="form-text">Accepted formats: .xlsx, .xls</div>
                    </div>
                    <button type="submit" class="btn btn-success px-4" <?= $__limit_reached ? 'disabled' : '' ?>>&#128229; Import Employees</button>
                    <a href="employees.php" class="btn btn-secondary ms-2">Cancel</a>
                </form>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($imported > 0): ?>
        Swal.fire({
            icon: 'success',
            title: 'Import Complete',
            text: '<?= addslashes($message) ?>',
            confirmButtonText: 'Go to Employees'
        }).then(() => { window.location.href = 'employees.php'; });
        <?php elseif ($message && $message_type === 'danger'): ?>
        Swal.fire({ icon: 'error', title: 'Error', text: '<?= addslashes($message) ?>' });
        <?php endif; ?>
    </script>
</body>
</html>
