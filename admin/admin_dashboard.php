<?php
session_start();
include('../config/db.php');

// Enable login requirement
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch admin name
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, rights FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin_user = $result->fetch_assoc();
$admin_name = $admin_user['name'] ?? 'Admin';
$admin_rights = null;
if (!empty($admin_user['rights'])) {
    $decoded = json_decode($admin_user['rights'], true);
    if (is_array($decoded) && !empty($decoded)) {
        $admin_rights = $decoded;
    }
}
$stmt->close();

// Returns true if admin has access to the given card key (null = all access)
function adminCan($key, $rights) {
    return $rights === null || in_array($key, $rights);
}

// Ensure leave_applications table exists (for KPI stats below)
$conn->query("CREATE TABLE IF NOT EXISTS leave_applications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    leave_type VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL, end_date DATE NOT NULL,
    days_count DECIMAL(5,1) NOT NULL DEFAULT 1,
    reason TEXT, status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
    admin_notes TEXT, reviewed_by INT, reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// KPI stats (only computed/shown for cards this admin has rights to)
$stat_total_emp = (int)($conn->query("SELECT COUNT(*) c FROM users WHERE role='employee' AND status='Working'")->fetch_assoc()['c'] ?? 0);
$stat_present_today = (int)($conn->query("SELECT COUNT(DISTINCT a.user_id) c FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.date = CURDATE() AND a.status IN ('Present','Late') AND u.role='employee'")->fetch_assoc()['c'] ?? 0);
$stat_on_leave_today = (int)($conn->query("SELECT COUNT(DISTINCT user_id) c FROM leave_applications WHERE status='Approved' AND CURDATE() BETWEEN start_date AND end_date")->fetch_assoc()['c'] ?? 0);
$stat_pending_leaves = (int)($conn->query("SELECT COUNT(*) c FROM leave_applications WHERE status='Pending'")->fetch_assoc()['c'] ?? 0);

$dm_hour = (int)date('G');
$dm_greeting = $dm_hour < 12 ? 'Good morning' : ($dm_hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title class="titel">Admin Dashboard</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/dashboard-modern.css">
    <style>
        .dashboard-card {
            border-left: 5px solid;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
        }
        .card-employees {
            border-left-color: #0d6efd;
        }
        .card-departments {
            border-left-color: #28a745;
        }
        .card-attendance {
            border-left-color: #17a2b8;
        }
        .card-export {
            border-left-color: #6f42c1;
        }
        .card-companies {
            border-left-color: #20c997;
        }
        .card-shifts {
            border-left-color: #fd7e14;
        }
        .card-locations {
            border-left-color: #e83e8c;
        }
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .card-links {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .card-links a {
            flex: 1;
            min-width: 100px;
        }
        .navbar-custom {
            position: relative;
        }

        .navbar-logo {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            height: 50px;
            z-index: 10;
        }

        .navbar-logo img {
            height: 100%;
            width: auto;
            max-width: 200px;
        }

        .navbar-welcome {
            position: absolute;
            left: 20px;
            display: flex;
            align-items: center;
            height: 100%;
            color: white;
        }

        .navbar-logout {
            margin-left: auto;
            padding-right: 20px;
        }

        @media (max-width: 768px) {
            .navbar-welcome {
                display: none;
            }
            .navbar-logo {
                height: 45px;
            }
            .navbar-logo img {
                max-width: 150px;
            }
        }
    </style>
</head>
<body class="modern-dash">

<?php include('_navbar.php'); ?>

<div class="container mt-5 mb-5">
    <div class="dm-header">
        <div>
            <h1><?= htmlspecialchars($dm_greeting) ?>, <?= htmlspecialchars($admin_name) ?> 👋</h1>
            <div class="dm-sub">Here's what's happening across your organization today.</div>
        </div>
        <div class="dm-date"><?= date('l, d M Y') ?></div>
    </div>

    <?php if (adminCan('manage_employees', $admin_rights) || adminCan('view_attendance', $admin_rights) || adminCan('leave_management', $admin_rights)): ?>
    <div class="dm-stats">
        <?php if (adminCan('manage_employees', $admin_rights)): ?>
        <div class="dm-stat c-blue">
            <div class="dm-stat-icon">👥</div>
            <div><div class="dm-stat-value"><?= $stat_total_emp ?></div><div class="dm-stat-label">Total Employees</div></div>
        </div>
        <?php endif; ?>
        <?php if (adminCan('view_attendance', $admin_rights)): ?>
        <div class="dm-stat c-green">
            <div class="dm-stat-icon">✅</div>
            <div><div class="dm-stat-value"><?= $stat_present_today ?></div><div class="dm-stat-label">Present Today</div></div>
        </div>
        <?php endif; ?>
        <?php if (adminCan('leave_management', $admin_rights)): ?>
        <div class="dm-stat c-orange">
            <div class="dm-stat-icon">🗓️</div>
            <div><div class="dm-stat-value"><?= $stat_on_leave_today ?></div><div class="dm-stat-label">On Leave Today</div></div>
        </div>
        <div class="dm-stat c-red">
            <div class="dm-stat-icon">⏳</div>
            <div><div class="dm-stat-value"><?= $stat_pending_leaves ?></div><div class="dm-stat-label">Pending Leave Requests</div></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <h3 class="mb-4 text-center">Admin Dashboard</h3>

    <!-- System Setup Notice -->
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <strong>⚠️ First-Time Setup Required:</strong> If employees are getting "Duplicate entry" errors when trying to punch in multiple times per day, click the button below to run the database setup migration.
        <a href="setup.php" class="btn btn-warning btn-sm ms-2">🔧 Run Setup</a>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <div class="row g-4">

        <?php if (adminCan('manage_employees', $admin_rights)): ?>
        <!-- Manage Employees Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-employees shadow">
                <div class="card-body">
                    <h5 class="card-title">👥 Manage Employees</h5>
                    <p class="card-text text-muted">Add, edit, and manage employee records and information.</p>
                    <div class="card-links">
                        <a href="employees.php?from=admin" class="btn btn-primary btn-sm">View Employees</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manage_departments', $admin_rights)): ?>
        <!-- Departments Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-departments shadow">
                <div class="card-body">
                    <h5 class="card-title">🏢 Manage Projects</h5>
                    <p class="card-text text-muted">Create and manage projects for organizing employees.</p>
                    <div class="card-links">
                        <a href="department.php?from=admin" class="btn btn-success btn-sm">View Projects</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('view_attendance', $admin_rights)): ?>
        <!-- View Attendance Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-attendance shadow">
                <div class="card-body">
                    <h5 class="card-title">📊 View Attendance</h5>
                    <p class="card-text text-muted">Monitor and review employee attendance records and logs.</p>
                    <div class="card-links">
                        <a href="attendance.php?from=admin" class="btn btn-info btn-sm">View Records</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manual_attendance', $admin_rights)): ?>
        <!-- Manual Attendance Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-attendance shadow">
                <div class="card-body">
                    <h5 class="card-title">⌨️ Manual Attendance</h5>
                    <p class="card-text text-muted">Manually record attendance for employees without smartphones.</p>
                    <div class="card-links">
                        <a href="manual_attendance.php" class="btn btn-info btn-sm">Record Attendance</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('comp_off', $admin_rights)): ?>
        <!-- Comp Off Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color: #ffc107;">
                <div class="card-body">
                    <h5 class="card-title">📅 Comp Off Management</h5>
                    <p class="card-text text-muted">Assign compensatory off to employees for worked rest days.</p>
                    <div class="card-links">
                        <a href="comp_off_management.php" class="btn btn-warning btn-sm">Manage Comp Off</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('export_reports', $admin_rights)): ?>
        <!-- Export Report Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-export shadow">
                <div class="card-body">
                    <h5 class="card-title">📥 Export Reports</h5>
                    <p class="card-text text-muted">Generate and download monthly attendance reports as CSV.</p>
                    <div class="card-links">
                        <a href="export_monthly.php?from=admin" class="btn btn-info btn-sm" style="background-color: #6f42c1; border-color: #6f42c1; color:white;">Export Reports</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manage_companies', $admin_rights)): ?>
        <!-- Manage Companies Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-companies shadow">
                <div class="card-body">
                    <h5 class="card-title">🏪 Manage Companies</h5>
                    <p class="card-text text-muted">Add, edit, and manage company information for employee assignment.</p>
                    <div class="card-links">
                        <a href="companies.php?from=admin" class="btn btn-success btn-sm" style="background-color: #20c997; border-color: #20c997; color:white;">Manage Companies</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manage_shifts', $admin_rights)): ?>
        <!-- Manage Shifts Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-shifts shadow">
                <div class="card-body">
                    <h5 class="card-title">⏰ Manage Shifts</h5>
                    <p class="card-text text-muted">Create and manage shift times for employee scheduling.</p>
                    <div class="card-links">
                        <a href="shifts.php?from=admin" class="btn btn-warning btn-sm" style="background-color: #fd7e14; border-color: #fd7e14; color:white;">Manage Shifts</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manage_locations', $admin_rights)): ?>
        <!-- Manage Locations Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card card-locations shadow">
                <div class="card-body">
                    <h5 class="card-title">📍 Manage Locations</h5>
                    <p class="card-text text-muted">Add and manage office locations and branches.</p>
                    <div class="card-links">
                        <a href="locations.php?from=admin" class="btn btn-danger btn-sm" style="background-color: #e83e8c; border-color: #e83e8c; color:white;">Manage Locations</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('od_management', $admin_rights)): ?>
        <!-- OD Management Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color: #ff6b6b;">
                <div class="card-body">
                    <h5 class="card-title">📍 OD Management</h5>
                    <p class="card-text text-muted">Mark Out of Station Duty (OD) for employees with date selection.</p>
                    <div class="card-links">
                        <a href="od_management.php?from=admin" class="btn btn-sm" style="background-color: #ff6b6b; border-color: #ff6b6b; color:white;">Manage OD</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('gps_restriction', $admin_rights)): ?>
        <!-- GPS Restriction Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color: #20c997;">
                <div class="card-body">
                    <h5 class="card-title">📡 GPS Attendance Restriction</h5>
                    <p class="card-text text-muted">Control which employees must be within 100 m of the office to mark attendance.</p>
                    <div class="card-links">
                        <a href="geo_restriction.php?from=admin" class="btn btn-sm" style="background-color: #20c997; border-color: #20c997; color:white;">Manage GPS Rules</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manage_passwords', $admin_rights)): ?>
        <!-- Manage Passwords Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color: #dc3545;">
                <div class="card-body">
                    <h5 class="card-title">🔐 Manage Passwords</h5>
                    <p class="card-text text-muted">Set and change employee login passwords securely.</p>
                    <div class="card-links">
                        <a href="manage_passwords.php?from=admin" class="btn btn-danger btn-sm">Manage Passwords</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('salary_slip', $admin_rights)): ?>
        <!-- Salary Slip Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color:#6610f2;">
                <div class="card-body">
                    <h5 class="card-title">💰 Salary Slip</h5>
                    <p class="card-text text-muted">Generate and download monthly salary slips for employees with automatic deduction calculations.</p>
                    <div class="card-links">
                        <a href="salary_slip.php" class="btn btn-sm" style="background:#6610f2;color:#fff;border-color:#6610f2;">Generate Slips</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('leave_management', $admin_rights)): ?>
        <!-- Leave Management Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color:#fd7e14;">
                <div class="card-body">
                    <h5 class="card-title">🗓️ Leave Management</h5>
                    <p class="card-text text-muted">Review, approve or reject employee leave applications. Rejected leaves are auto-deducted from salary.</p>
                    <div class="card-links">
                        <a href="leave_management.php" class="btn btn-sm" style="background:#fd7e14;color:#fff;border-color:#fd7e14;">Manage Leaves</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (adminCan('manage_admins', $admin_rights)): ?>
        <!-- Manage Admins Card -->
        <div class="col-md-6 col-lg-4">
            <div class="card dashboard-card shadow" style="border-left-color:#ffc107;">
                <div class="card-body">
                    <h5 class="card-title">👤 Manage Admins</h5>
                    <p class="card-text text-muted">Create, edit, and manage other admin accounts in the system.</p>
                    <div class="card-links">
                        <a href="admin.php?from=admin" class="btn btn-warning btn-sm">View Admins</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
