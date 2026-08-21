<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is admin or face_operator
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'face_operator'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Get date filter from URL or use today
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get all attendance records for selected date (including all punch in/out events)
$query = "SELECT 
    a.id,
    a.user_id,
    u.name,
    u.employee_id,
    a.punch_in,
    a.punch_out,
    a.punch_in_location,
    a.punch_out_location,
    CASE 
        WHEN a.punch_out IS NOT NULL THEN 'Present'
        ELSE 'Clocked In'
    END as status
FROM attendance a
JOIN users u ON a.user_id = u.id
WHERE DATE(a.punch_in) = ?
ORDER BY a.punch_in DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param('s', $selected_date);
$stmt->execute();
$result = $stmt->get_result();
$records = $result->fetch_all(MYSQLI_ASSOC);

// Get employee list for filter
$emp_query = "SELECT id, name FROM users WHERE role = 'employee' ORDER BY name";
$emp_result = $conn->query($emp_query);
$employees = $emp_result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Attendance Records</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            padding: 20px 0;
        }
        
        .container-custom {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 30px;
            margin-top: 20px;
        }
        
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 15px;
        }
        
        .header-section h2 {
            margin: 0;
            color: #333;
            font-weight: 600;
        }
        
        .filter-section {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-section input,
        .filter-section select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .filter-section button {
            padding: 8px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .filter-section button:hover {
            background-color: #0056b3;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        thead {
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .name-cell {
            font-weight: 500;
            color: #333;
        }
        
        .id-cell {
            color: #6c757d;
            font-size: 13px;
            font-weight: 500;
        }
        
        .time-cell {
            color: #495057;
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-present {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-clocked {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-card.present {
            background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
            color: #333;
        }
        
        .stat-card.clocked {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            color: #333;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 12px;
            text-transform: uppercase;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .export-button {
            background-color: #28a745;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            margin-left: 10px;
        }
        
        .export-button:hover {
            background-color: #218838;
        }
        
        .no-data {
            color: #999;
            font-style: italic;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="container-custom">
            <!-- Header -->
            <div class="header-section">
                <h2><i class="fas fa-clock"></i> Daily Attendance Records</h2>
                <div>
                    <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                    <label for="date" style="margin: 0; font-weight: 500;">Date:</label>
                    <input type="date" id="date" name="date" value="<?php echo $selected_date; ?>" required style="max-width: 200px;">
                    <button type="submit" style="padding: 8px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="simple_attendance.php" style="padding: 8px 15px; background-color: #6c757d; color: white; border-radius: 4px; text-decoration: none;">
                        <i class="fas fa-redo"></i> Today
                    </a>
                </form>
            </div>

            <!-- Statistics -->
            <?php
            $total_records = count($records);
            $present_count = count(array_filter($records, function($r) { return $r['status'] == 'Present'; }));
            $clocked_count = count(array_filter($records, function($r) { return $r['status'] == 'Clocked In'; }));
            ?>
            <div class="stats-section">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_records; ?></div>
                    <div class="stat-label">Total Records</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-number"><?php echo $present_count; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card clocked">
                    <div class="stat-number"><?php echo $clocked_count; ?></div>
                    <div class="stat-label">Clocked In</div>
                </div>
            </div>

            <!-- Table -->
            <?php if (count($records) > 0): ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 30%;">Name</th>
                            <th style="width: 15%;">Employee ID</th>
                            <th style="width: 20%;">Punch In</th>
                            <th style="width: 20%;">Punch Out</th>
                            <th style="width: 15%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                        <tr>
                            <td class="name-cell">
                                <i class="fas fa-user-circle" style="margin-right: 8px; color: #007bff;"></i>
                                <?php echo htmlspecialchars($record['name']); ?>
                            </td>
                            <td class="id-cell">#<?php echo str_pad($record['employee_id'], 3, '0', STR_PAD_LEFT); ?></td>
                            <td class="time-cell">
                                <i class="fas fa-sign-in-alt" style="color: #28a745; margin-right: 5px;"></i>
                                <?php echo date('H:i:s', strtotime($record['punch_in'])); ?>
                            </td>
                            <td class="time-cell">
                                <?php if ($record['punch_out']): ?>
                                    <i class="fas fa-sign-out-alt" style="color: #dc3545; margin-right: 5px;"></i>
                                    <?php echo date('H:i:s', strtotime($record['punch_out'])); ?>
                                <?php else: ?>
                                    <span class="no-data">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $record['status'] == 'Present' ? 'status-present' : 'status-clocked'; ?>">
                                    <?php echo $record['status']; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h5>No Records Found</h5>
                <p>No attendance records for <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
            </div>
            <?php endif; ?>

            <!-- Export Section -->
            <?php if (count($records) > 0): ?>
            <div style="margin-top: 25px; text-align: right;">
                <a href="all_punch_records.php?date=<?php echo $selected_date; ?>" class="export-button" style="background-color: #6c757d;">
                    <i class="fas fa-list"></i> Detailed Punch View
                </a>
                <a href="export_attendance.php?date=<?php echo $selected_date; ?>&format=csv" class="export-button">
                    <i class="fas fa-download"></i> Export as CSV
                </a>
                <a href="export_attendance.php?date=<?php echo $selected_date; ?>&format=json" class="export-button" style="background-color: #17a2b8;">
                    <i class="fas fa-download"></i> Export as JSON
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
