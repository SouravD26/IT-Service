<?php
session_start();
include('../config/db.php');
require_once __DIR__ . '/../config/attendance_policy.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'suparadmin' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit();
}

// A regular admin needs the right, same pattern as the other admin tools
if ($_SESSION['role'] === 'admin') {
    $__uid = (int)$_SESSION['user_id'];
    $__chk = $conn->prepare("SELECT rights FROM users WHERE id=?");
    $__chk->bind_param("i", $__uid);
    $__chk->execute();
    $__row = $__chk->get_result()->fetch_assoc();
    $__chk->close();
    $__rights = !empty($__row['rights']) ? json_decode($__row['rights'], true) : null;
    if (is_array($__rights) && !in_array('attendance_policy', $__rights)) {
        header("Location: admin_dashboard.php");
        exit();
    }
}

attendance_ensure_policy_table($conn);
$back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';

$message = "";
$message_type = "";

if (isset($_SESSION['flash_message'])) {
    $message      = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_policy'])) {
    $single_punch_absent  = isset($_POST['single_punch_absent']) ? 1 : 0;
    $half_day_min_hours   = (float)($_POST['half_day_min_hours'] ?? 5);
    $full_day_basis       = ($_POST['full_day_basis'] ?? 'shift') === 'fixed' ? 'fixed' : 'shift';
    $full_day_fixed_hours = (float)($_POST['full_day_fixed_hours'] ?? 8);

    if ($half_day_min_hours <= 0 || $half_day_min_hours > 24) {
        $message = "Half day minimum hours must be between 0 and 24";
        $message_type = "danger";
    } elseif ($full_day_fixed_hours <= 0 || $full_day_fixed_hours > 24) {
        $message = "Full day hours must be between 0 and 24";
        $message_type = "danger";
    } elseif ($full_day_basis === 'fixed' && $half_day_min_hours > $full_day_fixed_hours) {
        $message = "Half day minimum cannot be more than the full day hours";
        $message_type = "danger";
    } else {
        $stmt = $conn->prepare(
            "UPDATE attendance_policy
             SET single_punch_absent=?, half_day_min_hours=?, full_day_basis=?, full_day_fixed_hours=?
             WHERE id = 1"
        );
        $stmt->bind_param("idsd", $single_punch_absent, $half_day_min_hours, $full_day_basis, $full_day_fixed_hours);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash_message'] = "✓ Attendance policy saved. It applies to every screen, report and payslip from now on.";
        $_SESSION['flash_message_type'] = "success";
        header("Location: attendance_policy.php");
        exit();
    }
}

$policy = $conn->query("SELECT * FROM attendance_policy WHERE id = 1")->fetch_assoc();

