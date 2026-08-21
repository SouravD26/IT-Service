<?php
session_start();
include('../config/db.php');

// Allow suparadmin, or admin with 'manage_passwords' right
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['suparadmin', 'admin'])) {
    header("Location: ../auth/login.php");
    exit();
}
if ($_SESSION['role'] === 'admin') {
    $stmt_chk = $conn->prepare("SELECT rights FROM users WHERE id = ? AND role = 'admin'");
    $stmt_chk->bind_param("i", $_SESSION['user_id']);
    $stmt_chk->execute();
    $row_chk = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();
    $chk_rights = $row_chk && $row_chk['rights'] ? json_decode($row_chk['rights'], true) : null;
    if (!($chk_rights === null || in_array('manage_passwords', (array)$chk_rights))) {
        header("Location: ../auth/login.php");
        exit();
    }
}

// Determine dashboard to return to based on 'from' parameter
$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
$back_dashboard = ($from === 'admin') ? 'admin_dashboard.php' : 'dashboard.php';

$message = "";
$message_type = "";

// Handle Set/Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
    $emp_id = (int)$_POST['emp_id'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($new_password) || empty($confirm_password)) {
        $message = "All password fields are required";
        $message_type = "danger";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match";
        $message_type = "danger";
    } elseif (strlen($new_password) < 6) {
        $message = "Password must be at least 6 characters";
        $message_type = "danger";
    } else {
        // First check if employee already has a password set
        $check_stmt = $conn->prepare("SELECT password_set FROM users WHERE id = ? AND role = 'employee'");
        $check_stmt->bind_param("i", $emp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $employee_data = $check_result->fetch_assoc();
        $already_set = $employee_data && $employee_data['password_set'] ? true : false;
        $check_stmt->close();
        
        // Hash password
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        
        // Update password and set password_set to true
        $stmt = $conn->prepare("UPDATE users SET password = ?, password_set = TRUE WHERE id = ? AND role = 'employee'");
        $stmt->bind_param("si", $hashed_password, $emp_id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                if ($already_set) {
                    $message = "✓ Password reset successfully! Employee can now login with the new password.";
                } else {
                    $message = "✓ Password set successfully! Employee can now login.";
                }
                $message_type = "success";
            } else {
                $message = "Employee not found";
                $message_type = "warning";
            }
        } else {
            $message = "Error: " . $stmt->error;
            $message_type = "danger";
        }
        $stmt->close();
    }
}

// Fetch departments
$dept_stmt = $conn->prepare("SELECT DISTINCT department FROM users WHERE role = 'employee' AND department IS NOT NULL ORDER BY department");
$dept_stmt->execute();
$dept_result = $dept_stmt->get_result();
$departments = [];
while ($dept_row = $dept_result->fetch_assoc()) {
    $departments[] = $dept_row['department'];
}
$dept_stmt->close();

// Fetch locations
$loc_stmt = $conn->prepare("SELECT DISTINCT location FROM users WHERE role = 'employee' AND location IS NOT NULL ORDER BY location");
$loc_stmt->execute();
$loc_result = $loc_stmt->get_result();
$locations = [];
while ($loc_row = $loc_result->fetch_assoc()) {
    $locations[] = $loc_row['location'];
}
$loc_stmt->close();

