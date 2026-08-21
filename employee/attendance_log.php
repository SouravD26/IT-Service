<?php
session_start();
include('../config/db.php');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

// Get employee ID from URL or session
$employee_id = isset($_GET['employee_id']) ? intval($_GET['employee_id']) : $_SESSION['user_id'];
$date = isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d');

// Get employee info
$stmt = $conn->prepare("SELECT id, name, employee_id FROM users WHERE id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

if (!$employee) {
    die("Employee not found");
}

// Get attendance records for the day
$stmt = $conn->prepare("SELECT id, punch_in, punch_out, punch_in_location, punch_out_location FROM attendance WHERE user_id = ? AND date = ? ORDER BY punch_in ASC");
$stmt->bind_param("is", $employee_id, $date);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

// Calculate statistics
$total_records = count($records);
$total_hours = 0;
$punch_pairs = [];

foreach ($records as $record) {
    if ($record['punch_in'] && $record['punch_out']) {
        $in_time = strtotime($record['punch_in']);
        $out_time = strtotime($record['punch_out']);
        $duration = ($out_time - $in_time) / 3600; // in hours
        $total_hours += $duration;
        
        $punch_pairs[] = [
            'punch_in' => $record['punch_in'],
            'punch_out' => $record['punch_out'],
            'location_in' => $record['punch_in_location'],
            'location_out' => $record['punch_out_location'],
            'duration' => $duration
        ];
    } elseif ($record['punch_in'] && !$record['punch_out']) {
        $punch_pairs[] = [
            'punch_in' => $record['punch_in'],
            'punch_out' => null,
            'location_in' => $record['punch_in_location'],
            'location_out' => null,
            'duration' => null
        ];
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Records - <?php echo htmlspecialchars($employee['name']); ?></title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            border: none;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-item {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-item .label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .stat-item .value {
            font-size: 24px;
            font-weight: bold;
        }
        .punch-pair {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin: 15px 0;
            transition: all 0.3s ease;
        }
        .punch-pair:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #667eea;
        }
        .punch-in {
            border-left: 5px solid #28a745;
        }
        .punch-out {
            border-left: 5px solid #dc3545;
        }
        .time-display {
            font-size: 20px;
            font-weight: bold;
            color: #333;
        }
        .duration-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
            margin-top: 10px;
        }
        .location-tag {
            background: #f0f0f0;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-block;
            margin-top: 5px;
        }
        .no-punch-out {
            background: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            color: #856404;
            margin-top: 10px;
        }
        .timeline {
            position: relative;
            padding-left: 50px;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(to bottom, #28a745, #dc3545);
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 800px;">
        <!-- Header Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="mb-0"><i class="fas fa-user-check"></i> Attendance Records</h2>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h5><i class="fas fa-user"></i> Employee</h5>
                        <p class="fs-5"><strong><?php echo htmlspecialchars($employee['name']); ?></strong></p>
                        <small class="text-muted">ID: <?php echo htmlspecialchars($employee['employee_id']); ?></small>
                    </div>
                    <div class="col-md-6">
                        <h5><i class="fas fa-calendar"></i> Date</h5>
                        <p class="fs-5"><strong><?php echo date('d M Y', strtotime($date)); ?></strong></p>
                        <form method="get" style="margin-top: 10px;">
                            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                            <input type="date" name="date" value="<?php echo $date; ?>" class="form-control form-control-sm">
                            <button type="submit" class="btn btn-sm btn-primary mt-2">View</button>
                        </form>
                    </div>
                </div>

                <!-- Statistics -->
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="label">Total Records</div>
                        <div class="value"><?php echo $total_records; ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Punch Pairs</div>
                        <div class="value"><?php echo count($punch_pairs); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Total Hours</div>
                        <div class="value"><?php echo number_format($total_hours, 2); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Status</div>
                        <div class="value" style="font-size: 14px;">
                            <?php echo $total_records > 0 ? 'Present' : 'Absent'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Attendance Details -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Punch In/Out Log</h5>
            </div>
            <div class="card-body">
                <?php if (count($punch_pairs) > 0): ?>
                    <div class="timeline">
                        <?php foreach ($punch_pairs as $index => $pair): ?>
                            <div class="punch-pair punch-in">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div style="font-weight: bold; color: #28a745;">
                                            <i class="fas fa-sign-in-alt"></i> PUNCH IN
                                        </div>
                                        <div class="time-display" style="color: #28a745;">
                                            <?php echo date('H:i:s', strtotime($pair['punch_in'])); ?>
                                        </div>
                                        <?php if ($pair['location_in']): ?>
                                            <span class="location-tag">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($pair['location_in']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <?php if ($pair['punch_out']): ?>
                                            <div style="font-weight: bold; color: #dc3545;">
                                                <i class="fas fa-sign-out-alt"></i> PUNCH OUT
                                            </div>
                                            <div class="time-display" style="color: #dc3545;">
                                                <?php echo date('H:i:s', strtotime($pair['punch_out'])); ?>
                                            </div>
                                            <?php if ($pair['location_out']): ?>
                                                <span class="location-tag">
                                                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($pair['location_out']); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($pair['duration'] !== null): ?>
                                                <div class="duration-badge" style="margin-top: 10px;">
                                                    <i class="fas fa-hourglass-half"></i> <?php echo number_format($pair['duration'], 2); ?> hrs
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div class="no-punch-out">
                                                <i class="fas fa-exclamation-circle"></i> No Punch Out Yet
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning text-center">
                        <i class="fas fa-info-circle"></i> No attendance records found for this date
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Back Button -->
        <div class="text-center mt-4">
            <a href="javascript:history.back()" class="btn btn-secondary btn-lg">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>
</body>
</html>
