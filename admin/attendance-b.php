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

// Get filter values
$filter_dept = isset($_GET['dept']) ? htmlspecialchars($_GET['dept']) : '';
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : 2026;
$filter_day = isset($_GET['day']) ? (int)$_GET['day'] : 0;
$filter_name = isset($_GET['name']) ? htmlspecialchars($_GET['name']) : '';

// Fetch all departments
$dept_stmt = $conn->prepare("SELECT DISTINCT u.department FROM users u WHERE u.status = 'Working' AND u.department IS NOT NULL AND u.department != '' ORDER BY u.department");
$dept_stmt->execute();
$dept_result = $dept_stmt->get_result();
$departments = [];
while ($dept_row = $dept_result->fetch_assoc()) {
    $departments[] = $dept_row['department'];
}
$dept_stmt->close();

// Build SQL query with filters - SELECT only needed columns
$sql = "SELECT a.id, a.date, a.punch_in, a.punch_out, a.punch_in_location, a.punch_out_location, a.status, a.selfie_punchin, a.selfie_punchout, u.name, u.department FROM attendance a JOIN users u ON a.user_id = u.id WHERE u.status = 'Working' AND 1=1";
$params = [];
$types = "";

// Apply department filter
if (!empty($filter_dept)) {
    $sql .= " AND u.department = ?";
    $params[] = $filter_dept;
    $types .= "s";
}

// Apply name filter
if (!empty($filter_name)) {
    $sql .= " AND u.name LIKE ?";
    $params[] = "%" . $filter_name . "%";
    $types .= "s";
}

// Apply month and year filter
$sql .= " AND MONTH(a.date) = ? AND YEAR(a.date) = ?";
$params[] = $filter_month;
$params[] = $filter_year;
$types .= "ii";

// Apply day filter if selected
if (!empty($filter_day)) {
    $sql .= " AND DAY(a.date) = ?";
    $params[] = $filter_day;
    $types .= "i";
}

$sql .= " ORDER BY a.date DESC";

