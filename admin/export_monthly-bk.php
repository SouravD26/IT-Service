<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Handle error messages from previous actions
$message = isset($_SESSION['export_error']) ? $_SESSION['export_error'] : '';
unset($_SESSION['export_error']);
$message_type = !empty($message) ? 'danger' : '';

// Determine dashboard to return to based on 'from' parameter or user role
$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
if ($from === 'suparadmin') {
    $back_dashboard = 'dashboard.php';
} elseif ($from === 'admin') {
    $back_dashboard = 'admin_dashboard.php';
} else {
    $back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';
}

// Fetch departments for dropdown
$dept_result = $conn->query("SELECT DISTINCT department FROM users WHERE status = 'Working' AND department IS NOT NULL ORDER BY department");
$departments = array();
if ($dept_result) {
    while ($row = $dept_result->fetch_assoc()) {
        $departments[] = $row['department'];
    }
}

// Fetch locations for dropdown
$loc_result = $conn->query("SELECT DISTINCT location FROM users WHERE status = 'Working' AND location IS NOT NULL ORDER BY location");
$locations = array();
if ($loc_result) {
    while ($row = $loc_result->fetch_assoc()) {
        $locations[] = $row['location'];
    }
}

$selected_department = isset($_POST['department']) ? $_POST['department'] : '';
$selected_location = isset($_POST['location']) ? $_POST['location'] : '';
$selected_companies = isset($_POST['companies']) ? (is_array($_POST['companies']) ? $_POST['companies'] : array()) : array();
$employees = array();

// Get current month/year as default for filtering resigned employees
$current_month = intval(date('m'));
$current_year = intval(date('Y'));

