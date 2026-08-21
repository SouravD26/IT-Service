<?php
session_start();
include('config/db.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';

// Only face operators and admins can access this dashboard
if (!in_array($user_role, ['face_operator', 'admin', 'suparadmin'])) {
    header("Location: employee/dashboard.php");
    exit();
}

// Get user info
$user_query = $conn->prepare("SELECT id, name, email, role, employee_id FROM users WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_result = $user_query->get_result();
$user_data = $user_result->fetch_assoc();

// Determine if user is admin or face operator
$is_admin = in_array($user_role, ['admin', 'suparadmin']);
$is_operator = $user_role === 'face_operator';

// Get attendance data based on role
$result = null;

if ($is_admin) {
    // Admin: Show all face attendance records from last 7 days
    $query = "
        SELECT 
            a.id, a.user_id, u.name, u.employee_id, a.date, a.punch_in, a.punch_out,
            a.punch_in_location, a.punch_out_location, a.status
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        WHERE a.date >= DATE_SUB(DATE(NOW()), INTERVAL 7 DAY)
        ORDER BY a.date DESC, a.punch_in DESC
        LIMIT 100
    ";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query failed: " . $conn->error);
        $result = $conn->query("SELECT NULL as id WHERE FALSE");
    }
} else if ($is_operator) {
    // Face Operator: Show only today's records from all employees
    $query = "
        SELECT 
            a.id, a.user_id, u.name, u.employee_id, a.date, a.punch_in, a.punch_out,
            a.punch_in_location, a.punch_out_location, a.status
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        WHERE DATE(a.date) = DATE(NOW())
        ORDER BY a.punch_in DESC
        LIMIT 200
    ";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query failed: " . $conn->error);
        $result = $conn->query("SELECT NULL as id WHERE FALSE");
    }
} else {
    // Employee: Show own attendance records (fallback, shouldn't reach here)
    $stmt = $conn->prepare("
        SELECT 
            a.id, a.user_id, u.name, u.employee_id, a.date, a.punch_in, a.punch_out,
            a.punch_in_location, a.punch_out_location, a.status
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        WHERE a.user_id = ?
        ORDER BY a.date DESC, a.punch_in DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
}

// Get today's statistics
$today_stats = ['employees_today' => 0, 'total_punches' => 0];
$today_query = $conn->prepare("
    SELECT 
        COUNT(DISTINCT user_id) as employees_today,
        COUNT(*) as total_punches
    FROM attendance
    WHERE DATE(date) = DATE(NOW())
");

if ($today_query) {
    $today_query->execute();
    $result_stats = $today_query->get_result()->fetch_assoc();
    if ($result_stats) {
        $today_stats = $result_stats;
    }
    $today_query->close();
} else {
    error_log("Query prepare failed: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Face Attendance Dashboard</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .stat-card {
            border-radius: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .stat-card h4 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .stat-card p {
            margin: 5px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .attendance-row {
            border-left: 4px solid #667eea;
            padding-left: 15px;
        }
        .punch-time {
            font-weight: bold;
            color: #667eea;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">
                <i class="fas fa-face-smile"></i> 
                <?php echo $is_operator ? 'Face Attendance Operator' : 'Face Attendance Dashboard'; ?>
            </span>
            <div>
                <span class="text-white me-3">Welcome, <?php echo htmlspecialchars($user_data['name']); ?></span>
                <a href="auth/logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mt-5">
        <!-- Stats Cards -->
        <div class="row">
            <div class="col-md-6">
                <div class="stat-card">
                    <h4><?php echo $today_stats['employees_today'] ?? 0; ?></h4>
                    <p>👥 Employees Checked In Today</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h4><?php echo $today_stats['total_punches'] ?? 0; ?></h4>
                    <p>✅ Total Punches Today</p>
                </div>
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="mb-4">
            <?php if ($is_admin): ?>
                <a href="admin/dashboard.php" class="btn btn-primary">Admin Dashboard</a>
                <a href="admin/employees.php" class="btn btn-info">Manage Employees</a>
            <?php else: ?>
                <!-- Face Operator: Only Face Attendance -->
            <?php endif; ?>
            <a href="auto_face_attendance.php" class="btn btn-success">
                <i class="fas fa-camera"></i> Face Attendance
            </a>
            <a href="auth/logout.php" class="btn btn-danger">Logout</a>
        </div>

        <!-- Attendance Records -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <i class="fas fa-history"></i> 
                    <?php echo $is_admin ? 'Recent Face Attendance Records' : 'Today\'s Face Attendance Records'; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($result && $result->num_rows > 0): ?>
                    <div class="list-group">
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <div class="list-group-item attendance-row">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($row['name']); ?></h6>
                                        <small class="text-muted">ID: <?php echo htmlspecialchars($row['employee_id'] ?? 'N/A'); ?></small>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted">Date:</small><br>
                                        <span><?php echo date('M d, Y', strtotime($row['date'])); ?></span>
                                    </div>
                                    <div class="col-md-3">
                                        <small class="text-muted">Punch In:</small><br>
                                        <span class="punch-time"><?php echo htmlspecialchars($row['punch_in'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted">Punch Out:</small><br>
                                        <span class="punch-time"><?php echo htmlspecialchars($row['punch_out'] ?? '-'); ?></span>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted">Status:</small><br>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($row['status'] ?? 'Present'); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info" role="alert">
                        <i class="fas fa-info-circle"></i> No attendance records found.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>