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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title class="titel">Admin Dashboard</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
<body>

<nav class="navbar navbar-dark bg-dark navbar-custom" style="min-height: 70px;">
    <div class="container-fluid position-relative">
        
        <!-- Welcome Text (Left) -->
        <div class="navbar-welcome">
            <span class="text-white">Welcome, <?php echo htmlspecialchars($admin_name); ?></span>
        </div>

        <!-- Centered Logo -->
        <div class="navbar-logo">
            <img src="../assets/images/logo.png" alt="Company Logo">
        </div>

        <!-- Logout Button (Right) -->
        <div class="navbar-logout">
            <a href="../auth/logout.php" class="btn btn-danger btn-sm">Logout</a>
        </div>
        
    </div>
</nav>

<div class="container mt-5 mb-5">
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
                    <h5 class="card-title">🏢 Manage Departments</h5>
                    <p class="card-text text-muted">Create and manage departments for organizing employees.</p>
                    <div class="card-links">
                        <a href="department.php?from=admin" class="btn btn-success btn-sm">View Departments</a>
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

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
