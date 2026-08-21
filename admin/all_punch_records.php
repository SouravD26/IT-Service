<?php
session_start();
require_once '../config/db.php';

// Check if user is logged in and is admin or face_operator
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'face_operator'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Get date filter
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_employee = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : null;

// Base query
$base_query = "SELECT 
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
    END as status,
    CASE
        WHEN a.punch_out IS NOT NULL THEN 
            CONCAT(
                FLOOR(TIMESTAMPDIFF(MINUTE, a.punch_in, a.punch_out) / 60), 'h ',
                MOD(TIMESTAMPDIFF(MINUTE, a.punch_in, a.punch_out), 60), 'm'
            )
        ELSE 'In Progress'
    END as duration
FROM attendance a
JOIN users u ON a.user_id = u.id
WHERE DATE(a.punch_in) = ?";

$params = [$selected_date];
$param_types = 's';

if ($selected_employee) {
    $base_query .= " AND a.user_id = ?";
    $params[] = $selected_employee;
    $param_types .= 'i';
}

$base_query .= " ORDER BY a.punch_in DESC";

$stmt = $conn->prepare($base_query);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$records = $result->fetch_all(MYSQLI_ASSOC);

// Get employee list for filter
$emp_query = "SELECT DISTINCT a.user_id, u.name, u.employee_id FROM attendance a JOIN users u ON a.user_id = u.id WHERE DATE(a.punch_in) = ? ORDER BY u.name";
$emp_stmt = $conn->prepare($emp_query);
$emp_stmt->bind_param('s', $selected_date);
$emp_stmt->execute();
$emp_result = $emp_stmt->get_result();
$employees = $emp_result->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$total_records = count($records);
$present_count = count(array_filter($records, function($r) { return $r['status'] == 'Present'; }));
$clocked_count = count(array_filter($records, function($r) { return $r['status'] == 'Clocked In'; }));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Punch Records</title>
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
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
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
        
        .stats-grid {
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
        
        .punch-card {
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            align-items: center;
        }
        
        .punch-card.in {
            border-left-color: #28a745;
            background: #f0fff4;
        }
        
        .punch-card.out {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        
        .punch-type-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        .punch-type-badge.in {
            background: #28a745;
            color: white;
        }
        
        .punch-type-badge.out {
            background: #dc3545;
            color: white;
        }
        
        .punch-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .punch-info-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .punch-info-value {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            font-family: 'Courier New', monospace;
        }
        
        .employee-name {
            font-weight: 600;
            color: #333;
            font-size: 16px;
        }
        
        .employee-id {
            font-size: 12px;
            color: #6c757d;
        }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-present {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-clocked {
            background-color: #fff3cd;
            color: #856404;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="container-custom">
            <!-- Header -->
            <div class="header-section">
                <h2><i class="fas fa-calendar-check"></i> All Punch Records</h2>
                <div>
                    <a href="simple_attendance.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center w-100">
                    <label for="date" style="margin: 0; font-weight: 500; white-space: nowrap;">Date:</label>
                    <input type="date" id="date" name="date" value="<?php echo $selected_date; ?>" required style="max-width: 200px;">
                    
                    <label for="employee_id" style="margin: 0; font-weight: 500; white-space: nowrap;">Employee:</label>
                    <select id="employee_id" name="employee_id" style="max-width: 250px;">
                        <option value="">All Employees</option>
                        <?php foreach($employees as $emp): ?>
                        <option value="<?php echo $emp['user_id']; ?>" <?php echo $selected_employee == $emp['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['name']); ?> (#<?php echo str_pad($emp['employee_id'], 3, '0', STR_PAD_LEFT); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button type="submit" style="padding: 8px 20px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;margin-left: auto;">
                        <i class="fas fa-search"></i> Filter
                    </button>
                </form>
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_records; ?></div>
                    <div class="stat-label">Total Punches</div>
                </div>
                <div class="stat-card present">
                    <div class="stat-number"><?php echo $present_count; ?></div>
                    <div class="stat-label">Completed (In/Out)</div>
                </div>
                <div class="stat-card clocked">
                    <div class="stat-number"><?php echo $clocked_count; ?></div>
                    <div class="stat-label">Currently Clocked In</div>
                </div>
            </div>

            <!-- Punch Records -->
            <?php if (count($records) > 0): ?>
                <h5 style="margin-top: 25px; margin-bottom: 20px;"><i class="fas fa-list"></i> Punch Details</h5>
                <?php foreach ($records as $record): 
                    $isPunchIn = !isset($record['punch_out']) || is_null($record['punch_out']) ? false : true;
                    $punchType = (is_null($record['punch_out'])) ? 'in' : 'out';
                    $punchLabel = (is_null($record['punch_out'])) ? 'PUNCH IN' : 'PUNCH IN/OUT';
                    if (!is_null($record['punch_out']) && !is_null($record['punch_in'])) {
                        $punchLabel = 'COMPLETE RECORD';
                    }
                ?>
                <div class="punch-card <?php echo $punchType; ?>">
                    <div>
                        <span class="punch-type-badge <?php echo $punchType; ?>">
                            <i class="fas <?php echo is_null($record['punch_out']) ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?>"></i>
                            <?php echo is_null($record['punch_out']) ? 'IN' : ((is_null($record['punch_in'])) ? 'OUT' : 'PAIR'); ?>
                        </span>
                        <div style="margin-top: 10px;">
                            <div class="employee-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($record['name']); ?></div>
                            <div class="employee-id">#<?php echo str_pad($record['employee_id'], 3, '0', STR_PAD_LEFT); ?> | Record ID: <?php echo $record['id']; ?></div>
                        </div>
                    </div>
                    
                    <div class="punch-info">
                        <div class="punch-info-label"><i class="fas fa-sign-in-alt" style="color: #28a745;"></i> Punch In</div>
                        <div class="punch-info-value"><?php echo $record['punch_in'] ? date('H:i:s', strtotime($record['punch_in'])) : '—'; ?></div>
                        <?php if($record['punch_in_location']): ?><small style="color: #6c757d;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($record['punch_in_location']); ?></small><?php endif; ?>
                    </div>
                    
                    <div class="punch-info">
                        <div class="punch-info-label"><i class="fas fa-sign-out-alt" style="color: #dc3545;"></i> Punch Out</div>
                        <div class="punch-info-value"><?php echo $record['punch_out'] ? date('H:i:s', strtotime($record['punch_out'])) : '—'; ?></div>
                        <?php if($record['punch_out_location']): ?><small style="color: #6c757d;"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($record['punch_out_location']); ?></small><?php endif; ?>
                    </div>
                    
                    <div class="punch-info">
                        <div class="punch-info-label"><i class="fas fa-hourglass-half"></i> Duration</div>
                        <div class="punch-info-value" style="color: #007bff;"><?php echo htmlspecialchars($record['duration']); ?></div>
                        <span class="status-badge <?php echo $record['status'] == 'Present' ? 'status-present' : 'status-clocked'; ?>">
                            <?php echo $record['status']; ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h5>No Records Found</h5>
                    <p>No punch records for <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