// Fetch employees for selected department and location
if ($selected_department === 'all' && !empty($selected_location)) {
    // Fetch all employees by location
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND location = ? AND company IN ($placeholders) ORDER BY department, name");
        $types = 's' . str_repeat('s', count($selected_companies));
        $stmt->bind_param($types, $selected_location, ...$selected_companies);
    } else {
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND location = ? ORDER BY department, name");
        $stmt->bind_param("s", $selected_location);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Filter: Only show resigned employees if they resigned in current month or later
        if ($row['status'] === 'Resign' && !empty($row['date_of_exit'])) {
            $resign_parts = explode('-', trim($row['date_of_exit']));
            if (count($resign_parts) === 3) {
                $resign_year = intval($resign_parts[0]);
                $resign_month = intval($resign_parts[1]);
                // Only show if resignation is in current month or later
                if ($resign_year < $current_year || ($resign_year === $current_year && $resign_month < $current_month)) {
                    continue; // Skip this resigned employee for past months
                }
            }
        }
        $employees[] = $row;
    }
    $stmt->close();
} elseif ($selected_department === 'all' && empty($selected_location)) {
    // Fetch all employees grouped by department
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders) ORDER BY department, name");
        $stmt->bind_param(str_repeat('s', count($selected_companies)), ...$selected_companies);
    } else {
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') ORDER BY department, name");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Filter: Only show resigned employees if they resigned in current month or later
        if ($row['status'] === 'Resign' && !empty($row['date_of_exit'])) {
            $resign_parts = explode('-', trim($row['date_of_exit']));
            if (count($resign_parts) === 3) {
                $resign_year = intval($resign_parts[0]);
                $resign_month = intval($resign_parts[1]);
                // Only show if resignation is in current month or later
                if ($resign_year < $current_year || ($resign_year === $current_year && $resign_month < $current_month)) {
                    continue; // Skip this resigned employee for past months
                }
            }
        }
        $employees[] = $row;
    }
    $stmt->close();
} elseif ($selected_department && $selected_department !== '' && !empty($selected_location)) {
    // Fetch employees for selected department and location
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND location = ? AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders) ORDER BY name");
        $types = 'ss' . str_repeat('s', count($selected_companies));
        $stmt->bind_param($types, $selected_department, $selected_location, ...$selected_companies);
    } else {
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND location = ? AND (status = 'Working' OR status = 'Resign') ORDER BY name");
        $stmt->bind_param("ss", $selected_department, $selected_location);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Filter: Only show resigned employees if they resigned in current month or later
        if ($row['status'] === 'Resign' && !empty($row['date_of_exit'])) {
            $resign_parts = explode('-', trim($row['date_of_exit']));
            if (count($resign_parts) === 3) {
                $resign_year = intval($resign_parts[0]);
                $resign_month = intval($resign_parts[1]);
                // Only show if resignation is in current month or later
                if ($resign_year < $current_year || ($resign_year === $current_year && $resign_month < $current_month)) {
                    continue; // Skip this resigned employee for past months
                }
            }
        }
        $employees[] = $row;
    }
    $stmt->close();
} elseif ($selected_department && $selected_department !== '') {
    // Fetch employees for selected department
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders) ORDER BY name");
        $types = 's' . str_repeat('s', count($selected_companies));
        $stmt->bind_param($types, $selected_department, ...$selected_companies);
    } else {
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND (status = 'Working' OR status = 'Resign') ORDER BY name");
        $stmt->bind_param("s", $selected_department);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Filter: Only show resigned employees if they resigned in current month or later
        if ($row['status'] === 'Resign' && !empty($row['date_of_exit'])) {
            $resign_parts = explode('-', trim($row['date_of_exit']));
            if (count($resign_parts) === 3) {
                $resign_year = intval($resign_parts[0]);
                $resign_month = intval($resign_parts[1]);
                // Only show if resignation is in current month or later
                if ($resign_year < $current_year || ($resign_year === $current_year && $resign_month < $current_month)) {
                    continue; // Skip this resigned employee for past months
                }
            }
        }
        $employees[] = $row;
    }
    $stmt->close();
} else {
    // No specific department/location selected - fetch all employees
    $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') ORDER BY department, name");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Filter: Only show resigned employees if they resigned in current month or later
        if ($row['status'] === 'Resign' && !empty($row['date_of_exit'])) {
            $resign_parts = explode('-', trim($row['date_of_exit']));
            if (count($resign_parts) === 3) {
                $resign_year = intval($resign_parts[0]);
                $resign_month = intval($resign_parts[1]);
                // Only show if resignation is in current month or later
                if ($resign_year < $current_year || ($resign_year === $current_year && $resign_month < $current_month)) {
                    continue; // Skip this resigned employee for past months
                }
            }
        }
        $employees[] = $row;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Attendance Report</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Flatpickr Date Range Picker CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
    <style>
        .employee-card {
            border-left: 4px solid #28a745;
            transition: all 0.3s ease;
        }
        .employee-card:hover {
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
            transform: translateY(-2px);
        }
        .export-btn {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }
        .department-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .no-employees {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        .modal-header {
            background-color: #28a745;
            color: white;
        }
        .export-modal-btn {
            width: 100%;
            background-color: #28a745;
            border: none;
        }
        .export-modal-btn:hover {
            background-color: #218838;
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container mt-4">
    <!-- Back Button -->
    <div class="mb-3">
        <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary btn-sm">
            ← Back to Dashboard
        </a>
    </div>

    <!-- Error/Success Messages -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Section -->
    <div class="row mb-4">
        <div class="col-md-8 mx-auto">
            <div class="card shadow">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-filter"></i> Select Department & Location</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label for="department" class="form-label">Department:</label>
                                    <select name="department" id="department" class="form-control form-control-lg" required>
                                        <option value="">-- Select Department --</option>
                                        <option value="all" <?php echo ($selected_department === 'all') ? 'selected' : ''; ?>>All Departments</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept); ?>" 
                                                <?php echo ($selected_department === $dept) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label for="location" class="form-label">Location:</label>
                                    <select name="location" id="location" class="form-control form-control-lg">
                                        <option value="">-- All Locations --</option>
                                        <?php foreach ($locations as $loc): ?>
                                            <option value="<?php echo htmlspecialchars($loc); ?>" 
                                                <?php echo ($selected_location === $loc) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($loc); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group mb-3">
                                    <label class="form-label"><i class="fas fa-building"></i> Company <small class="text-muted">(Optional)</small></label>
                                    <button type="button" class="btn btn-outline-secondary w-100" data-bs-toggle="modal" data-bs-target="#companyModal">
                                        <span id="selectedCompaniesText">Select Company</span> <i class="fas fa-chevron-down"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Hidden inputs for selected companies -->
                        <div id="selectedCompaniesContainer"></div>
                        
                        <button type="submit" class="btn btn-info w-100 btn-lg">
                            <i class="fas fa-search"></i> Filter Employees
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Employees List Section -->
    <?php if ($selected_department): ?>
        <div class="department-header">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <?php if ($selected_department === 'all' && empty($selected_location)): ?>
                            <h2><i class="fas fa-building"></i> All Departments</h2>
                        <?php elseif ($selected_department === 'all' && !empty($selected_location)): ?>
                            <h2><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selected_location); ?> Location</h2>
                        <?php elseif (!empty($selected_location)): ?>
                            <h2><i class="fas fa-building"></i> <?php echo htmlspecialchars($selected_department); ?> - <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selected_location); ?></h2>
                        <?php else: ?>
                            <h2><i class="fas fa-building"></i> <?php echo htmlspecialchars($selected_department); ?> Department</h2>
                        <?php endif; ?>
                        <p class="mb-0">Total Employees: <strong><?php echo count($employees); ?></strong></p>
                    </div>
                    <?php if ($selected_department === 'all' && empty($selected_location) && count($employees) > 0): ?>
                        <form method="POST" action="export_all_departments.php" style="display: inline;">
                            <input type="hidden" name="month" value="<?php echo date('m'); ?>">
                            <input type="hidden" name="year" value="<?php echo date('Y'); ?>">
                            <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#exportAllModal">
                                <i class="fas fa-download"></i> Export All Departments
                            </button>
                        </form>
                    <?php elseif (!empty($selected_location) && count($employees) > 0): ?>
                        <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#exportLocationModal">
                            <i class="fas fa-download"></i> Export Location
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="container">
            <?php if (count($employees) > 0): ?>
                <?php if ($selected_department === 'all'): ?>
                    <!-- Group by department when viewing all departments -->
                    <?php 
                        $grouped_employees = array();
                        foreach ($employees as $emp) {
                            $dept = $emp['department'];
                            if (!isset($grouped_employees[$dept])) {
                                $grouped_employees[$dept] = array();
                            }
                            $grouped_employees[$dept][] = $emp;
                        }
                    ?>
                    
                    <?php foreach ($grouped_employees as $dept_name => $dept_employees): ?>
                        <div class="mt-5">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;">
                                <h4 class="mb-0"><i class="fas fa-building"></i> <?php echo htmlspecialchars($dept_name); ?> Department (<?php echo count($dept_employees); ?> employees)</h4>
                            </div>
                            <div class="row">
                                <?php foreach ($dept_employees as $employee): ?>
                                    <div class="col-md-6 col-lg-4 mb-4">
                                        <div class="card employee-card shadow-sm">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <h5 class="card-title mb-1">
                                                            <i class="fas fa-user-circle text-success"></i> <?php echo htmlspecialchars($employee['name']); ?>
                                                        </h5>
                                                        <p class="card-text text-muted mb-0">
                                                            <small>ID: <?php echo htmlspecialchars($employee['employee_id']); ?></small>
                                                        </p>
                                                        <p class="card-text text-muted">
                                                            <small><?php echo htmlspecialchars($employee['department']); ?></small>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-footer bg-light">
                                                <button type="button" class="btn btn-success export-btn w-100" data-bs-toggle="modal" 
                                                        data-bs-target="#exportModal<?php echo $employee['id']; ?>">
                                                    <i class="fas fa-download"></i> Export Excel
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Export Modal for Each Employee -->
                                        <div class="modal fade" id="exportModal<?php echo $employee['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            Export Report - <?php echo htmlspecialchars($employee['name']); ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="export_employee_excel.php">
                                                        <div class="modal-body">
                                                            <input type="hidden" name="employee_id" value="<?php echo $employee['id']; ?>">
                                                            <input type="hidden" name="employee_name" value="<?php echo htmlspecialchars($employee['name']); ?>">
                                                            
                                                            <div class="form-group mb-3">
                                                                <label for="month<?php echo $employee['id']; ?>" class="form-label">Month:</label>
                                                                <select name="month" id="month<?php echo $employee['id']; ?>" class="form-control" required>
                                                                    <option value="">Select Month</option>
                                                                    <option value="1" <?php echo (date('m') == '01') ? 'selected' : ''; ?>>January</option>
                                                                    <option value="2" <?php echo (date('m') == '02') ? 'selected' : ''; ?>>February</option>
                                                                    <option value="3" <?php echo (date('m') == '03') ? 'selected' : ''; ?>>March</option>
                                                                    <option value="4" <?php echo (date('m') == '04') ? 'selected' : ''; ?>>April</option>
                                                                    <option value="5" <?php echo (date('m') == '05') ? 'selected' : ''; ?>>May</option>
                                                                    <option value="6" <?php echo (date('m') == '06') ? 'selected' : ''; ?>>June</option>
                                                                    <option value="7" <?php echo (date('m') == '07') ? 'selected' : ''; ?>>July</option>
                                                                    <option value="8" <?php echo (date('m') == '08') ? 'selected' : ''; ?>>August</option>
                                                                    <option value="9" <?php echo (date('m') == '09') ? 'selected' : ''; ?>>September</option>
                                                                    <option value="10" <?php echo (date('m') == '10') ? 'selected' : ''; ?>>October</option>
                                                                    <option value="11" <?php echo (date('m') == '11') ? 'selected' : ''; ?>>November</option>
                                                                    <option value="12" <?php echo (date('m') == '12') ? 'selected' : ''; ?>>December</option>
                                                                </select>
                                                            </div>

                                                            <div class="form-group mb-3">
                                                                <label for="year<?php echo $employee['id']; ?>" class="form-label">Year:</label>
                                                                <input type="number" name="year" id="year<?php echo $employee['id']; ?>" 
                                                                       class="form-control" value="<?php echo date('Y'); ?>" required>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn export-modal-btn">
                                                                <i class="fas fa-file-excel"></i> Download Excel
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <!-- Show single department employees in grid -->
                    <div class="row">
                        <?php foreach ($employees as $employee): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card employee-card shadow-sm">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h5 class="card-title mb-1">
                                                <i class="fas fa-user-circle text-success"></i> <?php echo htmlspecialchars($employee['name']); ?>
                                            </h5>
                                            <p class="card-text text-muted mb-0">
                                                <small>ID: <?php echo htmlspecialchars($employee['employee_id']); ?></small>
                                            </p>
                                            <p class="card-text text-muted">
                                                <small><?php echo htmlspecialchars($employee['department']); ?></small>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-light">
                                    <button type="button" class="btn btn-success export-btn w-100" data-bs-toggle="modal" 
                                            data-bs-target="#exportModal<?php echo $employee['id']; ?>">
                                        <i class="fas fa-download"></i> Export Excel
                                    </button>
                                </div>
                            </div>

                            <!-- Export Modal for Each Employee -->
                            <div class="modal fade" id="exportModal<?php echo $employee['id']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                Export Report - <?php echo htmlspecialchars($employee['name']); ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="export_employee_excel.php">
                                            <div class="modal-body">
                                                <input type="hidden" name="employee_id" value="<?php echo $employee['id']; ?>">
                                                <input type="hidden" name="employee_name" value="<?php echo htmlspecialchars($employee['name']); ?>">
                                                
                                                <div class="form-group mb-3">
                                                    <label for="month<?php echo $employee['id']; ?>" class="form-label">Month:</label>
                                                    <select name="month" id="month<?php echo $employee['id']; ?>" class="form-control" required>
                                                        <option value="">Select Month</option>
                                                        <option value="1" <?php echo (date('m') == '01') ? 'selected' : ''; ?>>January</option>
                                                        <option value="2" <?php echo (date('m') == '02') ? 'selected' : ''; ?>>February</option>
                                                        <option value="3" <?php echo (date('m') == '03') ? 'selected' : ''; ?>>March</option>
                                                        <option value="4" <?php echo (date('m') == '04') ? 'selected' : ''; ?>>April</option>
                                                        <option value="5" <?php echo (date('m') == '05') ? 'selected' : ''; ?>>May</option>
                                                        <option value="6" <?php echo (date('m') == '06') ? 'selected' : ''; ?>>June</option>
                                                        <option value="7" <?php echo (date('m') == '07') ? 'selected' : ''; ?>>July</option>
                                                        <option value="8" <?php echo (date('m') == '08') ? 'selected' : ''; ?>>August</option>
                                                        <option value="9" <?php echo (date('m') == '09') ? 'selected' : ''; ?>>September</option>
                                                        <option value="10" <?php echo (date('m') == '10') ? 'selected' : ''; ?>>October</option>
                                                        <option value="11" <?php echo (date('m') == '11') ? 'selected' : ''; ?>>November</option>
                                                        <option value="12" <?php echo (date('m') == '12') ? 'selected' : ''; ?>>December</option>
                                                    </select>
                                                </div>

                                                <div class="form-group mb-3">
                                                    <label for="year<?php echo $employee['id']; ?>" class="form-label">Year:</label>
                                                    <input type="number" name="year" id="year<?php echo $employee['id']; ?>" 
                                                           class="form-control" value="<?php echo date('Y'); ?>" required>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn export-modal-btn">
                                                    <i class="fas fa-file-excel"></i> Download Excel
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-employees">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="mt-3">No employees found in this department</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Export All Departments Modal -->
<div class="modal fade" id="exportAllModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #28a745; color: white;">
                <h5 class="modal-title">Export All Departments Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="exportAllDepartmentsForm" method="POST" action="export_all_departments.php">
                <div class="modal-body">
                    <!-- Hidden fields to pass filter selections -->
                    <input type="hidden" name="department" id="exportAllDepartment" value="">
                    <input type="hidden" name="location" id="exportAllLocation" value="">
                    <div id="exportAllCompaniesContainer"></div>
                    
                    <div class="form-group mb-3">
                        <label for="exportAllDateRange" class="form-label">
                            <i class="fas fa-calendar"></i> Select Date Range:
                        </label>
                        <input type="text" id="exportAllDateRange" name="dateRange" 
                               class="form-control date-range-picker" placeholder="From Date - To Date" required>
                        <small class="text-muted">Click to open calendar and select dates</small>
                        <input type="hidden" name="from_date" id="exportAllFromDate">
                        <input type="hidden" name="to_date" id="exportAllToDate">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #28a745; color: white;">
                        <i class="fas fa-file-excel"></i> Download Excel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Export Location Modal -->