// Shift lengths in use, so the admin can see what "full shift" means in practice
$shift_lengths = [];
$res = $conn->query("SELECT DISTINCT shift_time FROM users WHERE role='employee' AND shift_time IS NOT NULL AND shift_time <> ''");
while ($r = $res->fetch_assoc()) {
    $h = attendance_shift_hours($r['shift_time']);
    if ($h !== null) {
        $shift_lengths[$r['shift_time']] = $h;
    }
}
ksort($shift_lengths);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Policy</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { background: #f4f6f9; }
        .rule-card { border-left: 5px solid #0d6efd; }
        .outcome td { vertical-align: middle; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4 mb-5" style="max-width:900px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">⚖️ Set Attendance Policy</h2>
            <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary">← Back</a>
        </div>

        <div class="alert alert-info">
            These rules turn raw punch records into paid days. They apply everywhere at once —
            attendance screens, exports, and the salary slip.
        </div>

        <form method="POST">
            <!-- Rule 1 -->
            <div class="card rule-card shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="card-title">1. Single punch</h5>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="singlePunch" name="single_punch_absent" value="1"
                               <?php echo $policy['single_punch_absent'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="singlePunch">
                            Mark the day <strong>Absent</strong> when an employee punches in but never punches out
                        </label>
                    </div>
                    <div class="form-text mt-2">
                        Without a punch out there are no worked hours to measure, so the day cannot earn pay.
                        If the day also has a properly closed session, that session still counts and the
                        unclosed one is ignored.
                    </div>
                </div>
            </div>

            <!-- Rule 2 -->
            <div class="card rule-card shadow-sm mb-3">
                <div class="card-body">
                    <h5 class="card-title">2. Half day and full day</h5>
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label">Minimum hours for <strong>half day</strong> pay</label>
                            <div class="input-group">
                                <input type="number" step="0.25" min="0.25" max="24"
                                       class="form-control" name="half_day_min_hours"
                                       value="<?php echo htmlspecialchars($policy['half_day_min_hours']); ?>" required>
                                <span class="input-group-text">hours</span>
                            </div>
                            <div class="form-text">Work less than this and the day is Absent.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">A <strong>full day</strong> is</label>
                            <select class="form-select" name="full_day_basis" id="fullDayBasis" onchange="toggleFixed()">
                                <option value="shift" <?php echo $policy['full_day_basis'] === 'shift' ? 'selected' : ''; ?>>
                                    The employee's own shift length
                                </option>
                                <option value="fixed" <?php echo $policy['full_day_basis'] === 'fixed' ? 'selected' : ''; ?>>
                                    A fixed number of hours for everyone
                                </option>
                            </select>
                        </div>
                        <div class="col-md-5" id="fixedWrap" style="display:none;">
                            <label class="form-label">Fixed full day hours</label>
                            <div class="input-group">
                                <input type="number" step="0.25" min="0.25" max="24"
                                       class="form-control" name="full_day_fixed_hours"
                                       value="<?php echo htmlspecialchars($policy['full_day_fixed_hours']); ?>">
                                <span class="input-group-text">hours</span>
                            </div>
                            <div class="form-text">Also used when an employee has no shift assigned.</div>
                        </div>
                    </div>

                    <?php if (!empty($shift_lengths)): ?>
                        <hr>
                        <div class="small text-muted">
                            <strong>Shifts currently in use:</strong>
                            <?php foreach ($shift_lengths as $name => $h): ?>
                                <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($name); ?> = <?php echo $h; ?>h</span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- What this means -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light fw-bold">What an employee earns</div>
                <div class="card-body p-0">
                    <table class="table mb-0 outcome">
                        <thead class="table-light">
                            <tr><th>Situation</th><th>Status</th><th class="text-end">Paid</th></tr>
                        </thead>
                        <tbody>
                            <tr><td>Punched in, never punched out</td><td><span class="badge bg-danger">Absent</span></td><td class="text-end">0 day</td></tr>
                            <tr><td>Worked less than <span class="policy-half"><?php echo $policy['half_day_min_hours']; ?></span> hours</td><td><span class="badge bg-danger">Absent</span></td><td class="text-end">0 day</td></tr>
                            <tr><td>Worked at least <span class="policy-half"><?php echo $policy['half_day_min_hours']; ?></span> hours but under a full shift</td><td><span class="badge bg-warning text-dark">Half Day</span></td><td class="text-end">0.5 day</td></tr>
                            <tr><td>Worked a full shift or more</td><td><span class="badge bg-success">Present</span></td><td class="text-end">1 day</td></tr>
                            <tr><td>Week off, approved paid leave, on duty, comp off</td><td><span class="badge bg-info">Paid</span></td><td class="text-end">1 day</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <button type="submit" name="save_policy" class="btn btn-success btn-lg">✓ Save Policy</button>
            <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary btn-lg ms-2">Cancel</a>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleFixed() {
            const basis = document.getElementById('fullDayBasis').value;
            document.getElementById('fixedWrap').style.display = basis === 'fixed' ? 'block' : 'none';
        }
        toggleFixed();

        // Keep the outcome table in step with the number being edited
        document.querySelector('[name="half_day_min_hours"]').addEventListener('input', function () {
            document.querySelectorAll('.policy-half').forEach(el => el.textContent = this.value);
        });

        <?php if ($message): ?>
        Swal.fire({
            icon: '<?php echo $message_type === 'danger' ? 'error' : 'success'; ?>',
            title: '<?php echo $message_type === 'danger' ? 'Error' : 'Saved'; ?>',
            text: '<?php echo addslashes($message); ?>'
        });
        <?php endif; ?>
    </script>
</body>
</html>
