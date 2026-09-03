<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'suparadmin' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit();
}

// A regular admin may only manage admins if granted the 'manage_admins' right
if ($_SESSION['role'] === 'admin') {
    $__uid = (int)$_SESSION['user_id'];
    $__chk = $conn->prepare("SELECT rights FROM users WHERE id=?");
    $__chk->bind_param("i", $__uid);
    $__chk->execute();
    $__row = $__chk->get_result()->fetch_assoc();
    $__chk->close();
    $__rights = !empty($__row['rights']) ? json_decode($__row['rights'], true) : null;
    if (!is_array($__rights) || !in_array('manage_admins', $__rights)) {
        header("Location: admin_dashboard.php");
        exit();
    }
}

// Back dashboard depends on role
$back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';

$message = "";
$message_type = "";

// Add columns if they don't exist
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'employee_id'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN employee_id VARCHAR(50)");
}
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'company'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN company VARCHAR(100)");
}
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) UNIQUE");
}
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'rights'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN rights TEXT DEFAULT NULL");
}

// Create departments table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Fetch all existing departments
$dept_stmt = $conn->prepare("SELECT name FROM departments ORDER BY name");
$dept_stmt->execute();
$dept_result = $dept_stmt->get_result();
$departments = [];
while ($dept_row = $dept_result->fetch_assoc()) {
    $departments[] = $dept_row['name'];
}
$dept_stmt->close();

// Define available rights/cards for admins
$available_rights = [
    'manage_employees'   => '👥 Manage Employees',
    'manage_departments' => '🏢 Manage Departments',
    'view_attendance'    => '📊 View Attendance',
    'manual_attendance'  => '⌨️ Manual Attendance',
    'comp_off'           => '📅 Comp Off Management',
    'export_reports'     => '📥 Export Reports',
    'manage_companies'   => '🏪 Manage Companies',
    'manage_shifts'      => '⏰ Manage Shifts',
    'manage_locations'   => '📍 Manage Locations',
    'od_management'      => '📍 OD Management',
    'gps_restriction'    => '📡 GPS Restriction',
    'manage_passwords'   => '🔐 Manage Passwords',
    'salary_slip'        => '💰 Salary Slip',
    'leave_management'   => '🗓️ Leave Management',
    'manage_admins'      => '👤 Manage Admins',
    'manage_supervisors' => '🧑‍✈️ Create Supervisor',
    'attendance_policy'  => '⚖️ Attendance Policy',
    'project_holidays'   => '🎌 Project Holidays',
];