<div class="modal fade" id="exportLocationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #17a2b8; color: white;">
                <h5 class="modal-title">Export Location Report</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="export_location_excel.php">
                <div class="modal-body">
                    <input type="hidden" name="location" value="<?php echo htmlspecialchars($selected_location); ?>">
                    
                    <div class="form-group mb-3">
                        <label for="exportLocationMonth" class="form-label">Month:</label>
                        <select name="month" id="exportLocationMonth" class="form-control" required>
                            <option value="">Select Month</option>
                            <option value="1" <?php echo (date('m') == '01') ? 'selected' : ''; ?>>January</option>
                            <option value="2" <?php echo (date('m') == '02') ? 'selected' : ''; ?>>February</option>
                            <option value="3" <?php echo (date('m') == '03') ? 'selected' : ''; ?>>March</option>
                            <option value="4" <?php echo (date('m') == '04') ? 'selected' : ''; ?>>April</option>
                            <option value="5" <?php echo (date('m') == '05') ? 'selected' : ''; ?>>May</option>
                            <option value="6" <?php echo (date('m') == '06') ? 'selected' : ''; ?>>June</option>
                            <option value="7" <?php echo (date('m') == '07') ? 'selected' : ''; ?>>July</option>
                            <option value="8" <?php echo (date('m') == '08') ? 'selected' : ''; ?>>August</option>
                            <option value="9" <?php echo (date('m') == '09') ? 'selected' : ''; ?>>September</option>
                            <option value="10" <?php echo (date('m') == '10') ? 'selected' : ''; ?>>October</option>
                            <option value="11" <?php echo (date('m') == '11') ? 'selected' : ''; ?>>November</option>
                            <option value="12" <?php echo (date('m') == '12') ? 'selected' : ''; ?>>December</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label for="exportLocationYear" class="form-label">Year:</label>
                        <input type="number" name="year" id="exportLocationYear" class="form-control" value="<?php echo date('Y'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background-color: #17a2b8; color: white;">
                        <i class="fas fa-file-excel"></i> Download Excel
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Company Selection Modal -->
<div class="modal fade" id="companyModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #0d6efd; color: white;">
                <h5 class="modal-title">Select Company</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="margin-bottom: 15px; display: flex; gap: 10px;">
                    <button type="button" class="btn btn-sm btn-success" id="selectAllBtn" style="flex: 1;">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button type="button" class="btn btn-sm btn-warning" id="clearAllBtn" style="flex: 1;">
                        <i class="fas fa-times-circle"></i> Clear
                    </button>
                </div>
                <div id="companiesListContainer" style="max-height: 400px; overflow-y: auto;">
                    <div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="applyCompaniesBtn">Apply Selection</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Flatpickr Date Range Picker JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>

