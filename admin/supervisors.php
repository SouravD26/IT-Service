<?php
session_start();
include('../config/db.php');
require_once __DIR__ . '/../config/supervisor_setup.php';
require_once __DIR__ . '/employee_unique_check.php';

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'suparadmin' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit();
}

// A regular admin may only manage supervisors if granted the right
if ($_SESSION['role'] === 'admin') {
    $__uid = (int)$_SESSION['user_id'];
    $__chk = $conn->prepare("SELECT rights FROM users WHERE id=?");
    $__chk->bind_param("i", $__uid);
    $__chk->execute();
    $__row = $__chk->get_result()->fetch_assoc();
    $__chk->close();
    $__rights = !empty($__row['rights']) ? json_decode($__row['rights'], true) : null;
    if (is_array($__rights) && !in_array('manage_supervisors', $__rights)) {
        header("Location: admin_dashboard.php");
        exit();
    }
}

supervisor_ensure_schema($conn);

$back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';

$message = "";
$message_type = "";

// One-time message left by a redirect after a successful action
if (isset($_SESSION['flash_message'])) {
    $message      = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

// Locations to choose from
$locations = [];
$loc_result = $conn->query("SELECT name FROM locations ORDER BY name");
while ($loc_row = $loc_result->fetch_assoc()) {
    $locations[] = $loc_row['name'];
}

/** Send the admin back to a clean URL carrying a one-time message. */
function supervisor_redirect(string $msg, string $type): void
{
    $_SESSION['flash_message'] = $msg;
    $_SESSION['flash_message_type'] = $type;
    header("Location: supervisors.php");
    exit();
}

// ── Add Supervisor ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supervisor'])) {
    $name             = htmlspecialchars(trim($_POST['name'] ?? ''));
    $phone            = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $location         = htmlspecialchars(trim($_POST['location'] ?? ''));
    $employee_id      = htmlspecialchars(trim($_POST['employee_id'] ?? ''));
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($name === '' || $phone === '' || $location === '' || $password === '') {
        $message = "Name, Mobile Number, Location and Password are required";
        $message_type = "danger";
    } elseif (strlen($phone) !== 10) {
        $message = "Mobile Number must be exactly 10 digits";
        $message_type = "danger";
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $message_type = "danger";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match";
        $message_type = "danger";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters";
        $message_type = "danger";
    } elseif (find_duplicate_employee($conn, 'phone', $phone)) {
        $clash = find_duplicate_employee($conn, 'phone', $phone);
        $message = "Mobile Number {$phone} is already used by {$clash['name']}";
        $message_type = "danger";
    } else {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $email_val = htmlspecialchars($email);
        $stmt = $conn->prepare(
            "INSERT INTO users (name, email, password, role, phone, location, employee_id, status, password_set)
             VALUES (?, ?, ?, 'supervisor', ?, ?, ?, 'Working', TRUE)"
        );
        $stmt->bind_param("ssssss", $name, $email_val, $hashed, $phone, $location, $employee_id);
        if ($stmt->execute()) {
            $stmt->close();
            supervisor_redirect("✓ Supervisor created successfully! They can log in with mobile {$phone}.", "success");
        }
        $message = "Error: " . $stmt->error;
        $message_type = "danger";
        $stmt->close();
    }
}