// Handle Add Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin'])) {
    $name = htmlspecialchars($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $department = htmlspecialchars($_POST['department']);
    $employee_id = htmlspecialchars($_POST['employee_id']);
    $company = htmlspecialchars($_POST['company']);
    $phone = htmlspecialchars($_POST['phone']);
    $rights_selected = isset($_POST['rights']) && is_array($_POST['rights']) ? $_POST['rights'] : [];
    $rights_selected = array_values(array_filter($rights_selected, fn($r) => array_key_exists($r, $available_rights)));
    $rights_json = !empty($rights_selected) ? json_encode($rights_selected) : null;
    
    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($department) || empty($employee_id) || empty($company) || empty($phone)) {
        $message = "All fields are required";
        $message_type = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $message_type = "danger";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match";
        $message_type = "danger";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters";
        $message_type = "danger";
    } else {
        // Check if phone already exists
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt_check->bind_param("s", $phone);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $message = "Phone number already exists";
            $message_type = "danger";
        } else {
            // Hash password and insert
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $stmt_insert = $conn->prepare("INSERT INTO users (name, email, password, role, department, employee_id, company, phone, rights) VALUES (?, ?, ?, 'admin', ?, ?, ?, ?, ?)");
            $stmt_insert->bind_param("ssssssss", $name, $email, $hashed_password, $department, $employee_id, $company, $phone, $rights_json);
            
            if ($stmt_insert->execute()) {
                $message = "✓ Admin created successfully!";
                $message_type = "success";
            } else {
                $message = "Error: " . $stmt_insert->error;
                $message_type = "danger";
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}

// Handle Update Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin'])) {
    $admin_id = (int)$_POST['admin_id'];
    $name = htmlspecialchars($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $department = htmlspecialchars($_POST['department']);
    $employee_id = htmlspecialchars($_POST['employee_id']);
    $company = htmlspecialchars($_POST['company']);
    $phone = htmlspecialchars($_POST['phone']);
    $rights_selected = isset($_POST['rights']) && is_array($_POST['rights']) ? $_POST['rights'] : [];
    $rights_selected = array_values(array_filter($rights_selected, fn($r) => array_key_exists($r, $available_rights)));
    $rights_json = !empty($rights_selected) ? json_encode($rights_selected) : null;
    
    // Validation
    if (empty($name) || empty($email) || empty($department) || empty($employee_id) || empty($company) || empty($phone)) {
        $message = "All fields are required";
        $message_type = "danger";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Invalid email format";
        $message_type = "danger";
    } else {
        // Check if phone already exists (for other admins)
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
        $stmt_check->bind_param("si", $phone, $admin_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $message = "Phone number already exists for another admin";
            $message_type = "danger";
        } else {
            // Update admin
            $stmt_update = $conn->prepare("UPDATE users SET name = ?, email = ?, department = ?, employee_id = ?, company = ?, phone = ?, rights = ? WHERE id = ? AND role = 'admin'");
            $stmt_update->bind_param("sssssssi", $name, $email, $department, $employee_id, $company, $phone, $rights_json, $admin_id);
            
            if ($stmt_update->execute()) {
                $message = "✓ Admin updated successfully!";
                $message_type = "success";
                header("refresh:1.5;url=?view_admin=" . $admin_id);
            } else {
                $message = "Error: " . $stmt_update->error;
                $message_type = "danger";
            }
            $stmt_update->close();
        }
        $stmt_check->close();
    }
}

// Handle Delete Admin
if (isset($_GET['delete_admin'])) {
    $delete_id = (int)$_GET['delete_admin'];
    
    // Delete the admin (CASCADE will delete related attendance records)
    $stmt_delete = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'");
    $stmt_delete->bind_param("i", $delete_id);
    
    if ($stmt_delete->execute()) {
        if ($stmt_delete->affected_rows > 0) {
            $message = "✓ Admin deleted successfully!";
            $message_type = "success";
        } else {
            $message = "⚠ Admin not found";
            $message_type = "warning";
        }
    } else {
        $message = "✗ Error: " . $stmt_delete->error;
        $message_type = "danger";
    }
    $stmt_delete->close();
}

// Fetch all admins using prepared statement
$stmt = $conn->prepare("SELECT id, name, email, department, employee_id, company, phone, rights FROM users WHERE role = 'admin' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management - Attendance System</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
    </style>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-5">
        <!-- Toggle Button -->
        <div class="mb-4">
            <button class="btn btn-primary btn-lg" onclick="toggleForm()" id="toggleBtn">
                ➕ Add New Admin
            </button>
        </div>

        <!-- Add Admin Form (Hidden by default) -->
        <div class="card mb-4" id="addForm" style="display: none;">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">➕ Add New Admin</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Employee ID</label>
                        <input type="text" name="employee_id" class="form-control" placeholder="e.g., ADM001" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Enter name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" placeholder="Enter email" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Company</label>
                        <input type="text" name="company" class="form-control" placeholder="Company name" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" placeholder="e.g., +1234567890" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Department</label>
                        <select name="department" class="form-control" required>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Password</label>
                        <div class="password-input-group">
                            <input type="password" name="password" id="admin_password" class="form-control" placeholder="Min 6 characters" required>
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordField('admin_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm Password</label>
                        <div class="password-input-group">
                            <input type="password" name="confirm_password" id="admin_confirm_password" class="form-control" placeholder="Confirm password" required>
                            <button type="button" class="toggle-password-btn" onclick="togglePasswordField('admin_confirm_password')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-12">                        <label class="form-label fw-bold">🔑 Rights <small class="text-muted fw-normal">(Select which dashboard cards this admin can access)</small></label>
                        <div class="border rounded p-3 bg-light">
                            <div class="mb-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllCheckboxes('addRightsBox')">Select All / Deselect All</button>
                            </div>
                            <div class="row g-2" id="addRightsBox">
                                <?php foreach ($available_rights as $rkey => $rlabel): ?>
                                <div class="col-md-4 col-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="rights[]" value="<?php echo htmlspecialchars($rkey); ?>" id="add_r_<?php echo $rkey; ?>">
                                        <label class="form-check-label" for="add_r_<?php echo $rkey; ?>"><?php echo $rlabel; ?></label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">                        <button type="submit" name="add_admin" class="btn btn-success">✓ Create Admin</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleForm()">✕ Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Admins List -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">All Admins</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Employee ID</th>
                                <th>Company</th>
                                <th>Phone</th>
                                <th>Department</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            while ($row = $result->fetch_assoc()) {
                                echo "<tr>";
                                echo "<td>" . $row['id'] . "</td>";
                                echo "<td>" . htmlspecialchars($row['name']) . "</td>";
                                echo "<td>" . htmlspecialchars($row['email']) . "</td>";
                                echo "<td>" . ($row['employee_id'] ?? 'N/A') . "</td>";
                                echo "<td>" . ($row['company'] ?? 'N/A') . "</td>";
                                echo "<td>" . ($row['phone'] ?? 'N/A') . "</td>";
                                echo "<td>" . ($row['department'] ?? 'N/A') . "</td>";
                                echo "<td>";
                                echo "<a href='?view_admin=" . $row['id'] . "' class='btn btn-sm btn-info'>View</a> ";
                                echo "<a href='?view_admin=" . $row['id'] . "&edit=1' class='btn btn-sm btn-warning'>Edit</a> ";
                                echo "<button type='button' onclick=\"confirmDeleteAdmin(" . $row['id'] . ")\" class='btn btn-sm btn-danger'>Delete</button>";
                                echo "</td>";
                                echo "</tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Admin Details Section -->
        <?php
        if (isset($_GET['view_admin'])) {
            $view_id = (int)$_GET['view_admin'];
            $stmt_view = $conn->prepare("SELECT id, name, email, employee_id, company, phone, department, rights FROM users WHERE id = ? AND role = 'admin'");
            $stmt_view->bind_param("i", $view_id);
            $stmt_view->execute();
            $result_view = $stmt_view->get_result();
            
            if ($result_view->num_rows > 0) {
                $admin = $result_view->fetch_assoc();
                $is_edit = isset($_GET['edit']) && $_GET['edit'] == '1';
                $admin_rights_arr = [];
                if (!empty($admin['rights'])) {
                    $admin_rights_arr = json_decode($admin['rights'], true) ?: [];
                }
                ?>
                <div class="card mt-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">👤 Admin <?php echo $is_edit ? 'Edit' : 'Details'; ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$is_edit): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Name:</strong> <?php echo htmlspecialchars($admin['name']); ?></p>
                                    <p><strong>Employee ID:</strong> <?php echo htmlspecialchars($admin['employee_id']); ?></p>
                                    <p><strong>Email:</strong> <?php echo htmlspecialchars($admin['email']); ?></p>
                                    <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($admin['phone']); ?></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Company:</strong> <?php echo htmlspecialchars($admin['company']); ?></p>
                                    <p><strong>Department:</strong> <?php echo htmlspecialchars($admin['department'] ?? 'N/A'); ?></p>
                                    <p><strong>User ID:</strong> <?php echo $admin['id']; ?></p>
                                    <p><strong>Rights:</strong>
                                        <?php
                                        $view_rights = !empty($admin['rights']) ? json_decode($admin['rights'], true) : null;
                                        if (is_array($view_rights) && !empty($view_rights)):
                                            foreach ($view_rights as $rk):
                                                if (isset($available_rights[$rk])): ?>
                                            <span class="badge bg-primary me-1"><?php echo htmlspecialchars($available_rights[$rk]); ?></span>
                                        <?php       endif;
                                            endforeach;
                                        else: ?>
                                            <span class="text-muted">All cards (no restriction)</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <a href="?view_admin=<?php echo $admin['id']; ?>&edit=1" class="btn btn-warning">✎ Edit Admin</a>
                            <a href="admin.php" class="btn btn-secondary">Back to List</a>
                        <?php else: ?>
                            <form method="POST" class="row g-3">
                                <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                <div class="col-md-3">
                                    <label class="form-label">Employee ID</label>
                                    <input type="text" name="employee_id" class="form-control" value="<?php echo htmlspecialchars($admin['employee_id']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($admin['name']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Email</label>
                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Company</label>
                                    <input type="text" name="company" class="form-control" value="<?php echo htmlspecialchars($admin['company']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($admin['phone']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Department</label>
                                    <select name="department" class="form-control" required>
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $admin['department'] === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">🔑 Rights <small class="text-muted fw-normal">(Select which dashboard cards this admin can access)</small></label>
                                    <div class="border rounded p-3 bg-light">
                                        <div class="mb-2">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllCheckboxes('editRightsBox')">Select All / Deselect All</button>
                                        </div>
                                        <div class="row g-2" id="editRightsBox">
                                            <?php foreach ($available_rights as $rkey => $rlabel): ?>
                                            <div class="col-md-4 col-6">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="rights[]" value="<?php echo htmlspecialchars($rkey); ?>" id="edit_r_<?php echo $rkey; ?>" <?php echo in_array($rkey, $admin_rights_arr) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="edit_r_<?php echo $rkey; ?>"><?php echo $rlabel; ?></label>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="update_admin" class="btn btn-success">✓ Update Admin</button>
                                    <a href="?view_admin=<?php echo $admin['id']; ?>" class="btn btn-secondary">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            }
            $stmt_view->close();
        }
        ?>

        <div class="mt-3">
            <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleForm() {
            const form = document.getElementById('addForm');
            const btn = document.getElementById('toggleBtn');
            
            if (form.style.display === 'none') {
                form.style.display = 'block';
                btn.textContent = '✕ Close Form';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-danger');
            } else {
                form.style.display = 'none';
                btn.textContent = '➕ Add New Admin';
                btn.classList.remove('btn-danger');
                btn.classList.add('btn-primary');
            }
        }
        
        function togglePasswordField(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = event.target.closest('.toggle-password-btn');
            const icon = btn.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        function toggleAllCheckboxes(containerId) {
            const container = document.getElementById(containerId);
            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => { cb.checked = !allChecked; });
        }
        
        function confirmDeleteAdmin(adminId) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'This admin and all their attendance records will be deleted permanently!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_admin=' + adminId;
                }
            });
        }
        
        <?php
        if ($message) {
            $icon = 'success';
            $title = 'Success';
            if ($message_type === 'danger') {
                $icon = 'error';
                $title = 'Error';
            } elseif ($message_type === 'warning') {
                $icon = 'warning';
                $title = 'Warning';
            }
            $message_escaped = addslashes($message);
            echo "Swal.fire({
                icon: '$icon',
                title: '$title',
                text: '$message_escaped',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });";
        }
        ?>
    </script>
</body>
</html>

<?php
$stmt->close();
?>