<!-- Date Picker Initialization Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Helper function to format dates
    function formatDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    // Initialize date range pickers for all modals
    const dateRangePickers = document.querySelectorAll('.date-range-picker');
    
    dateRangePickers.forEach(picker => {
        const employeeId = picker.getAttribute('id').replace('dateRange', '').replace('exportAllDateRange', 'exportAll');
        flatpickr(picker, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            minDate: new Date(2020, 0, 1),
            maxDate: new Date(),
            defaultDate: [
                new Date().toISOString().split('T')[0],
                new Date().toISOString().split('T')[0]
            ],
            locale: {
                rangeSeparator: ' → '
            },
            onChange: function(selectedDates, dateStr, instance) {
                if (selectedDates.length === 2) {
                    const fromDate = selectedDates[0];
                    const toDate = selectedDates[1];
                    
                    // Determine which ID pattern to use
                    let fromDateId, toDateId;
                    if (picker.getAttribute('id') === 'exportAllDateRange') {
                        fromDateId = 'exportAllFromDate';
                        toDateId = 'exportAllToDate';
                    } else {
                        fromDateId = 'fromDate' + employeeId;
                        toDateId = 'toDate' + employeeId;
                    }
                    
                    // Update hidden inputs with the date values
                    const fromDateInput = document.getElementById(fromDateId);
                    const toDateInput = document.getElementById(toDateId);
                    
                    if (fromDateInput && toDateInput) {
                        fromDateInput.value = formatDate(fromDate);
                        toDateInput.value = formatDate(toDate);
                    }
                }
            }
        });
    });
});
</script>

