<?php
session_start();
include('../config/db.php');
require_once __DIR__ . '/../config/project_holiday.php';
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
    if (is_array($__rights) && !in_array('project_holidays', $__rights)) {
        header("Location: admin_dashboard.php");
        exit();
    }
}

project_holiday_ensure_schema($conn);
$back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';

$message = "";
$message_type = "";

if (isset($_SESSION['flash_message'])) {
    $message      = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

/** Send the admin back to a clean URL carrying a one-time message. */
function holiday_redirect(string $msg, string $type): void
{
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_message_type'] = $type;
    header("Location: project_holidays.php");
    exit();
}

// Projects to choose from (the long-standing `locations` master)
$projects = [];
$proj_result = $conn->query("SELECT name FROM locations ORDER BY name");
while ($p = $proj_result->fetch_assoc()) {
    $projects[] = $p['name'];
}

// ── Declare a holiday ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_holiday'])) {
    $selected = isset($_POST['projects']) && is_array($_POST['projects']) ? $_POST['projects'] : [];
    $date     = trim($_POST['holiday_date'] ?? '');
    $title    = htmlspecialchars(trim($_POST['title'] ?? ''));
    if ($title === '') {
        $title = 'Holiday';
    }

    if (empty($selected) || $date === '') {
        $message = "Pick at least one project and a date";
        $message_type = "danger";
    } elseif (!DateTime::createFromFormat('Y-m-d', $date)) {
        $message = "That date is not valid";
        $message_type = "danger";
    } else {
        $added = 0;
        $skipped = [];
        $by = (int)$_SESSION['user_id'];

        // INSERT IGNORE leans on the unique (project, holiday_date) key, so
        // declaring the same day twice quietly does nothing instead of erroring
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO project_holidays (project, holiday_date, title, created_by)
             VALUES (?, ?, ?, ?)"
        );
        foreach ($selected as $proj) {
            $proj = trim($proj);
            if ($proj === '' || !in_array($proj, $projects, true)) {
                continue;
            }
            $stmt->bind_param("sssi", $proj, $date, $title, $by);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $added++;
            } else {
                $skipped[] = $proj;
            }
        }
        $stmt->close();

        $note = $added . " project(s) marked holiday on " . date('d M Y', strtotime($date)) . ".";
        if ($skipped) {
            $note .= " Already declared for: " . implode(', ', $skipped) . ".";
        }
        holiday_redirect(($added ? "✓ " : "⚠ ") . $note, $added ? "success" : "warning");
    }
}

// ── Remove a holiday ──
if (isset($_GET['delete_holiday'])) {
    $del = (int)$_GET['delete_holiday'];
    $stmt = $conn->prepare("DELETE FROM project_holidays WHERE id = ?");
    $stmt->bind_param("i", $del);
    $stmt->execute();
    $gone = $stmt->affected_rows > 0;
    $stmt->close();
    holiday_redirect(
        $gone ? "✓ Holiday removed. Those days go back to normal attendance rules." : "⚠ Holiday not found",
        $gone ? "success" : "warning"
    );
}

$filter_project = trim($_GET['project'] ?? '');
$holidays = project_holiday_all($conn, $filter_project);