// Execute query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Records</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        .selfie-thumbnail {
            max-width: 70px;
            max-height: 70px;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s;
            border: 2px solid #ddd;
        }
        .selfie-thumbnail:hover {
            transform: scale(1.08);
            border-color: #007bff;
        }
        .selfie-modal-img {
            max-width: 100%;
            max-height: 600px;
            border-radius: 8px;
            margin-top: 20px;
        }
        .no-selfie {
            color: #999;
            font-size: 11px;
            text-align: center;
        }
        .badge-selfie {
            font-size: 10px;
            padding: 2px 5px;
        }
        .table-sm td {
            padding: 0.5rem;
            font-size: 13px;
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container-fluid mt-5">
    <h3 class="mb-4">📊 Attendance Records with Selfie Verification</h3>
    
    <!-- Filter Card -->
    <div class="card mb-4 shadow">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">🔍 Filter Attendance</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Department</label>
                    <select name="dept" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filter_dept === $dept ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Employee Name</label>
                    <input type="text" name="name" class="form-control" placeholder="Search by name" value="<?php echo htmlspecialchars($filter_name); ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Month</label>
                    <select name="month" id="monthFilter" class="form-control" onchange="updateDays()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $filter_month === $m ? 'selected' : ''; ?>>
                                <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Year</label>
                    <select name="year" id="yearFilter" class="form-control" onchange="updateDays()">
                        <?php 
                        $current_year = 2026;
                        for ($y = $current_year - 5; $y <= $current_year; $y++): 
                        ?>
                            <option value="<?php echo $y; ?>" <?php echo $filter_year === $y ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Day</label>
                    <select name="day" id="dayFilter" class="form-control">
                        <option value="">All Days</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">🔎 Filter</button>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <a href="attendance.php" class="btn btn-secondary w-100">↺ Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Attendance Table -->
    <div class="card shadow">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">📋 Records</h5>
        </div>
        <div class="card-body">
            <?php if ($result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead class="table-dark">
                            <tr>
                                <th>Employee Name</th>
                                <th>Department</th>
                                <th>Date</th>
                                <th>Punch In</th>
                                <th>Punch In Selfie</th>
                                <th>Punch In Location</th>
                                <th>Punch Out</th>
                                <th>Punch Out Selfie</th>
                                <th>Punch Out Location</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['department'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($row['date']); ?></td>
                                    <td><?php echo htmlspecialchars($row['punch_in'] ?? '-'); ?></td>
                                    
                                    <!-- Punch In Selfie -->
                                    <td>
                                        <?php if (!empty($row['selfie_punchin'])): ?>
                                            <img 
                                                src="../uploads/selfies/<?php echo str_replace('-', '/', htmlspecialchars($row['date'])); ?>/<?php echo htmlspecialchars($row['selfie_punchin']); ?>" 
                                                alt="Punch In Selfie" 
                                                class="selfie-thumbnail"
                                                onclick="viewSelfie('<?php echo htmlspecialchars($row['selfie_punchin']); ?>', 'Punch In', '<?php echo htmlspecialchars($row['name']); ?>', '<?php echo htmlspecialchars($row['date']); ?>')"
                                                onerror="this.src='../uploads/selfies/<?php echo htmlspecialchars($row['selfie_punchin']); ?>'"
                                                title="Click to view punch-in selfie"
                                            >
                                            <br>
                                            <span class="badge badge-selfie bg-success">✓</span>
                                        <?php else: ?>
                                            <div class="no-selfie">
                                                <span class="badge badge-selfie bg-danger">✗</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Punch In Location -->
                                    <td><small><?php echo htmlspecialchars($row['punch_in_location'] ?? '-'); ?></small></td>
                                    
                                    <td><?php echo htmlspecialchars($row['punch_out'] ?? '-'); ?></td>
                                    
                                    <!-- Punch Out Selfie -->
                                    <td>
                                        <?php if (!empty($row['selfie_punchout'])): ?>
                                            <img 
                                                src="../uploads/selfies/<?php echo str_replace('-', '/', htmlspecialchars($row['date'])); ?>/<?php echo htmlspecialchars($row['selfie_punchout']); ?>" 
                                                alt="Punch Out Selfie" 
                                                class="selfie-thumbnail"
                                                onclick="viewSelfie('<?php echo htmlspecialchars($row['selfie_punchout']); ?>', 'Punch Out', '<?php echo htmlspecialchars($row['name']); ?>', '<?php echo htmlspecialchars($row['date']); ?>')"
                                                onerror="this.src='../uploads/selfies/<?php echo htmlspecialchars($row['selfie_punchout']); ?>'"
                                                title="Click to view punch-out selfie"
                                            >
                                            <br>
                                            <span class="badge badge-selfie bg-success">✓</span>
                                        <?php else: ?>
                                            <div class="no-selfie">
                                                <span class="badge badge-selfie bg-danger">✗</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- Punch Out Location -->
                                    <td><small><?php echo htmlspecialchars($row['punch_out_location'] ?? '-'); ?></small></td>
                                    
                                    <td>
                                        <?php 
                                        $status = $row['status'] ?? 'Absent';
                                        $badge_class = match($status) {
                                            'Present' => 'bg-success',
                                            'Late' => 'bg-warning',
                                            'Leave' => 'bg-info',
                                            'Absent' => 'bg-danger',
                                            default => 'bg-secondary'
                                        };
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info" role="alert">
                    ℹ️ No attendance records found for the selected filters.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Statistics -->
    <?php if ($result->num_rows > 0): ?>
        <div class="row mt-4">
            <?php
            // Rewind result for statistics
            $stmt->execute();
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
            }
            $result = $stmt->get_result();
            
            $total_records = 0;
            $with_punch_in_selfie = 0;
            $with_punch_out_selfie = 0;
            $missing_selfies = 0;
            
            while ($row = $result->fetch_assoc()) {
                $total_records++;
                if (!empty($row['selfie_punchin'])) {
                    $with_punch_in_selfie++;
                }
                if (!empty($row['selfie_punchout'])) {
                    $with_punch_out_selfie++;
                }
                if (empty($row['selfie_punchin']) || empty($row['selfie_punchout'])) {
                    $missing_selfies++;
                }
            }
            
            $punch_in_percentage = $total_records > 0 ? round(100 * $with_punch_in_selfie / $total_records, 2) : 0;
            $punch_out_percentage = $total_records > 0 ? round(100 * $with_punch_out_selfie / $total_records, 2) : 0;
            ?>
            
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h5>Total Records</h5>
                        <h2><?php echo $total_records; ?></h2>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h5>Punch-In Selfies</h5>
                        <h2><?php echo $with_punch_in_selfie; ?></h2>
                        <small><?php echo $punch_in_percentage; ?>%</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h5>Punch-Out Selfies</h5>
                        <h2><?php echo $with_punch_out_selfie; ?></h2>
                        <small><?php echo $punch_out_percentage; ?>%</small>
                    </div>
                </div>
            </div>
            
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center">
                        <h5>Missing Selfies</h5>
                        <h2><?php echo $missing_selfies; ?></h2>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Selfie Modal -->
<div class="modal fade" id="selfieModal" tabindex="-1" aria-labelledby="selfieModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="selfieModalLabel">📷 Selfie View</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p id="selfieInfo" class="text-muted"></p>
                <img id="selfieImage" src="" alt="Selfie" class="selfie-modal-img">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    function viewSelfie(filename, type, empName, date) {
        // Set modal content
        document.getElementById('selfieInfo').innerText = `${empName} - ${type} - ${date}`;
        const img = document.getElementById('selfieImage');
        // Convert date from YYYY-MM-DD to YYYY/MM/DD for folder path
        const datePath = date.replace(/-/g, '/');
        img.src = `../uploads/selfies/${datePath}/${filename}`;
        
        // Handle image loading errors (fallback for old pictures)
        img.onerror = function() {
            // Try loading from old flat folder structure
            this.src = `../uploads/selfies/${filename}`;
            this.onerror = null; // Remove handler to prevent infinite loop
        };
        
        // Show modal
        const modal = new bootstrap.Modal(document.getElementById('selfieModal'));
        modal.show();
    }

    // Update days dropdown based on selected month and year
    function updateDays() {
        const monthSelect = document.getElementById('monthFilter');
        const yearSelect = document.getElementById('yearFilter');
        const daySelect = document.getElementById('dayFilter');
        
        const month = parseInt(monthSelect.value);
        const year = parseInt(yearSelect.value);
        
        // Get number of days in selected month
        const daysInMonth = new Date(year, month, 0).getDate();
        
        // Save currently selected day (if any)
        const currentDay = daySelect.value;
        
        // Clear day options except "All Days"
        daySelect.innerHTML = '<option value="">All Days</option>';
        
        // Populate days from 1 to daysInMonth
        for (let day = 1; day <= daysInMonth; day++) {
            const option = document.createElement('option');
            option.value = day;
            option.textContent = day;
            option.selected = (currentDay == day);
            daySelect.appendChild(option);
        }
    }

    // Initialize days on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateDays();
        // Restore selected day if it exists
        const currentDay = '<?php echo $filter_day; ?>';
        if (currentDay) {
            document.getElementById('dayFilter').value = currentDay;
        }
    });
</script>

</body>
</html>

<?php
$stmt->close();
?>