<!-- Company Filter Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const locationSelect = document.getElementById('location');
    const companiesListContainer = document.getElementById('companiesListContainer');
    const selectedCompaniesContainer = document.getElementById('selectedCompaniesContainer');
    const selectedCompaniesText = document.getElementById('selectedCompaniesText');
    const applyCompaniesBtn = document.getElementById('applyCompaniesBtn');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const clearAllBtn = document.getElementById('clearAllBtn');

    // Load companies when location changes
    locationSelect.addEventListener('change', loadCompanies);
    
    // Load on page load
    window.addEventListener('load', loadCompanies);

    // Select All button
    selectAllBtn.addEventListener('click', function() {
        document.querySelectorAll('.company-checkbox').forEach(cb => cb.checked = true);
    });

    // Clear All button
    clearAllBtn.addEventListener('click', function() {
        document.querySelectorAll('.company-checkbox').forEach(cb => cb.checked = false);
    });

    function loadCompanies() {
        const location = locationSelect.value;
        companiesListContainer.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>';

        // Fetch companies for selected location
        fetch('get_companies_for_location.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'location=' + encodeURIComponent(location || '')
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.companies && data.companies.length > 0) {
                let html = '';
                data.companies.forEach(company => {
                    const safeId = 'comp_' + company.replace(/[^a-zA-Z0-9]/g, '_');
                    html += `
                        <div style="margin-bottom: 15px; padding: 12px; border: 1px solid #ddd; border-radius: 6px; background-color: #f9f9f9; display: flex; align-items: center;">
                            <input class="company-checkbox" type="checkbox" name="company" 
                                   value="${company}" id="${safeId}" style="width: 20px; height: 20px; cursor: pointer; margin-right: 12px; flex-shrink: 0;">
                            <label for="${safeId}" style="cursor: pointer; margin-bottom: 0; flex-grow: 1; font-size: 15px;">
                                ${company}
                            </label>
                        </div>
                    `;
                });
                companiesListContainer.innerHTML = html;
            } else if (data.success) {
                companiesListContainer.innerHTML = '<div class="alert alert-info py-2 mb-0">No companies found for selected location.</div>';
            } else {
                companiesListContainer.innerHTML = '<div class="alert alert-danger py-2 mb-0">Error loading companies.</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            companiesListContainer.innerHTML = '<div class="alert alert-danger py-2 mb-0">Network error.</div>';
        });
    }

    // Apply selected companies
    applyCompaniesBtn.addEventListener('click', function() {
        const checkedCompanies = document.querySelectorAll('.company-checkbox:checked');
        const selectedCompanies = Array.from(checkedCompanies).map(cb => cb.value);

        // Update display text
        if (selectedCompanies.length === 0) {
            selectedCompaniesText.textContent = 'Select Company';
        } else if (selectedCompanies.length === 1) {
            selectedCompaniesText.textContent = selectedCompanies[0];
        } else {
            selectedCompaniesText.textContent = selectedCompanies.length + ' Companies Selected';
        }

        // Create hidden inputs for form submission
        selectedCompaniesContainer.innerHTML = '';
        selectedCompanies.forEach(company => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'companies[]';
            input.value = company;
            selectedCompaniesContainer.appendChild(input);
        });

        // Close modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('companyModal'));
        modal.hide();
    });
});
</script>