// ── Update Supervisor ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_supervisor'])) {
    $sup_id   = (int)$_POST['sup_id'];
    $name     = htmlspecialchars(trim($_POST['name'] ?? ''));
    $phone    = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $location = htmlspecialchars(trim($_POST['location'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($name === '' || $phone === '' || $location === '') {
        $message = "Name, Mobile Number and Location are required";
        $message_type = "danger";
    } elseif (strlen($phone) !== 10) {
        $message = "Mobile Number must be exactly 10 digits";
        $message_type = "danger";
    } elseif ($password !== '' && strlen($password) < 6) {
        $message = "Password must be at least 6 characters";
        $message_type = "danger";
    } elseif (($clash = find_duplicate_employee($conn, 'phone', $phone, $sup_id))) {
        $message = "Mobile Number {$phone} is already used by {$clash['name']}";
        $message_type = "danger";
    } else {
        $email_val = htmlspecialchars($email);
        if ($password !== '') {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare(
                "UPDATE users SET name=?, email=?, phone=?, location=?, password=?
                 WHERE id=? AND role='supervisor'"
            );
            $stmt->bind_param("sssssi", $name, $email_val, $phone, $location, $hashed, $sup_id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE users SET name=?, email=?, phone=?, location=?
                 WHERE id=? AND role='supervisor'"
            );
            $stmt->bind_param("ssssi", $name, $email_val, $phone, $location, $sup_id);
        }
        if ($stmt->execute()) {
            $stmt->close();
            supervisor_redirect("✓ Supervisor updated successfully!", "success");
        }
        $message = "Error: " . $stmt->error;
        $message_type = "danger";
        $stmt->close();
    }
}

// ── Delete Supervisor ──
if (isset($_GET['delete_supervisor'])) {
    $del_id = (int)$_GET['delete_supervisor'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'supervisor'");
    $stmt->bind_param("i", $del_id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    supervisor_redirect(
        $deleted ? "✓ Supervisor deleted successfully!" : "⚠ Supervisor not found",
        $deleted ? "success" : "warning"
    );
}

// ── List supervisors, with how many employees each one covers ──
$supervisors = $conn->query("
    SELECT u.id, u.name, u.email, u.phone, u.location, u.employee_id, u.created_at,
           (SELECT COUNT(*) FROM users e
             WHERE e.role = 'employee' AND e.status = 'Working' AND e.location = u.location) AS employee_count
    FROM users u
    WHERE u.role = 'supervisor'
    ORDER BY u.location, u.name
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Supervisor</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body { background: #f4f6f9; }
        .sup-card { border-left: 5px solid #6f42c1; }
        .loc-badge { background: #6f42c1; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4 mb-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">🧑‍✈️ Create Supervisor</h2>
            <div>
                <button class="btn btn-primary btn-lg me-2" onclick="toggleAddForm()">➕ Add Supervisor</button>
                <a href="<?php echo $back_dashboard; ?>" class="btn btn-secondary btn-lg">← Back</a>
            </div>
        </div>

        <div class="alert alert-info">
            <strong>What is a supervisor?</strong> A supervisor logs in with their own mobile number and password,
            and punches attendance on behalf of the employees who do not carry a phone.
            They see <strong>only the employees at the location you assign here</strong>.
        </div>

        <!-- Add Supervisor Form -->
        <div class="card mb-4" id="addForm" style="display:none;">
            <div class="card-header bg-primary text-white"><h5 class="mb-0">➕ New Supervisor</h5></div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="Enter supervisor name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                        <input type="tel" name="phone" id="addPhone" class="form-control" required
                            maxlength="10" pattern="[0-9]{10}"
                            placeholder="10-digit login number"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10);"
                            title="Mobile number must be exactly 10 digits">
                        <div class="form-text">This is the supervisor's login ID.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Location <span class="text-danger">*</span></label>
                        <select name="location" class="form-control" required>
                            <option value="">Select Location</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Supervisor will manage every employee at this location.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email <span class="text-muted small">(optional)</span></label>
                        <input type="email" name="email" class="form-control" placeholder="name@example.com">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Staff ID <span class="text-muted small">(optional)</span></label>
                        <input type="text" name="employee_id" class="form-control" placeholder="e.g., SUP001">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" class="form-control" required minlength="6" placeholder="Min 6 characters">
                            <button type="button" class="btn btn-outline-secondary eye-btn" onclick="togglePassword(this)" title="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" class="form-control" required minlength="6" placeholder="Re-enter password">
                            <button type="button" class="btn btn-outline-secondary eye-btn" onclick="togglePassword(this)" title="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="add_supervisor" class="btn btn-success">✓ Create Supervisor</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleAddForm()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Supervisor List -->
        <?php if (empty($supervisors)): ?>
            <div class="card"><div class="card-body text-center text-muted py-5">
                <i class="fas fa-user-tie fa-3x mb-3 d-block" style="opacity:.3;"></i>
                No supervisors yet. Click <strong>Add Supervisor</strong> to create the first one.
            </div></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($supervisors as $sup): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card sup-card shadow-sm h-100">
                            <div class="card-body">
                                <h5 class="card-title mb-1"><?php echo htmlspecialchars($sup['name']); ?></h5>
                                <span class="badge loc-badge mb-2">📍 <?php echo htmlspecialchars($sup['location']); ?></span>
                                <p class="card-text small mb-1"><strong>📱 Login:</strong> <?php echo htmlspecialchars($sup['phone']); ?></p>
                                <?php if (!empty($sup['email'])): ?>
                                    <p class="card-text small mb-1"><strong>✉️</strong> <?php echo htmlspecialchars($sup['email']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($sup['employee_id'])): ?>
                                    <p class="card-text small mb-1"><strong>🆔</strong> <?php echo htmlspecialchars($sup['employee_id']); ?></p>
                                <?php endif; ?>
                                <p class="card-text small text-muted mb-3">
                                    👥 Covers <strong><?php echo (int)$sup['employee_count']; ?></strong> employee(s)
                                </p>
                                <button class="btn btn-sm btn-warning"
                                    onclick='openEditModal(<?php echo json_encode($sup, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>✎ Edit</button>
                                <button class="btn btn-sm btn-danger"
                                    onclick="confirmDelete(<?php echo (int)$sup['id']; ?>, '<?php echo htmlspecialchars(addslashes($sup['name'])); ?>')">🗑 Delete</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">✎ Edit Supervisor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body row g-3">
                        <input type="hidden" name="sup_id" id="editId">
                        <input type="hidden" name="update_supervisor" value="1">
                        <div class="col-12">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" id="editPhone" class="form-control" required
                                maxlength="10" pattern="[0-9]{10}"
                                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,10);">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Location <span class="text-danger">*</span></label>
                            <select name="location" id="editLocation" class="form-control" required>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars($loc); ?>"><?php echo htmlspecialchars($loc); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email <span class="text-muted small">(optional)</span></label>
                            <input type="email" name="email" id="editEmail" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">New Password <span class="text-muted small">(leave blank to keep current)</span></label>
                            <div class="input-group">
                                <input type="password" name="password" class="form-control" minlength="6" placeholder="Min 6 characters">
                                <button type="button" class="btn btn-outline-secondary eye-btn" onclick="togglePassword(this)" title="Show password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">✓ Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Eye button: reveals the password field sitting just before the button
        function togglePassword(btn) {
            const input = btn.previousElementSibling;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.innerHTML = showing ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            btn.title = showing ? 'Show password' : 'Hide password';
        }

        function toggleAddForm() {
            const f = document.getElementById('addForm');
            f.style.display = f.style.display === 'none' ? 'block' : 'none';
            if (f.style.display === 'block') f.scrollIntoView({ behavior: 'smooth' });
        }

        function openEditModal(sup) {
            document.getElementById('editId').value = sup.id;
            document.getElementById('editName').value = sup.name || '';
            document.getElementById('editPhone').value = sup.phone || '';
            document.getElementById('editEmail').value = sup.email || '';
            document.getElementById('editLocation').value = sup.location || '';
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        function confirmDelete(id, name) {
            Swal.fire({
                title: 'Delete ' + name + '?',
                text: 'This supervisor will no longer be able to log in. Employee records are not affected.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Yes, delete'
            }).then(r => {
                if (r.isConfirmed) window.location.href = '?delete_supervisor=' + id;
            });
        }

        // Warn as soon as a mobile number is already taken, instead of on submit
        const addPhone = document.getElementById('addPhone');
        if (addPhone) {
            let t = null;
            addPhone.addEventListener('input', () => {
                clearTimeout(t);
                t = setTimeout(() => {
                    const v = addPhone.value.trim();
                    if (v.length !== 10) return;
                    fetch('check_duplicate.php?field=phone&value=' + encodeURIComponent(v))
                        .then(r => r.json())
                        .then(d => {
                            let warn = addPhone.parentElement.querySelector('.duplicate-warning');
                            if (!warn) {
                                warn = document.createElement('div');
                                warn.className = 'duplicate-warning text-danger small mt-1 fw-semibold';
                                addPhone.parentElement.appendChild(warn);
                            }
                            warn.textContent = d.duplicate ? '⚠ ' + d.message : '';
                            addPhone.style.borderColor = d.duplicate ? '#dc3545' : '';
                        })
                        .catch(() => {});
                }, 400);
            });
        }

        <?php if ($message): ?>
        Swal.fire({
            icon: '<?php echo $message_type === 'danger' ? 'error' : ($message_type === 'warning' ? 'warning' : 'success'); ?>',
            title: '<?php echo $message_type === 'danger' ? 'Error' : ($message_type === 'warning' ? 'Warning' : 'Success'); ?>',
            text: '<?php echo addslashes($message); ?>',
            confirmButtonText: 'OK'
        });
        <?php endif; ?>
    </script>
</body>
</html>
