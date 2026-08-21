<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

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

// Pagination setup
$items_per_page = isset($_GET['items_per_page']) ? intval($_GET['items_per_page']) : 50;
// Validate items_per_page - only allow 50, 100, 150, 200
$allowed_per_page = [50, 100, 150, 200];
if (!in_array($items_per_page, $allowed_per_page)) {
    $items_per_page = 50;
}
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;
$total_employees = 0;

// Fetch employees for selected department and location
if ($selected_department === 'all' && !empty($selected_location)) {
    // Fetch all employees by location
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND location = ? AND company IN ($placeholders)");
        $types = 's' . str_repeat('s', count($selected_companies));
        $stmt_count->bind_param($types, $selected_location, ...$selected_companies);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND location = ? AND company IN ($placeholders) ORDER BY department, name LIMIT ? OFFSET ?");
        $types = 's' . str_repeat('s', count($selected_companies)) . 'ii';
        $params = array_merge([$selected_location], $selected_companies, [$items_per_page, $offset]);
        $stmt->bind_param($types, ...$params);
    } else {
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND location = ?");
        $stmt_count->bind_param("s", $selected_location);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND location = ? ORDER BY department, name LIMIT ? OFFSET ?");
        $stmt->bind_param("sii", $selected_location, $items_per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
} elseif ($selected_department === 'all' && empty($selected_location)) {
    // Fetch all employees grouped by department
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders)");
        $stmt_count->bind_param(str_repeat('s', count($selected_companies)), ...$selected_companies);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders) ORDER BY department, name LIMIT ? OFFSET ?");
        $types = str_repeat('s', count($selected_companies)) . 'ii';
        $params = array_merge($selected_companies, [$items_per_page, $offset]);
        $stmt->bind_param($types, ...$params);
    } else {
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign')");
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE role = 'employee' AND (status = 'Working' OR status = 'Resign') ORDER BY department, name LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $items_per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
} elseif ($selected_department && $selected_department !== '' && !empty($selected_location)) {
    // Fetch employees for selected department and location
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE department = ? AND location = ? AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders)");
        $types = 'ss' . str_repeat('s', count($selected_companies));
        $stmt_count->bind_param($types, $selected_department, $selected_location, ...$selected_companies);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND location = ? AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders) ORDER BY name LIMIT ? OFFSET ?");
        $types = 'ss' . str_repeat('s', count($selected_companies)) . 'ii';
        $params = array_merge([$selected_department, $selected_location], $selected_companies, [$items_per_page, $offset]);
        $stmt->bind_param($types, ...$params);
    } else {
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE department = ? AND location = ? AND (status = 'Working' OR status = 'Resign')");
        $stmt_count->bind_param("ss", $selected_department, $selected_location);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND location = ? AND (status = 'Working' OR status = 'Resign') ORDER BY name LIMIT ? OFFSET ?");
        $stmt->bind_param("ssii", $selected_department, $selected_location, $items_per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
} elseif ($selected_department && $selected_department !== '') {
    // Fetch employees for selected department
    if (count($selected_companies) > 0) {
        $placeholders = implode(',', array_fill(0, count($selected_companies), '?'));
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE department = ? AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders)");
        $types = 's' . str_repeat('s', count($selected_companies));
        $stmt_count->bind_param($types, $selected_department, ...$selected_companies);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND (status = 'Working' OR status = 'Resign') AND company IN ($placeholders) ORDER BY name LIMIT ? OFFSET ?");
        $types = 's' . str_repeat('s', count($selected_companies)) . 'ii';
        $params = array_merge([$selected_department], $selected_companies, [$items_per_page, $offset]);
        $stmt->bind_param($types, ...$params);
    } else {
        // Get total count
        $stmt_count = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE department = ? AND (status = 'Working' OR status = 'Resign')");
        $stmt_count->bind_param("s", $selected_department);
        $stmt_count->execute();
        $count_result = $stmt_count->get_result();
        $total_employees = $count_result->fetch_assoc()['total'];
        $stmt_count->close();
        
        // Get paginated results
        $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, status, date_of_exit FROM users WHERE department = ? AND (status = 'Working' OR status = 'Resign') ORDER BY name LIMIT ? OFFSET ?");
        $stmt->bind_param("sii", $selected_department, $items_per_page, $offset);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
}

$total_pages = ceil($total_employees / $items_per_page);

// Helper function to get initials and avatar color
function getInitials($name) {
    $parts = explode(' ', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[count($parts) - 1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

function getAvatarColor($name, $id) {
    $colors = ['avatar-1', 'avatar-2', 'avatar-3', 'avatar-4', 'avatar-5', 'avatar-6', 'avatar-7', 'avatar-8'];
    return $colors[($id % 8)];
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
        /* Collapsible Department Section */
        .dept-section {
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .dept-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 1.5rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            transition: all 0.3s ease;
        }

        .dept-header:hover {
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        }

        .dept-header-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .dept-count {
            background: rgba(255, 255, 255, 0.3);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .dept-toggle {
            transition: transform 0.3s ease;
            font-size: 1.25rem;
        }

        .dept-toggle.collapsed {
            transform: rotate(-90deg);
        }

        .dept-employees {
            max-height: 2000px;
            transition: max-height 0.3s ease;
            overflow: hidden;
        }

        .dept-employees.collapsed {
            max-height: 0;
            padding: 0;
        }

        /* Employee Row */
        .employee-row {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e0e0e0;
            background: white;
            transition: all 0.3s ease;
        }

        .employee-row:last-child {
            border-bottom: none;
        }

        .employee-row:hover {
            background: #f0f4ff;
        }

        /* Avatar */
        .employee-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
            margin-right: 1.5rem;
            flex-shrink: 0;
        }

        /* Employee Info Grid */
        .employee-info {
            display: grid;
            grid-template-columns: auto 1fr auto auto;
            gap: 2rem;
            align-items: center;
            flex: 1;
        }

        .employee-id-section {
            min-width: 100px;
        }

        .employee-id-label {
            font-size: 0.75rem;
            color: #666;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .employee-id {
            font-size: 0.95rem;
            font-weight: 600;
            color: #667eea;
        }

        .employee-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .employee-dept-badge {
            display: inline-block;
            background: #e8f0fe;
            color: #667eea;
            padding: 0.35rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .export-btn-small {
            padding: 0.5rem 1rem;
            border: 1px solid #28a745;
            color: #28a745;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
        }

        .export-btn-small:hover {
            background: #28a745;
            color: white;
        }

        .no-employees {
            text-align: center;
            padding: 2rem;
            color: #999;
        }

        /* Avatar Colors - Generating based on initials */
        .avatar-1 { background: #6366f1; }
        .avatar-2 { background: #ec4899; }
        .avatar-3 { background: #f59e0b; }
        .avatar-4 { background: #10b981; }
        .avatar-5 { background: #3b82f6; }
        .avatar-6 { background: #8b5cf6; }
        .avatar-7 { background: #06b6d4; }
        .avatar-8 { background: #f87171; }

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
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                        <div style="flex: 1;">
                            <?php if ($selected_department === 'all' && empty($selected_location)): ?>
                                <h2><i class="fas fa-building"></i> All Departments</h2>
                            <?php elseif ($selected_department === 'all' && !empty($selected_location)): ?>
                                <h2><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selected_location); ?> Location</h2>
                            <?php elseif (!empty($selected_location)): ?>
                                <h2><i class="fas fa-building"></i> <?php echo htmlspecialchars($selected_department); ?> - <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($selected_location); ?></h2>
                            <?php else: ?>
                                <h2><i class="fas fa-building"></i> <?php echo htmlspecialchars($selected_department); ?> Department</h2>
                            <?php endif; ?>
                            <p class="mb-0">Total Employees: <strong><?php echo $total_employees; ?></strong> | Showing: <strong><?php echo count($employees); ?></strong></p>
                        </div>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem; margin-left: 2rem; padding-top: 0.5rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem; white-space: nowrap;">
                                <label for="itemsPerPage" style="font-weight: 600; margin-bottom: 0; font-size: 0.9rem;">Show per page:</label>
                                <select id="itemsPerPage" class="form-control form-control-sm" style="width: 85px; padding: 0.35rem 0.5rem;" onchange="changeItemsPerPage(this.value)">
                                    <option value="50" <?php echo ($items_per_page == 50) ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo ($items_per_page == 100) ? 'selected' : ''; ?>>100</option>
                                    <option value="150" <?php echo ($items_per_page == 150) ? 'selected' : ''; ?>>150</option>
                                    <option value="200" <?php echo ($items_per_page == 200) ? 'selected' : ''; ?>>200</option>
                                </select>
                            </div>
                        </div>
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

        <div class="container mt-5">
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
                    <div class="dept-section">
                        <!-- Department Header - Collapsible -->
                        <div class="dept-header" onclick="toggleDept(this)">
                            <div class="dept-header-title">
                                <i class="fas fa-users"></i>
                                <span><?php echo htmlspecialchars($dept_name); ?> Department</span>
                                <span class="dept-count"><?php echo count($dept_employees); ?> employees</span>
                            </div>
                            <i class="fas fa-chevron-down dept-toggle"></i>
                        </div>

                        <!-- Department Employees -->
                        <div class="dept-employees">
                            <?php foreach ($dept_employees as $employee): ?>
                                <div class="employee-row">
                                    <!-- Avatar -->
                                    <div class="employee-avatar <?php echo getAvatarColor($employee['name'], $employee['id']); ?>">
                                        <?php echo getInitials($employee['name']); ?>
                                    </div>

                                    <!-- Employee Info -->
                                    <div class="employee-info">
                                        <div class="employee-id-section">
                                            <div class="employee-id-label">Employee ID</div>
                                            <div class="employee-id">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></div>
                                        </div>
                                        <div>
                                            <div class="employee-name"><?php echo htmlspecialchars($employee['name']); ?></div>
                                        </div>
                                        <div>
                                            <span class="employee-dept-badge"><?php echo htmlspecialchars($dept_name); ?></span>
                                        </div>
                                    </div>

                                    <!-- Export Button -->
                                    <button type="button" class="export-btn-small" data-bs-toggle="modal" 
                                            data-bs-target="#exportModal<?php echo $employee['id']; ?>">
                                        <i class="fas fa-download"></i> Export
                                    </button>
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
                                                        <label for="dateRangeEmployee<?php echo $employee['id']; ?>" class="form-label">
                                                            <i class="fas fa-calendar"></i> Select Date Range:
                                                        </label>
                                                        <input type="text" id="dateRangeEmployee<?php echo $employee['id']; ?>" name="dateRange" 
                                                               class="form-control date-range-picker" placeholder="From Date - To Date" required>
                                                        <small class="text-muted">Click to open calendar and select start and end dates</small>
                                                        <input type="hidden" name="from_date" id="fromDateEmployee<?php echo $employee['id']; ?>">
                                                        <input type="hidden" name="to_date" id="toDateEmployee<?php echo $employee['id']; ?>">
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
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <!-- Pagination Controls for All Departments -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-5">
                        <ul class="pagination justify-content-center">
                            <?php if ($current_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?department=all&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=1">First</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?department=all&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $current_page - 1; ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php 
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php echo ($i === $current_page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?department=all&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?department=all&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $current_page + 1; ?>">Next</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?department=all&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $total_pages; ?>">Last</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <div class="text-center text-muted mb-5">
                        Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> 
                        (Showing <?php echo count($employees); ?> of <?php echo $total_employees; ?> employees)
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Show single department employees in table layout -->
                    <div class="dept-section">
                        <div class="dept-header">
                            <div class="dept-header-title">
                                <i class="fas fa-users"></i>
                                <span><?php echo htmlspecialchars($selected_department); ?> Department</span>
                                <span class="dept-count"><?php echo count($employees); ?> employees</span>
                            </div>
                        </div>

                        <div class="dept-employees">
                            <?php foreach ($employees as $employee): ?>
                                <div class="employee-row">
                                    <!-- Avatar -->
                                    <div class="employee-avatar <?php echo getAvatarColor($employee['name'], $employee['id']); ?>">
                                        <?php echo getInitials($employee['name']); ?>
                                    </div>

                                    <!-- Employee Info -->
                                    <div class="employee-info">
                                        <div class="employee-id-section">
                                            <div class="employee-id-label">Employee ID</div>
                                            <div class="employee-id">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></div>
                                        </div>
                                        <div>
                                            <div class="employee-name"><?php echo htmlspecialchars($employee['name']); ?></div>
                                        </div>
                                        <div>
                                            <span class="employee-dept-badge"><?php echo htmlspecialchars($selected_department); ?></span>
                                        </div>
                                    </div>

                                    <!-- Export Button -->
                                    <button type="button" class="export-btn-small" data-bs-toggle="modal" 
                                            data-bs-target="#exportModal<?php echo $employee['id']; ?>">
                                        <i class="fas fa-download"></i> Export
                                    </button>
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
                                                        <label for="dateRangeEmployee<?php echo $employee['id']; ?>" class="form-label">
                                                            <i class="fas fa-calendar"></i> Select Date Range:
                                                        </label>
                                                        <input type="text" id="dateRangeEmployee<?php echo $employee['id']; ?>" name="dateRange" 
                                                               class="form-control date-range-picker" placeholder="From Date - To Date" required>
                                                        <small class="text-muted">Click to open calendar and select start and end dates</small>
                                                        <input type="hidden" name="from_date" id="fromDateEmployee<?php echo $employee['id']; ?>">
                                                        <input type="hidden" name="to_date" id="toDateEmployee<?php echo $employee['id']; ?>">
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
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Pagination Controls for Single Department -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-5">
                        <ul class="pagination justify-content-center">
                            <?php if ($current_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?department=<?php echo urlencode($selected_department); ?>&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=1">First</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?department=<?php echo urlencode($selected_department); ?>&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $current_page - 1; ?>">Previous</a>
                                </li>
                            <?php endif; ?>
                            
                            <?php 
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php echo ($i === $current_page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?department=<?php echo urlencode($selected_department); ?>&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?department=<?php echo urlencode($selected_department); ?>&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $current_page + 1; ?>">Next</a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?department=<?php echo urlencode($selected_department); ?>&location=<?php echo urlencode($selected_location); ?>&items_per_page=<?php echo $items_per_page; ?>&page=<?php echo $total_pages; ?>">Last</a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <div class="text-center text-muted mb-5">
                        Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> 
                        (Showing <?php echo count($employees); ?> of <?php echo $total_employees; ?> employees)
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-employees">
                    <i class="fas fa-inbox" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="mt-3">No employees found in this selection</p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
                    <input type="hidden" name="department" value="<?php echo htmlspecialchars($selected_department); ?>">
                    
                    <div class="form-group mb-3">
                        <label for="exportLocationDateRange" class="form-label">
                            <i class="fas fa-calendar"></i> Select Date Range:
                        </label>
                        <input type="text" id="exportLocationDateRange" name="dateRange" 
                               class="form-control date-range-picker" placeholder="From Date - To Date" required>
                        <small class="text-muted">Click to open calendar and select start and end dates</small>
                        <input type="hidden" name="from_date" id="exportLocationFromDate">
                        <input type="hidden" name="to_date" id="exportLocationToDate">
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
        const pickerId = picker.getAttribute('id');
        
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
                    
                    // Determine which ID pattern this picker follows
                    let fromDateId, toDateId;
                    
                    if (pickerId === 'exportAllDateRange') {
                        // All departments export
                        fromDateId = 'exportAllFromDate';
                        toDateId = 'exportAllToDate';
                    } else if (pickerId === 'exportLocationDateRange') {
                        // Location export
                        fromDateId = 'exportLocationFromDate';
                        toDateId = 'exportLocationToDate';
                    } else if (pickerId.startsWith('dateRangeEmployee')) {
                        // Individual employee export
                        const employeeId = pickerId.replace('dateRangeEmployee', '');
                        fromDateId = 'fromDateEmployee' + employeeId;
                        toDateId = 'toDateEmployee' + employeeId;
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

<!-- Collapsible Departments Script -->
<script>
function toggleDept(headerElement) {
    const employeesDiv = headerElement.nextElementSibling;
    const toggleIcon = headerElement.querySelector('.dept-toggle');
    
    employeesDiv.classList.toggle('collapsed');
    toggleIcon.classList.toggle('collapsed');
}

// Change items per page and reload
function changeItemsPerPage(value) {
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    
    // Update items_per_page
    urlParams.set('items_per_page', value);
    
    // Reset to page 1 when changing items per page
    urlParams.set('page', '1');
    
    // Redirect to new URL
    window.location.href = '?' + urlParams.toString();
}
</script>

</body>
</html>
