<?php
session_start();
require_once '../config/db.php';
require_once '../config/supervisor_setup.php';
require_once '../config/attendance_geo.php';

date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'supervisor') {
    header("Location: ../auth/login.php");
    exit();
}

supervisor_ensure_schema($conn);

$supervisor_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, phone, location FROM users WHERE id = ? AND role = 'supervisor'");
$stmt->bind_param("i", $supervisor_id);
$stmt->execute();
$supervisor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$supervisor) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

$location  = $supervisor['location'] ?? '';
$employees = supervisor_employees($conn, $location);

// Today's punch state for every employee, so each card shows it without a round trip
$today = attendance_shift_date();
$punch_state = [];
if (!empty($employees)) {
    $ids = implode(',', array_map(fn($e) => (int)$e['id'], $employees));
    $res = $conn->query(
        "SELECT user_id,
                MAX(punch_in)  AS last_in,
                MAX(punch_out) AS last_out,
                SUM(punch_in IS NOT NULL AND punch_out IS NULL) AS open_sessions
         FROM attendance
         WHERE date = '" . $conn->real_escape_string($today) . "' AND user_id IN ($ids)
         GROUP BY user_id"
    );
    while ($row = $res->fetch_assoc()) {
        $punch_state[(int)$row['user_id']] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supervisor Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { background: #f4f6f9; }
        .topbar { background: linear-gradient(135deg, #6f42c1, #8e5ad6); color: #fff; }
        .search-wrap { position: relative; }
        #employeeResults {
            position: absolute; z-index: 1050; width: 100%;
            max-height: 320px; overflow-y: auto;
            background: #fff; border: 1px solid #dee2e6; border-top: none;
            border-radius: 0 0 8px 8px; box-shadow: 0 6px 18px rgba(0,0,0,.12);
            display: none;
        }
        #employeeResults .result-item { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f1f1f1; }
        #employeeResults .result-item:hover, #employeeResults .result-item.active { background: #f3ecfd; }
        #employeeResults .result-item:last-child { border-bottom: none; }
        .emp-card { cursor: pointer; transition: transform .12s ease, box-shadow .12s ease; border-left: 5px solid #6f42c1; }
        .emp-card:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(0,0,0,.14); }
        .avatar {
            width: 52px; height: 52px; border-radius: 50%;
            background: #6f42c1; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 20px; flex-shrink: 0;
        }
        #cameraStream, #photoPreview {
            width: 100%; max-height: 300px; object-fit: cover;
            border-radius: 10px; background: #000;
        }
        .status-line { font-size: 13px; }
    </style>
</head>
<body>

<!-- Top bar -->
<nav class="topbar py-3 mb-4 shadow">
    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="mb-0">🧑‍✈️ <?php echo htmlspecialchars($supervisor['name']); ?></h5>
            <small>📍 <?php echo htmlspecialchars($location ?: 'No location assigned'); ?> &middot; <?php echo date('D, d M Y'); ?></small>
        </div>
        <a href="../auth/logout.php" class="btn btn-light btn-sm">Logout</a>
    </div>
</nav>

<div class="container mb-5">

    <?php if ($location === ''): ?>
        <div class="alert alert-danger">
            No location is assigned to your account. Please ask an admin to set your location before punching attendance.
        </div>
    <?php elseif (empty($employees)): ?>
        <div class="alert alert-warning">
            No working employees found at <strong><?php echo htmlspecialchars($location); ?></strong>.
        </div>
    <?php else: ?>

    <!-- Main card: search + selected employee -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">🔍 Punch Attendance</h5>
            <small class="text-muted">Search an employee at <?php echo htmlspecialchars($location); ?>, then tap their card to punch.</small>
        </div>
        <div class="card-body">

            <!-- Search bar with dropdown -->
            <div class="search-wrap mb-3">
                <input type="text" id="employeeSearch" class="form-control form-control-lg"
                       placeholder="Search by employee name or ID…" autocomplete="off">
                <div id="employeeResults"></div>
            </div>

            <!-- Selected employee card (filled by JS) -->
            <div id="selectedEmployee"></div>

            <div id="emptyHint" class="text-center text-muted py-4">
                <i class="fas fa-user-group fa-2x mb-2 d-block" style="opacity:.3;"></i>
                <?php echo count($employees); ?> employee(s) at this location. Start typing to find one.
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Punch popup -->
<div class="modal fade" id="punchModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header text-white" style="background:#6f42c1;">
                <h5 class="modal-title" id="punchModalTitle">Punch</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopCamera()"></button>
            </div>
            <div class="modal-body">
                <div id="punchStatus" class="alert alert-secondary py-2 status-line mb-3">Loading today's status…</div>

                <!-- Camera / captured photo -->
                <video id="cameraStream" autoplay playsinline muted></video>
                <img id="photoPreview" style="display:none;" alt="Captured photo">
                <canvas id="photoCanvas" style="display:none;"></canvas>

                <div class="d-grid gap-2 mt-3">
                    <button type="button" class="btn btn-outline-primary" id="captureBtn" onclick="capturePhoto()">
                        📷 Capture Photo
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="retakeBtn" style="display:none;" onclick="retakePhoto()">
                        ↻ Retake
                    </button>
                </div>

                <div class="status-line mt-3" id="gpsStatus">📍 Getting location…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopCamera()">Cancel</button>
                <button type="button" class="btn btn-success" id="punchActionBtn" onclick="submitPunch()" disabled>
                    Punch In
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const EMPLOYEES = <?php echo json_encode(array_map(function ($e) use ($punch_state) {
        $st = $punch_state[(int)$e['id']] ?? null;
        return [
            'id'          => (int)$e['id'],
            'name'        => $e['name'],
            'employee_id' => $e['employee_id'] ?? '',
            'department'  => $e['department'] ?? '',
            'shift_time'  => $e['shift_time'] ?? '',
            'punched_in'  => $st ? ((int)$st['open_sessions'] > 0) : false,
            'last_in'     => $st && $st['last_in']  ? date('h:i A', strtotime($st['last_in']))  : null,
            'last_out'    => $st && $st['last_out'] ? date('h:i A', strtotime($st['last_out'])) : null,
        ];
    }, $employees), JSON_UNESCAPED_UNICODE); ?>;

    let selectedEmployee = null;
    let capturedImage = null;
    let cameraStreamObj = null;
    let gps = { lat: null, lng: null };
    let nextAction = 'in';

    const searchInput = document.getElementById('employeeSearch');
    const resultsBox  = document.getElementById('employeeResults');

    // ── Search: name or employee ID, limited to this location's employees ──
    function renderResults(term) {
        const q = term.trim().toLowerCase();
        const matches = EMPLOYEES.filter(e =>
            e.name.toLowerCase().includes(q) ||
            (e.employee_id || '').toLowerCase().includes(q)
        ).slice(0, 50);

        if (matches.length === 0) {
            resultsBox.innerHTML = '<div class="result-item text-muted">No employee found</div>';
        } else {
            resultsBox.innerHTML = matches.map(e => `
                <div class="result-item" onclick="selectEmployee(${e.id})">
                    <strong>${escapeHtml(e.name)}</strong>
                    <span class="text-muted small"> ${escapeHtml(e.employee_id || '')}</span>
                    ${e.punched_in ? '<span class="badge bg-success float-end">Punched In</span>' : ''}
                </div>`).join('');
        }
        resultsBox.style.display = 'block';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    searchInput.addEventListener('input', () => renderResults(searchInput.value));
    searchInput.addEventListener('focus', () => renderResults(searchInput.value));
    document.addEventListener('click', e => {
        if (!e.target.closest('.search-wrap')) resultsBox.style.display = 'none';
    });

    // ── Tapping a search result shows the employee's card ──
    function selectEmployee(id) {
        selectedEmployee = EMPLOYEES.find(e => e.id === id);
        if (!selectedEmployee) return;
        resultsBox.style.display = 'none';
        searchInput.value = selectedEmployee.name;
        document.getElementById('emptyHint').style.display = 'none';

        const e = selectedEmployee;
        const initials = e.name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
        document.getElementById('selectedEmployee').innerHTML = `
            <div class="card emp-card shadow-sm" onclick="openPunchModal()">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="avatar">${escapeHtml(initials)}</div>
                    <div class="flex-grow-1">
                        <h5 class="mb-1">${escapeHtml(e.name)}</h5>
                        <div class="text-muted small">
                            ${escapeHtml(e.employee_id || '')} ${e.department ? '&middot; ' + escapeHtml(e.department) : ''}
                            ${e.shift_time ? '&middot; ⏰ ' + escapeHtml(e.shift_time) : ''}
                        </div>
                        <div class="small mt-1">
                            ${e.punched_in
                                ? '<span class="badge bg-success">Punched In</span>'
                                : '<span class="badge bg-secondary">Not Punched In</span>'}
                            ${e.last_in  ? ' <span class="text-muted">In: ' + e.last_in + '</span>'  : ''}
                            ${e.last_out ? ' <span class="text-muted">Out: ' + e.last_out + '</span>' : ''}
                        </div>
                    </div>
                    <div class="text-primary"><i class="fas fa-chevron-right"></i></div>
                </div>
                <div class="card-footer bg-light text-center small text-muted">Tap the card to punch in / punch out</div>
            </div>`;
    }

    // ── Punch popup ──
    function openPunchModal() {
        if (!selectedEmployee) return;
        capturedImage = null;
        document.getElementById('punchModalTitle').textContent = selectedEmployee.name;
        document.getElementById('punchActionBtn').disabled = true;
        document.getElementById('photoPreview').style.display = 'none';
        document.getElementById('cameraStream').style.display = 'block';
        document.getElementById('captureBtn').style.display = 'block';
        document.getElementById('retakeBtn').style.display = 'none';

        new bootstrap.Modal(document.getElementById('punchModal')).show();

        loadStatus();
        startCamera();
        fetchLocation();
    }

    // Ask the server what the next action is, so two supervisors cannot both punch in
    function loadStatus() {
        const box = document.getElementById('punchStatus');
        box.className = 'alert alert-secondary py-2 status-line mb-3';
        box.textContent = "Loading today's status…";

        fetch('employee_status.php?employee_id=' + selectedEmployee.id)
            .then(r => r.json())
            .then(d => {
                if (!d.success) { box.className = 'alert alert-danger py-2 status-line mb-3'; box.textContent = d.message; return; }
                nextAction = d.next_action;

                const btn = document.getElementById('punchActionBtn');
                btn.textContent = nextAction === 'in' ? 'Punch In' : 'Punch Out';
                btn.className = nextAction === 'in' ? 'btn btn-success' : 'btn btn-danger';

                const lines = d.sessions.length
                    ? d.sessions.map((s, i) => `Session ${i + 1}: In ${s.punch_in || '—'} / Out ${s.punch_out || '—'}`).join('<br>')
                    : 'No punches recorded today.';
                box.className = 'alert ' + (d.is_punched_in ? 'alert-success' : 'alert-secondary') + ' py-2 status-line mb-3';
                box.innerHTML = lines;
                refreshPunchButton();
            })
            .catch(() => { box.className = 'alert alert-danger py-2 status-line mb-3'; box.textContent = 'Could not load status.'; });
    }

    function startCamera() {
        const video = document.getElementById('cameraStream');
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            Swal.fire('Camera unavailable', 'This browser cannot access the camera. Use Chrome over https or localhost.', 'error');
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => { cameraStreamObj = stream; video.srcObject = stream; })
            .catch(() => {
                // Fall back to the front camera if there is no rear one
                navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
                    .then(stream => { cameraStreamObj = stream; video.srcObject = stream; })
                    .catch(err => Swal.fire('Camera error', 'Cannot access the camera: ' + err.message, 'error'));
            });
    }

    function stopCamera() {
        if (cameraStreamObj) {
            cameraStreamObj.getTracks().forEach(t => t.stop());
            cameraStreamObj = null;
        }
    }

    function capturePhoto() {
        const video  = document.getElementById('cameraStream');
        const canvas = document.getElementById('photoCanvas');

        // Downscale to keep the upload small, as employee/punch_in.php does
        const maxW = 480;
        const scale = video.videoWidth > maxW ? maxW / video.videoWidth : 1;
        canvas.width  = Math.round(video.videoWidth  * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        capturedImage = canvas.toDataURL('image/jpeg', 0.5);

        const preview = document.getElementById('photoPreview');
        preview.src = capturedImage;
        preview.style.display = 'block';
        video.style.display = 'none';
        document.getElementById('captureBtn').style.display = 'none';
        document.getElementById('retakeBtn').style.display = 'block';
        stopCamera();
        refreshPunchButton();
    }

    function retakePhoto() {
        capturedImage = null;
        document.getElementById('photoPreview').style.display = 'none';
        document.getElementById('cameraStream').style.display = 'block';
        document.getElementById('captureBtn').style.display = 'block';
        document.getElementById('retakeBtn').style.display = 'none';
        startCamera();
        refreshPunchButton();
    }

    function fetchLocation() {
        const box = document.getElementById('gpsStatus');
        if (!navigator.geolocation) { box.textContent = '📍 Location not supported by this browser'; return; }
        box.textContent = '📍 Getting location…';
        navigator.geolocation.getCurrentPosition(
            pos => {
                gps.lat = pos.coords.latitude;
                gps.lng = pos.coords.longitude;
                box.innerHTML = '📍 Location captured (±' + Math.round(pos.coords.accuracy) + ' m)';
                refreshPunchButton();
            },
            err => {
                gps.lat = gps.lng = null;
                box.innerHTML = '<span class="text-danger">📍 Location unavailable: ' + err.message + '</span>';
                refreshPunchButton();
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }

    // Photo is mandatory; the server decides whether missing GPS is fatal
    function refreshPunchButton() {
        document.getElementById('punchActionBtn').disabled = !capturedImage;
    }

    function submitPunch() {
        if (!capturedImage) { Swal.fire('Photo required', 'Capture a photo first.', 'warning'); return; }

        const btn = document.getElementById('punchActionBtn');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'Saving…';

        const body = new FormData();
        body.append('employee_id', selectedEmployee.id);
        body.append('action', nextAction);
        body.append('selfie_image', capturedImage);
        if (gps.lat !== null) { body.append('lat', gps.lat); body.append('lng', gps.lng); }

        fetch('punch.php', { method: 'POST', body })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    stopCamera();
                    bootstrap.Modal.getInstance(document.getElementById('punchModal')).hide();
                    Swal.fire('Done', d.message, 'success').then(() => location.reload());
                } else {
                    btn.disabled = false;
                    btn.textContent = original;
                    Swal.fire('Not recorded', d.message, 'error');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.textContent = original;
                Swal.fire('Error', 'Could not reach the server: ' + err.message, 'error');
            });
    }

    // Always release the camera when the popup closes
    document.getElementById('punchModal').addEventListener('hidden.bs.modal', stopCamera);
</script>
</body>
</html>
