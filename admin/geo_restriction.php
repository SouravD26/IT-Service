<?php
session_start();
include('../config/db.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
if ($from === 'suparadmin') {
    $back_dashboard = 'dashboard.php';
} elseif ($from === 'admin') {
    $back_dashboard = 'admin_dashboard.php';
} else {
    $back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';
}

// Add geo_restricted column if missing (safe for all MySQL/MariaDB versions)
$chk = $conn->query("SHOW COLUMNS FROM users LIKE 'geo_restricted'");
if ($chk && $chk->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN geo_restricted TINYINT(1) NOT NULL DEFAULT 0");
}

// Create office_settings table if missing
$conn->query("CREATE TABLE IF NOT EXISTS office_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    office_name VARCHAR(100) NOT NULL DEFAULT 'Head Office',
    latitude DECIMAL(11,8) DEFAULT NULL,
    longitude DECIMAL(11,8) DEFAULT NULL,
    radius_meters INT NOT NULL DEFAULT 100,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Seed default coordinates only if table is empty
$cnt_res = $conn->query("SELECT COUNT(*) AS c FROM office_settings");
if ($cnt_res && (int)$cnt_res->fetch_assoc()['c'] === 0) {
    $conn->query("INSERT INTO office_settings (office_name, latitude, longitude, radius_meters)
                  VALUES ('Head Office', 22.55075955, 88.39922009, 100)");
}

// Handle Save Office Location
$office_msg = '';
$office_msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_office'])) {
    $o_name   = htmlspecialchars(trim($_POST['office_name']));
    $o_lat    = (float)$_POST['office_lat'];
    $o_lng    = (float)$_POST['office_lng'];
    $o_radius = max(50, min(5000, (int)$_POST['office_radius']));
    $o_id     = (int)$_POST['office_id'];
    $stmt = $conn->prepare("UPDATE office_settings SET office_name=?, latitude=?, longitude=?, radius_meters=? WHERE id=?");
    $stmt->bind_param("sddii", $o_name, $o_lat, $o_lng, $o_radius, $o_id);
    if ($stmt->execute()) {
        $office_msg = "✓ Office location saved successfully!";
        $office_msg_type = "success";
    } else {
        $office_msg = "Error: " . $stmt->error;
        $office_msg_type = "danger";
    }
    $stmt->close();
}

// Load current office settings (with safe defaults if not yet seeded)
$office_res = $conn->query("SELECT * FROM office_settings ORDER BY id LIMIT 1");
$office = ($office_res && $office_res->num_rows > 0) ? $office_res->fetch_assoc() : [
    'id'            => 0,
    'office_name'   => 'Head Office',
    'latitude'      => null,
    'longitude'     => null,
    'radius_meters' => 100,
];

// Filters
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';
$dept_filter = isset($_GET['dept'])   ? trim($_GET['dept'])   : '';
$per_page    = 20;
$page        = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset      = ($page - 1) * $per_page;

// Count total matching rows for pagination
$count_sql    = "SELECT COUNT(*) as cnt FROM users u WHERE u.role = 'employee' AND u.status = 'Working'";
$count_params = [];
$count_types  = '';
if (!empty($search)) {
    $count_sql    .= " AND (u.name LIKE ? OR u.employee_id LIKE ?)";
    $count_params[]= "%$search%";
    $count_params[]= "%$search%";
    $count_types  .= 'ss';
}
if (!empty($dept_filter)) {
    $count_sql    .= " AND u.department = ?";
    $count_params[]= $dept_filter;
    $count_types  .= 's';
}
$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) die('Count prepare failed: ' . htmlspecialchars($conn->error));
if (!empty($count_params)) $count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_filtered = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$page = min($page, $total_pages);  // clamp to valid range
$offset = ($page - 1) * $per_page;

// Build query — no JOIN to locations (GPS comes from office_settings now)
$sql    = "SELECT u.id, u.name, u.employee_id, u.department, u.location, u.company, u.geo_restricted
           FROM users u
           WHERE u.role = 'employee' AND u.status = 'Working'";
$params = [];
$types  = '';

if (!empty($search)) {
    $sql    .= " AND (u.name LIKE ? OR u.employee_id LIKE ?)";
    $params[]= "%$search%";
    $params[]= "%$search%";
    $types  .= 'ss';
}
if (!empty($dept_filter)) {
    $sql    .= " AND u.department = ?";
    $params[]= $dept_filter;
    $types  .= 's';
}
$sql .= " ORDER BY u.name LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= 'ii';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Query prepare failed: ' . htmlspecialchars($conn->error));
}
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$employees = $stmt->get_result();
$stmt->close();