// Fetch employees without passwords or with password status
$stmt = $conn->prepare("SELECT id, name, email, employee_id, department, phone, password_set, location FROM users WHERE role = 'employee' ORDER BY password_set ASC, name ASC");
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employee Passwords</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        .password-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-input-group .form-control {
            padding-right: 45px;
        }
        .toggle-password-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toggle-password-btn:hover {
            color: #764ba2;
        }
        .password-modal {
            backdrop-filter: blur(5px);
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container mt-5">
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-12">
            <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary btn-sm">← Back to Dashboard</a>
        </div>
    </div>

    <!-- Employees List -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">🔐 Employee Password Management</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info" role="alert">
                <strong>ℹ️ Instructions:</strong>
                <ul class="mb-0 mt-2">
                    <li><strong>Set Password:</strong> Click "Set Password" for new employees without passwords (badge: <span class="badge bg-warning">✗ Not Set</span>)</li>
                    <li><strong>Reset Forgot Password:</strong> Click "Change Password" if employee forgot their password (badge: <span class="badge bg-success">✓ Set</span>)</li>
                    <li>Passwords must be at least 6 characters long</li>
                    <li>After setting/changing password, employee can login with the new credentials</li>
                    <li>All password changes are logged and secure</li>
                </ul>
            </div>

            <!-- Search and Filter Section -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label"><strong>🔍 Search Employee</strong></label>
                            <input type="text" class="form-control" id="searchInput" placeholder="Search by name or employee ID...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><strong>🏢 Filter by Department</strong></label>
                            <select class="form-control" id="departmentFilter">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label"><strong>📍 Filter by Location</strong></label>
                            <select class="form-control" id="locationFilter">
                                <option value="">All Locations</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover" id="employeesTable">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>Employee Name</th>
                            <th>Email</th>
                            <th>Employee ID</th>
                            <th>Department</th>
                            <th>Location</th>
                            <th>Phone</th>
                            <th>Pass. Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        while ($row = $result->fetch_assoc()) {
                            $pass_status = $row['password_set'] ? '<span class="badge bg-success">✓ Set</span>' : '<span class="badge bg-warning">✗ Not Set</span>';
                            $action_text = $row['password_set'] ? 'Change Password' : 'Set Password';
                            $dept = htmlspecialchars($row['department'] ?? 'N/A');
                            $location = htmlspecialchars($row['location'] ?? 'N/A');
                            $emp_name = htmlspecialchars($row['name']);
                            echo "<tr data-name='" . $emp_name . "' data-empid='" . htmlspecialchars($row['employee_id']) . "' data-dept='" . $dept . "' data-location='" . $location . "'>";
                            echo "<td>" . $row['id'] . "</td>";
                            echo "<td>" . $emp_name . "</td>";
                            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                            echo "<td>" . htmlspecialchars($row['employee_id']) . "</td>";
                            echo "<td>" . $dept . "</td>";
                            echo "<td>" . $location . "</td>";
                            echo "<td>" . htmlspecialchars($row['phone']) . "</td>";
                            echo "<td>" . $pass_status . "</td>";
                            $emp_name_js = htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8');
                            $emp_email_js = htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8');
                            echo "<td><button type='button' class='btn btn-sm btn-primary' onclick='openPasswordModal(" . $row['id'] . ", &quot;" . $emp_name_js . "&quot;, &quot;" . $emp_email_js . "&quot;, " . ($row['password_set'] ? 'true' : 'false') . ")'>" . $action_text . "</button></td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>
</div>

<!-- Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">🔐 Set Employee Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="passwordForm">
                <div class="modal-body">
                    <input type="hidden" name="emp_id" id="emp_id" value="">
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Employee:</strong></label>
                        <p id="emp_name_display" class="form-control-plaintext"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><strong>Email:</strong></label>
                        <p id="emp_email_display" class="form-control-plaintext"></p>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="new_password"><strong>New Password</strong></label>
                        <div class="password-input-group">
                            <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Min 6 characters" required>
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordField('new_password')">
                                👁️
                            </button>
                        </div>
                        <small class="text-muted">Must be at least 6 characters long</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="confirm_password"><strong>Confirm Password</strong></label>
                        <div class="password-input-group">
                            <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm password" required>
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordField('confirm_password')">
                                👁️
                            </button>
                        </div>
                    </div>

                    <div class="alert alert-warning" role="alert">
                        <strong>⚠️ Note:</strong> Remember this password or share it securely with the employee. The password cannot be recovered once set.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="set_password" class="btn btn-primary">✓ Set Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openPasswordModal(emp_id, emp_name, emp_email, isPasswordSet) {
        document.getElementById('emp_id').value = emp_id;
        document.getElementById('emp_name_display').textContent = emp_name;
        document.getElementById('emp_email_display').textContent = emp_email;
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_password').value = '';
        
        // Update modal title and message based on whether password is already set
        const modalTitle = document.querySelector('#passwordModal .modal-title');
        const warningAlert = document.querySelector('#passwordModal .alert-warning');
        
        if (isPasswordSet) {
            // Reset forgot password
            modalTitle.innerHTML = '🔄 Reset Employee Password (Forgot Password)';
            warningAlert.innerHTML = '<strong>⚠️ Password Reset:</strong> Employee reported they forgot their password. Enter a new password below. The employee will use this new password to login.';
        } else {
            // Set initial password
            modalTitle.innerHTML = '🔐 Set Employee Password';
            warningAlert.innerHTML = '<strong>⚠️ Note:</strong> This is the employee\'s initial password. Remember this password or share it securely with the employee. The password cannot be recovered once set.';
        }
        
        // Open modal
        const modal = new bootstrap.Modal(document.getElementById('passwordModal'));
        modal.show();
    }

    function togglePasswordField(fieldId) {
        const field = document.getElementById(fieldId);
        if (field.type === 'password') {
            field.type = 'text';
        } else {
            field.type = 'password';
        }
    }

    // Form validation on submit
    document.getElementById('passwordForm').addEventListener('submit', function(e) {
        const newPass = document.getElementById('new_password').value;
        const confirmPass = document.getElementById('confirm_password').value;
        
        if (newPass !== confirmPass) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Password Mismatch',
                text: 'Passwords do not match. Please try again.',
                confirmButtonColor: '#3085d6'
            });
            return false;
        }
        
        if (newPass.length < 6) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Password Too Short',
                text: 'Password must be at least 6 characters long.',
                confirmButtonColor: '#3085d6'
            });
            return false;
        }
    });

    // Search and Filter Functionality
    document.getElementById('searchInput').addEventListener('keyup', filterTable);
    document.getElementById('departmentFilter').addEventListener('change', filterTable);
    document.getElementById('locationFilter').addEventListener('change', filterTable);

    function filterTable() {
        const table = document.getElementById('employeesTable');
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        const searchInput = document.getElementById('searchInput').value.toLowerCase();
        const departmentFilter = document.getElementById('departmentFilter').value;
        const locationFilter = document.getElementById('locationFilter').value;
        
        let visibleCount = 0;
        
        Array.from(rows).forEach(row => {
            const name = row.getAttribute('data-name').toLowerCase();
            const empid = row.getAttribute('data-empid').toLowerCase();
            const dept = row.getAttribute('data-dept');
            const location = row.getAttribute('data-location');
            
            // Check search criteria
            const matchesSearch = name.includes(searchInput) || empid.includes(searchInput);
            
            // Check department filter
            const matchesDept = departmentFilter === '' || dept === departmentFilter;
            
            // Check location filter
            const matchesLocation = locationFilter === '' || location === locationFilter;
            
            // Show or hide row
            if (matchesSearch && matchesDept && matchesLocation) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Show "No results" message if needed
        const tbody = table.getElementsByTagName('tbody')[0];
        let noResultsRow = document.getElementById('noResultsRow');
        
        if (visibleCount === 0) {
            if (!noResultsRow) {
                noResultsRow = document.createElement('tr');
                noResultsRow.id = 'noResultsRow';
                noResultsRow.innerHTML = '<td colspan="9" class="text-center text-muted py-3">No employees found matching your search criteria.</td>';
                tbody.appendChild(noResultsRow);
            }
            noResultsRow.style.display = '';
        } else {
            if (noResultsRow) {
                noResultsRow.style.display = 'none';
            }
        }
    }
</script>

</body>
</html>