<!-- Export All Departments Form Handler Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Get the export form
    const exportForm = document.getElementById('exportAllDepartmentsForm');
    
    if (!exportForm) {
        console.error('Export form not found!');
        return;
    }
    
    // Add submit listener to form
    exportForm.addEventListener('submit', function(e) {
        // Get the companies container inside this form
        const companiesContainer = document.getElementById('exportAllCompaniesContainer');
        
        if (!companiesContainer) {
            console.error('Companies container not found in form!');
            return;
        }
        
        // Populate department and location hidden fields from the filter selects
        const deptSelect = document.getElementById('department');
        const locSelect = document.getElementById('location');
        const exportAllDept = document.getElementById('exportAllDepartment');
        const exportAllLoc = document.getElementById('exportAllLocation');
        
        if (deptSelect && exportAllDept) {
            exportAllDept.value = deptSelect.value;
            console.log('Set department:', deptSelect.value);
        }
        
        if (locSelect && exportAllLoc) {
            exportAllLoc.value = locSelect.value;
            console.log('Set location:', locSelect.value);
        }
        
        // Clear previous company inputs
        companiesContainer.innerHTML = '';
        
        // Get all CHECKED company checkboxes from the modal (this is the source of truth)
        const checkedCompanies = document.querySelectorAll('.company-checkbox:checked');
        
        console.log('Found checked companies:', checkedCompanies.length);
        
        // Create new hidden inputs for each checked company inside the export form
        checkedCompanies.forEach(checkbox => {
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'companies[]';
            hiddenInput.value = checkbox.value;
            companiesContainer.appendChild(hiddenInput);
            console.log('Added company to export:', checkbox.value);
        });
        
        // Log message if no companies selected
        if (checkedCompanies.length === 0) {
            console.log('No specific companies selected - export will include all employees');
        }
    });
});
</script>

</body>
</html>