// How many working employees each project covers, shown next to the checkbox
$project_headcount = [];
foreach ($projects as $p) {
    $project_headcount[$p] = project_holiday_employee_count($conn, $p);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Holidays</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { background: #f4f6f9; }
        .rule-card { border-left: 5px solid #0dcaf0; }
        .proj-pick { border: 1px solid #dee2e6; border-radius: .5rem; padding: .6rem .8rem; }
        .proj-pick:hover { background: #f8f9fa; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4 mb-5" style="max-width:1000px;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">🎌 Project Holidays</h2>
            <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary">← Back</a>
        </div>

        <div class="alert alert-info">
            Mark a date as a holiday for a whole project. Every employee allocated to that project is
            shown as <span class="badge bg-info text-dark">Holiday</span> for that day and is
            <strong>paid in full</strong>, with no punch needed. It applies everywhere at once —
            attendance screens, exports and the salary slip.
        </div>

        <?php if (empty($projects)): ?>
            <div class="alert alert-warning">
                No projects exist yet. Add them under <strong>Manage Locations</strong> first —
                a project is a location value.
            </div>
        <?php else: ?>

        <div class="card rule-card shadow-sm mb-4">
            <div class="card-header bg-light fw-bold">Declare a holiday</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="holiday_date" class="form-control" required
                               value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Occasion <span class="text-muted small">(optional)</span></label>
                        <input type="text" name="title" class="form-control" maxlength="150"
                               placeholder="e.g., Independence Day">
                        <div class="form-text">Shown as the reason on attendance screens. Defaults to “Holiday”.</div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Projects <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            <?php foreach ($projects as $p): ?>
                                <div class="col-md-4">
                                    <label class="proj-pick d-flex align-items-center gap-2 mb-0 w-100">
                                        <input class="form-check-input mt-0 proj-box" type="checkbox"
                                               name="projects[]" value="<?php echo htmlspecialchars($p); ?>">
                                        <span>
                                            <strong><?php echo htmlspecialchars($p); ?></strong><br>
                                            <span class="text-muted small"><?php echo $project_headcount[$p]; ?> employee(s)</span>
                                        </span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">
                            <a href="#" onclick="toggleAll(true);return false;">Select all</a> ·
                            <a href="#" onclick="toggleAll(false);return false;">Clear</a>
                        </div>
                    </div>

                    <div class="col-12">
                        <button type="submit" name="add_holiday" class="btn btn-success btn-lg">✓ Mark Holiday</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span class="fw-bold">Declared holidays</span>
                <form method="GET" class="d-flex gap-2">
                    <select name="project" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">All projects</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>"
                                <?php echo $filter_project === $p ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (empty($holidays)): ?>
                    <p class="text-muted m-3 mb-3">No holidays declared yet.</p>
                <?php else: ?>
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th><th>Day</th><th>Project</th><th>Occasion</th>
                                <th>Declared by</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($holidays as $h): ?>
                            <tr>
                                <td><?php echo date('d M Y', strtotime($h['holiday_date'])); ?></td>
                                <td class="text-muted"><?php echo date('D', strtotime($h['holiday_date'])); ?></td>
                                <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($h['project']); ?></span></td>
                                <td><?php echo htmlspecialchars($h['title']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($h['created_by_name'] ?? '—'); ?></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-danger"
                                            onclick="confirmDelete(<?php echo (int)$h['id']; ?>, '<?php echo addslashes($h['project']); ?>', '<?php echo date('d M Y', strtotime($h['holiday_date'])); ?>')">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleAll(on) {
            document.querySelectorAll('.proj-box').forEach(b => b.checked = on);
        }

        function confirmDelete(id, project, date) {
            Swal.fire({
                icon: 'warning',
                title: 'Remove this holiday?',
                html: '<strong>' + project + '</strong> on ' + date +
                      '<br><small>That day goes back to normal attendance rules for everyone on the project.</small>',
                showCancelButton: true,
                confirmButtonText: 'Remove',
                confirmButtonColor: '#dc3545'
            }).then(r => {
                if (r.isConfirmed) window.location.href = '?delete_holiday=' + id;
            });
        }

        <?php if ($message): ?>
        Swal.fire({
            icon: '<?php echo $message_type === 'danger' ? 'error' : ($message_type === 'warning' ? 'warning' : 'success'); ?>',
            title: '<?php echo $message_type === 'danger' ? 'Error' : ($message_type === 'warning' ? 'Note' : 'Saved'); ?>',
            text: '<?php echo addslashes($message); ?>'
        });
        <?php endif; ?>
    </script>
</body>
</html>
