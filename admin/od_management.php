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

// Create OD table if doesn't exist with better error handling
$message = "";
$message_type = "";

$result = $conn->query("SHOW TABLES LIKE 'od_records'");
if ($result->num_rows === 0) {
    // Table doesn't exist, create it
    $create_table = "CREATE TABLE IF NOT EXISTS od_records (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        od_date DATE NOT NULL,
        marked_by INT NOT NULL,
        marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_od (user_id, od_date)
    )";
    
    if (!$conn->query($create_table)) {
        $message = "Error creating od_records table: " . htmlspecialchars($conn->error);
        $message_type = "danger";
    }
}

$admin_id = $_SESSION['user_id'];

// Handle Mark OD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'mark_od') {
        $user_id = intval($_POST['user_id']);
        $dates_input = isset($_POST['od_dates']) ? trim($_POST['od_dates']) : '';
        
        $od_dates = [];
        if (!empty($dates_input)) {
            // Parse comma-separated or newline-separated dates
            $dates_input = str_replace(['\r\n', '\r', '\n'], ',', $dates_input);
            $od_dates = array_filter(array_map('trim', explode(',', $dates_input)));
        }
        
        if (empty($od_dates)) {
            $message = "✗ Please select at least one date";
            $message_type = "danger";
        } else {
            $success_count = 0;
            $duplicate_count = 0;
            
            foreach ($od_dates as $date) {
                // Validate date format YYYY-MM-DD
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    continue;
                }
                
                $stmt_check = $conn->prepare("SELECT id FROM od_records WHERE user_id = ? AND od_date = ?");
                if ($stmt_check === false) {
                    $message = "✗ Database error: " . htmlspecialchars($conn->error);
                    $message_type = "danger";
                    break;
                }
                $stmt_check->bind_param("is", $user_id, $date);
                $stmt_check->execute();
                
                if ($stmt_check->get_result()->num_rows > 0) {
                    $duplicate_count++;
                } else {
                    $stmt_insert = $conn->prepare("INSERT INTO od_records (user_id, od_date, marked_by) VALUES (?, ?, ?)");
                    if ($stmt_insert === false) {
                        $message = "✗ Database error: " . htmlspecialchars($conn->error);
                        $message_type = "danger";
                        break;
                    }
                    $stmt_insert->bind_param("isi", $user_id, $date, $admin_id);
                    if ($stmt_insert->execute()) {
                        $success_count++;
                    }
                    $stmt_insert->close();
                }
                $stmt_check->close();
            }
            
            if ($message === "") {
                $message = "✓ OD marked: " . $success_count . " date(s)";
                if ($duplicate_count > 0) {
                    $message .= " (" . $duplicate_count . " already marked)";
                }
                $message_type = "success";
            }
        }
    } elseif ($_POST['action'] === 'remove_od') {
        $od_id = intval($_POST['od_id']);
        $stmt = $conn->prepare("DELETE FROM od_records WHERE id = ?");
        if ($stmt === false) {
            $message = "✗ Database error: " . htmlspecialchars($conn->error);
            $message_type = "danger";
        } else {
            $stmt->bind_param("i", $od_id);
            if ($stmt->execute()) {
                $message = "✓ OD record removed";
                $message_type = "success";
            } else {
                $message = "✗ Error removing OD record";
                $message_type = "danger";
            }
            $stmt->close();
        }
    }
}

// Build filter query
$filter_query = "WHERE u.status = 'Working'";
$params = [];
$types = "";

if (!empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $filter_query .= " AND (u.name LIKE ? OR u.employee_id LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $types .= "ss";
}

if (!empty($_GET['department'])) {
    $dept = $_GET['department'];
    $filter_query .= " AND u.department = ?";
    $params[] = $dept;
    $types .= "s";
}

if (!empty($_GET['location'])) {
    $loc = $_GET['location'];
    $filter_query .= " AND u.company = ?";
    $params[] = $loc;
    $types .= "s";
}

// Fetch employees based on filters
$sql = "SELECT u.id, u.name, u.employee_id, u.department, u.company FROM users u $filter_query ORDER BY u.name";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch all departments
$dept_stmt = $conn->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' ORDER BY department");
$departments = $dept_stmt->fetch_all(MYSQLI_ASSOC);

// Fetch all locations
$loc_stmt = $conn->query("SELECT DISTINCT company FROM users WHERE company IS NOT NULL AND company != '' ORDER BY company");
$locations = $loc_stmt->fetch_all(MYSQLI_ASSOC);