// Departments for filter dropdown
$dept_res    = $conn->query("SELECT DISTINCT department FROM users WHERE role='employee' AND department IS NOT NULL AND department !='' ORDER BY department");
$departments = [];
while ($d = $dept_res->fetch_assoc()) $departments[] = $d['department'];

// Summary counts
$totals_res = $conn->query("SELECT COUNT(*) as total, SUM(geo_restricted) as restricted FROM users WHERE role='employee' AND status='Working'");
$totals = $totals_res ? $totals_res->fetch_assoc() : ['total' => 0, 'restricted' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Attendance Restriction</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { background: #f4f6f9; }
        .emp-row {
            background: #fff;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: box-shadow 0.2s;
        }
        .emp-row:hover { box-shadow: 0 3px 10px rgba(0,0,0,0.13); }
        .emp-row.restricted { border-left: 4px solid #198754; }
        .emp-row.unrestricted { border-left: 4px solid #dee2e6; }
        .emp-name { font-weight: 600; font-size: 1rem; margin-bottom: 2px; }
        .emp-meta { font-size: 0.82rem; color: #6c757d; }
        .form-switch .form-check-input { width: 3em; height: 1.5em; cursor: pointer; }
        .no-gps-badge { font-size: 0.72rem; }
        .stat-box { border-radius: 10px; padding: 18px; text-align: center; }
        @media (max-width: 576px) {
            .emp-row { flex-wrap: wrap; }
            .emp-row .ms-auto { margin-top: 8px; }
        }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4 mb-5" style="max-width:900px;">

        <!-- Info Banner -->
        <div class="alert alert-info d-flex gap-3 align-items-start mb-4">
            <span style="font-size:1.6rem;">📡</span>
            <div>
                <strong>How it works:</strong> Toggle ON for any employee to enforce that they can only mark attendance when within <strong><?= (int)$office['radius_meters'] ?> metres</strong> of your Head Office.
                Employees toggled OFF can mark attendance from anywhere.
            </div>
        </div>

        <!-- ===== HEAD OFFICE GPS CARD ===== -->
        <div class="card shadow-sm mb-4 border-0" style="border-left:5px solid #20c997 !important; border-left-width:5px !important;">
            <div class="card-header d-flex align-items-center justify-content-between" style="background:#e6f9f4;">
                <span class="fw-bold" style="color:#0a6c50;">📍 Head Office GPS Location</span>
                <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="collapse" data-bs-target="#officeEditPanel">
                    ✏️ Edit
                </button>
            </div>
            <div class="card-body py-3">
                <?php if ($office_msg): ?>
                <div class="alert alert-<?= $office_msg_type ?> py-2 mb-3"><?= $office_msg ?></div>
                <?php endif; ?>

                <!-- Current coords display -->
                <div class="d-flex flex-wrap gap-3 align-items-center mb-1">
                    <div>
                        <span class="text-muted small">Office Name</span><br>
                        <strong id="disp_name"><?= htmlspecialchars($office['office_name']) ?></strong>
                    </div>
                    <div>
                        <span class="text-muted small">Latitude</span><br>
                        <code id="disp_lat"><?= $office['latitude'] ?></code>
                    </div>
                    <div>
                        <span class="text-muted small">Longitude</span><br>
                        <code id="disp_lng"><?= $office['longitude'] ?></code>
                    </div>
                    <div>
                        <span class="text-muted small">Allowed Radius</span><br>
                        <span class="badge bg-primary" id="disp_radius"><?= (int)$office['radius_meters'] ?> m</span>
                    </div>
                    <div class="ms-auto">
                        <?php if ($office['latitude']): ?>
                        <a href="https://www.google.com/maps?q=<?= $office['latitude'] ?>,<?= $office['longitude'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">🗺 View on Map</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Collapsible edit form -->
                <div class="collapse mt-3" id="officeEditPanel">
                    <hr>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="office_id" value="<?= (int)$office['id'] ?>">
                        <div class="col-md-4">
                            <label class="form-label">Office Name</label>
                            <input type="text" name="office_name" class="form-control" value="<?= htmlspecialchars($office['office_name']) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Latitude</label>
                            <input type="number" step="any" name="office_lat" id="edit_lat" class="form-control" value="<?= $office['latitude'] ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Longitude</label>
                            <input type="number" step="any" name="office_lng" id="edit_lng" class="form-control" value="<?= $office['longitude'] ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Radius (m)</label>
                            <input type="number" name="office_radius" class="form-control" value="<?= (int)$office['radius_meters'] ?>" min="50" max="5000">
                        </div>
                        <div class="col-12 d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="useMyGPS()">
                                📍 Use My Current GPS
                            </button>
                            <small class="text-muted">or paste coordinates from Google Maps (right-click → copy coordinates)</small>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="save_office" class="btn btn-success">💾 Save Office Location</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- ===== END HEAD OFFICE GPS CARD ===== -->

        <!-- Stats -->
        <div class="row g-3 mb-4">
            <div class="col-4">
                <div class="stat-box bg-white shadow-sm">
                    <div style="font-size:1.8rem;font-weight:700;color:#0d6efd;"><?= (int)$totals['total'] ?></div>
                    <div class="text-muted small">Total Employees</div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-box bg-white shadow-sm">
                    <div style="font-size:1.8rem;font-weight:700;color:#198754;"><?= (int)$totals['restricted'] ?></div>
                    <div class="text-muted small">Restriction ON</div>
                </div>
            </div>
            <div class="col-4">
                <div class="stat-box bg-white shadow-sm">
                    <div style="font-size:1.8rem;font-weight:700;color:#6c757d;"><?= (int)$totals['total'] - (int)$totals['restricted'] ?></div>
                    <div class="text-muted small">Restriction OFF</div>
                </div>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex flex-wrap gap-2 align-items-center py-2">
                <span class="fw-semibold me-2">Bulk:</span>
                <button class="btn btn-success btn-sm" onclick="bulkToggle(1)">✅ Enable All</button>
                <button class="btn btn-secondary btn-sm" onclick="bulkToggle(0)">❌ Disable All</button>
                <span class="text-muted small ms-3">(applies to current filtered list)</span>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="card shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="row g-2 align-items-end">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="🔍 Search by name or employee ID..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="dept" class="form-select form-select-sm">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $dept_filter === $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="hidden" name="from" value="<?= htmlspecialchars($from) ?>">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Employee List -->
        <div id="empList">
        <?php
        $rowCount = 0;
        if ($employees->num_rows > 0):
            while ($emp = $employees->fetch_assoc()):
                $rowCount++;
                $isOn     = (int)$emp['geo_restricted'];
                $officeReady = ($office['latitude'] !== null && $office['longitude'] !== null);
                $rowClass = $isOn ? 'restricted' : 'unrestricted';
        ?>
        <div class="emp-row <?= $rowClass ?>" id="row-<?= $emp['id'] ?>">
            <!-- Avatar -->
            <div style="width:40px;height:40px;background:<?= $isOn ? '#d1e7dd' : '#e9ecef' ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">
                <?= $isOn ? '🔒' : '👤' ?>
            </div>

            <!-- Info -->
            <div style="flex:1;min-width:0;">
                <div class="emp-name"><?= htmlspecialchars($emp['name']) ?></div>
                <div class="emp-meta">
                    <?= $emp['employee_id'] ? '<span class="badge bg-light text-dark border me-1">' . htmlspecialchars($emp['employee_id']) . '</span>' : '' ?>
                    <?= $emp['department'] ? htmlspecialchars($emp['department']) : '<span class="text-muted">No Dept</span>' ?>
                    <?php if ($emp['location']): ?>
                    · <?= htmlspecialchars($emp['location']) ?>
                    <?php endif; ?>
                    <?php if ($isOn && !$officeReady): ?>
                    <span class="badge bg-warning text-dark no-gps-badge ms-1">⚠ Office GPS not set</span>
                    <?php elseif ($isOn && $officeReady): ?>
                    <span class="badge bg-success no-gps-badge ms-1">📍 GPS Active</span>
                    <?php endif; ?>
                    <?= $emp['company'] ? '· ' . htmlspecialchars($emp['company']) : '' ?>
                </div>
            </div>

            <!-- Toggle -->
            <div class="ms-auto d-flex align-items-center gap-2 flex-shrink-0">
                <span class="small text-muted" id="label-<?= $emp['id'] ?>"><?= $isOn ? '<span class="text-success fw-semibold">ON</span>' : '<span class="text-secondary">OFF</span>' ?></span>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="toggle-<?= $emp['id'] ?>"
                        <?= $isOn ? 'checked' : '' ?>
                        onchange="toggleRestriction(<?= $emp['id'] ?>, this.checked)"
                        title="<?= $isOn ? 'Click to disable 100m restriction' : 'Click to enable 100m restriction' ?>">
                </div>
            </div>
        </div>
        <?php endwhile; ?>
        <?php else: ?>
        <div class="alert alert-warning text-center">No employees found matching your filters.</div>
        <?php endif; ?>
        </div>

        <?php if ($rowCount === 0 && empty($search) && empty($dept_filter)): ?>
        <div class="alert alert-info text-center">No active employees found in the system.</div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <small class="text-muted">
                    Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_filtered) ?> of <?= $total_filtered ?> employees
                </small>
                <ul class="pagination pagination-sm mb-0">
                    <!-- Previous -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo;</a>
                    </li>
                    <?php
                    // Show at most 7 page links centred around current page
                    $start_p = max(1, $page - 3);
                    $end_p   = min($total_pages, $page + 3);
                    if ($start_p > 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif;
                    for ($p = $start_p; $p <= $end_p; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                    </li>
                    <?php endfor;
                    if ($end_p < $total_pages): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                    <?php endif; ?>
                    <!-- Next -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&raquo;</a>
                    </li>
                </ul>
            </div>
        </nav>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleRestriction(userId, enable) {
            const val    = enable ? 1 : 0;
            const toggle = document.getElementById('toggle-' + userId);
            const row    = document.getElementById('row-' + userId);
            const label  = document.getElementById('label-' + userId);

            toggle.disabled = true;

            fetch('../api/toggle_geo_restrict.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'user_id=' + userId + '&value=' + val
            })
            .then(r => r.json())
            .then(data => {
                toggle.disabled = false;
                if (data.success) {
                    if (val === 1) {
                        row.classList.remove('unrestricted');
                        row.classList.add('restricted');
                        row.querySelector('div:first-child').textContent = '🔒';
                        row.querySelector('div:first-child').style.background = '#d1e7dd';
                        label.innerHTML = '<span class="text-success fw-semibold">ON</span>';
                    } else {
                        row.classList.remove('restricted');
                        row.classList.add('unrestricted');
                        row.querySelector('div:first-child').textContent = '👤';
                        row.querySelector('div:first-child').style.background = '#e9ecef';
                        label.innerHTML = '<span class="text-secondary">OFF</span>';
                    }
                    updateStats();
                } else {
                    toggle.checked = !enable;
                    Swal.fire('Error', data.message || 'Could not update restriction.', 'error');
                }
            })
            .catch(() => {
                toggle.disabled = false;
                toggle.checked = !enable;
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            });
        }

        function updateStats() {
            const on  = document.querySelectorAll('.emp-row.restricted').length;
            const all = document.querySelectorAll('.emp-row').length;
            document.querySelectorAll('.stat-box')[1].querySelector('div:first-child').textContent = on;
            document.querySelectorAll('.stat-box')[2].querySelector('div:first-child').textContent = all - on;
        }

        function bulkToggle(val) {
            const rows   = document.querySelectorAll('.emp-row');
            const action = val === 1 ? 'enable' : 'disable';
            if (rows.length === 0) return;

            Swal.fire({
                title: 'Confirm Bulk ' + (val ? 'Enable' : 'Disable'),
                text: (val ? 'Enable' : 'Disable') + ' GPS restriction for all ' + rows.length + ' employee(s) in the current list?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, ' + action + ' all',
                confirmButtonColor: val ? '#198754' : '#6c757d'
            }).then(result => {
                if (!result.isConfirmed) return;
                rows.forEach(row => {
                    const userId = row.id.replace('row-', '');
                    const toggle = document.getElementById('toggle-' + userId);
                    const curVal = toggle.checked ? 1 : 0;
                    if (curVal !== val) {
                        toggle.checked = val === 1;
                        toggleRestriction(userId, val === 1);
                    }
                });
            });
        }

        function useMyGPS() {
            if (!navigator.geolocation) {
                alert('Geolocation is not supported by your browser.');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                pos => {
                    document.getElementById('edit_lat').value = pos.coords.latitude.toFixed(8);
                    document.getElementById('edit_lng').value = pos.coords.longitude.toFixed(8);
                    // Auto-open the collapse panel if not already open
                    const panel = document.getElementById('officeEditPanel');
                    if (!panel.classList.contains('show')) {
                        new bootstrap.Collapse(panel).show();
                    }
                },
                err => { alert('Could not get location: ' + err.message); },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        }
    </script>
</body>
</html>