// Fetch OD records for employees
$od_map = [];
if (!empty($employees)) {
    $emp_ids = array_column($employees, 'id');
    $placeholders = implode(',', array_fill(0, count($emp_ids), '?'));
    $od_sql = "SELECT id, user_id, od_date FROM od_records WHERE user_id IN ($placeholders) ORDER BY od_date DESC";
    $od_stmt = $conn->prepare($od_sql);
    if ($od_stmt) {
        $types = str_repeat('i', count($emp_ids));
        $od_stmt->bind_param($types, ...$emp_ids);
        $od_stmt->execute();
        $od_records = $od_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $od_stmt->close();
    } else {
        $od_records = [];
    }
    
    foreach ($od_records as $record) {
        if (!isset($od_map[$record['user_id']])) {
            $od_map[$record['user_id']] = [];
        }
        $od_map[$record['user_id']][$record['id']] = $record['od_date'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OD Management</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/litepicker@latest/dist/litepicker.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .navbar-custom {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .navbar-logo {
            height: 50px;
            z-index: 10;
            flex-grow: 1;
            text-align: center;
        }
        .navbar-welcome {
            display: flex;
            align-items: center;
            height: 100%;
            color: white;
            min-width: 120px;
        }
        .navbar-logout {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-right: 20px;
            min-width: fit-content;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .employee-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fff;
            transition: box-shadow 0.3s;
        }
        .employee-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .employee-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 15px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-label {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
        }
        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #212529;
        }
        .calendar-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        .od-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 6px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            margin-right: 5px;
            margin-bottom: 5px;
            position: relative;
        }
        .od-badge .remove-btn {
            margin-left: 8px;
            cursor: pointer;
            font-weight: bold;
        }
        .od-list {
            max-height: 150px;
            overflow-y: auto;
        }
        .date-picker {
            font-size: 1rem !important;
            padding: 0.5rem !important;
            border: 1px solid #ced4da !important;
            background-color: #fff !important;
            cursor: pointer !important;
        }
        .date-picker:focus {
            border-color: #86b7fe !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
            outline: 0 !important;
        }
        /* Litepicker custom styles */
        .litepicker {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
        }
        @media (max-width: 768px) {
            .navbar-custom {
                flex-wrap: wrap;
            }
            .navbar-logo {
                flex-basis: 100%;
                margin-bottom: 10px;
            }
            .navbar-logout {
                flex-basis: 100%;
                justify-content: center;
                padding-right: 0;
                margin-top: 10px;
            }
            .employee-info {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container mt-5 mb-5">
    <h2 class="mb-4 text-center">📍 OD (Out Station Duty) Management</h2>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filters Section -->
    <div class="filter-section">
        <h5 class="mb-3">🔍 Filter Employees</h5>
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" placeholder="Search name or employee ID" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <select name="department" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo ($_GET['department'] ?? '') === $dept['department'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['department']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="location" class="form-select">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo htmlspecialchars($loc['company']); ?>" <?php echo ($_GET['location'] ?? '') === $loc['company'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($loc['company']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </form>
    </div>

    <!-- Results Section -->
    <div class="results-section">
        <?php if (empty($employees)): ?>
            <div class="alert alert-info">No employees found matching your filters.</div>
        <?php else: ?>
            <h5 class="mb-3">📊 Results (<?php echo count($employees); ?> employee<?php echo count($employees) !== 1 ? 's' : ''; ?>)</h5>
            <?php foreach ($employees as $emp): ?>
                <div class="employee-card">
                    <div class="employee-info">
                        <div class="info-item">
                            <span class="info-label">👤 Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp['name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">🏢 Employee Code</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp['employee_id']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">🏭 Department</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp['department']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">📍 Location</span>
                            <span class="info-value"><?php echo htmlspecialchars($emp['company']); ?></span>
                        </div>
                    </div>

                    <!-- OD Calendar Section -->
                    <form method="POST" class="od-form">
                        <input type="hidden" name="action" value="mark_od">
                        <input type="hidden" name="user_id" value="<?php echo $emp['id']; ?>">

                        <div class="calendar-section">
                            <label class="form-label">📅 <strong>Select Dates for OD</strong></label>
                            <p class="text-muted small">Click calendar to select single or multiple dates</p>
                            <div class="row g-2 align-items-center">
                                <div class="col-md-9">
                                    <input type="text" class="form-control date-picker" placeholder="Click to select dates" data-emp-id="<?php echo $emp['id']; ?>" readonly style="cursor: pointer; background-color: #fff;">
                                    <input type="hidden" name="od_dates" class="hidden-od-dates">
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-success btn-sm w-100">
                                        <i class="fas fa-check-circle"></i> Mark as OD
                                    </button>
                                </div>
                            </div>
                            <div id="selected-dates-<?php echo $emp['id']; ?>" class="mt-2 text-muted" style="font-size: 0.9rem;"></div>
                        </div>

                        <!-- Display Already Marked OD Dates -->
                        <?php if (!empty($od_map[$emp['id']])): ?>
                            <div style="margin-bottom: 15px;">
                                <label class="form-label">✓ <strong>Already Marked OD Dates</strong>:</label>
                                <div class="od-list">
                                    <?php foreach ($od_map[$emp['id']] as $od_id => $date): ?>
                                        <span class="od-badge">
                                            <?php echo date('d-M-Y', strtotime($date)); ?>
                                            <span class="remove-btn" onclick="removeOD(<?php echo $od_id; ?>)">×</span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Remove OD Form (Hidden) -->
<form id="removeODForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="remove_od">
    <input type="hidden" name="od_id" id="removeODId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/litepicker@latest/dist/litepicker.js"></script>

<script>
    // Initialize Litepicker for each employee
    document.querySelectorAll('.date-picker').forEach(function(input) {
        const empId = input.getAttribute('data-emp-id');
        const form = input.closest('form');
        const hiddenInput = form.querySelector('.hidden-od-dates');
        const displayDiv = document.getElementById('selected-dates-' + empId);
        
        console.log('Initializing Litepicker for emp:', empId);
        
        let selectedDates = [];
        
        function updateDatesDisplay() {
            console.log('updateDatesDisplay called with:', selectedDates);
            if (selectedDates.length > 0) {
                const formatted = selectedDates.map(function(d) {
                    return new Date(d).toLocaleDateString('en-US', {day: '2-digit', month: 'short', year: 'numeric'});
                }).join(', ');
                displayDiv.innerHTML = '<strong>Selected (' + selectedDates.length + '):</strong> ' + formatted;
                hiddenInput.value = selectedDates.join(',');
                console.log('Updated hidden input to:', hiddenInput.value);
            } else {
                displayDiv.innerHTML = '';
                hiddenInput.value = '';
            }
        }
        
        const picker = new Litepicker({
            element: input,
            singleMode: false,
            numberOfMonths: 2,
            format: 'YYYY-MM-DD'
        });
        
        console.log('Litepicker created:', !!picker);
        
        // Poll for input value changes (Litepicker updates the input element)
        let lastValue = '';
        setInterval(function() {
            if (input.value !== lastValue) {
                lastValue = input.value;
                console.log('Input value changed to:', input.value);
                
                // Parse the date range from Litepicker
                if (input.value && input.value.trim()) {
                    // Litepicker format: "YYYY-MM-DD - YYYY-MM-DD" for ranges or just "YYYY-MM-DD" for single
                    try {
                        const parts = input.value.split(' - ');
                        selectedDates = [];
                        
                        if (parts.length === 2) {
                            // Range selection
                            const startDate = new Date(parts[0].trim());
                            const endDate = new Date(parts[1].trim());
                            
                            let current = new Date(startDate);
                            while (current <= endDate) {
                                const year = current.getFullYear();
                                const month = String(current.getMonth() + 1).padStart(2, '0');
                                const day = String(current.getDate()).padStart(2, '0');
                                selectedDates.push(year + '-' + month + '-' + day);
                                current.setDate(current.getDate() + 1);
                            }
                        } else if (parts.length === 1) {
                            // Single date
                            selectedDates.push(parts[0].trim());
                        }
                        
                        updateDatesDisplay();
                    } catch (e) {
                        console.error('Error parsing dates:', e);
                    }
                } else {
                    selectedDates = [];
                    updateDatesDisplay();
                }
            }
        }, 500);
        
        // Also trigger on input click (after picker closes)
        input.addEventListener('blur', function() {
            console.log('Input blur - checking value:', this.value);
            if (this.value && this.value.trim()) {
                try {
                    const parts = this.value.split(' - ');
                    selectedDates = [];
                    
                    if (parts.length === 2) {
                        const startDate = new Date(parts[0].trim());
                        const endDate = new Date(parts[1].trim());
                        
                        let current = new Date(startDate);
                        while (current <= endDate) {
                            const year = current.getFullYear();
                            const month = String(current.getMonth() + 1).padStart(2, '0');
                            const day = String(current.getDate()).padStart(2, '0');
                            selectedDates.push(year + '-' + month + '-' + day);
                            current.setDate(current.getDate() + 1);
                        }
                    } else if (parts.length === 1) {
                        selectedDates.push(parts[0].trim());
                    }
                    
                    updateDatesDisplay();
                } catch (e) {
                    console.error('Error parsing dates on blur:', e);
                }
            }
        });
    });
    
    function removeOD(odId) {
        Swal.fire({
            title: 'Remove OD Record?',
            text: 'Are you sure you want to remove this OD record?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, remove it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('removeODId').value = odId;
                document.getElementById('removeODForm').submit();
                Swal.fire({
                    title: 'Removed!',
                    text: 'OD record has been removed.',
                    icon: 'success',
                    timer: 1500
                });
            }
        });
    }

    // Form validation
    document.querySelectorAll('.od-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const hiddenDates = this.querySelector('.hidden-od-dates');
            console.log('Form submit - hiddenDates value:', hiddenDates.value);
            if (!hiddenDates.value.trim()) {
                e.preventDefault();
                Swal.fire({
                    title: 'Error!',
                    text: 'Please select at least one date',
                    icon: 'error',
                    confirmButtonColor: '#3085d6',
                    confirmButtonText: 'OK'
                });
            }
        });
    });
</script>

</body>
</html>
