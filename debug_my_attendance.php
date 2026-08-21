<?php
/**
 * DEBUG: Check Database Records for Employee
 * Shows exact data stored in attendance table
 */

session_start();
include('config/db.php');

// Check if logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Employee';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug - Database Records</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; }
        .card { margin-bottom: 20px; }
        .code { background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; }
    </style>
</head>
<body>

<div class="container">
    <h2 class="mb-4">🔍 Debug: Your Attendance Records in Database</h2>
    
    <div class="alert alert-info">
        <strong>Logged in as:</strong> <?php echo htmlspecialchars($user_name); ?> (ID: <?php echo $user_id; ?>)
    </div>

    <!-- ALL Records -->
    <div class="card">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0">🔴 ALL Raw Database Records (No Grouping)</h5>
        </div>
        <div class="card-body">
            <?php
            
            $sql_all = "SELECT id, user_id, date, punch_in, punch_out, punch_in_location, punch_out_location, status, selfie_punchin, selfie_punchout 
                        FROM attendance 
                        WHERE user_id = ? 
                        ORDER BY date DESC, id DESC";
            
            $stmt_all = $conn->prepare($sql_all);
            $stmt_all->bind_param("i", $user_id);
            $stmt_all->execute();
            $result_all = $stmt_all->get_result();
            
            if ($result_all->num_rows > 0) {
                echo "<div class='table-responsive'>";
                echo "<table class='table table-sm table-striped'>";
                echo "<thead class='table-dark'>";
                echo "<tr>";
                echo "<th>ID</th>";
                echo "<th>Date</th>";
                echo "<th>Punch In</th>";
                echo "<th>Punch In Loc</th>";
                echo "<th>Punch Out</th>";
                echo "<th>Punch Out Loc</th>";
                echo "<th>Status</th>";
                echo "</tr>";
                echo "</thead>";
                echo "<tbody>";
                
                while ($row = $result_all->fetch_assoc()) {
                    echo "<tr>";
                    echo "<td><strong>" . $row['id'] . "</strong></td>";
                    echo "<td>" . $row['date'] . "</td>";
                    echo "<td>" . ($row['punch_in'] ? substr($row['punch_in'], 0, 5) : '-') . "</td>";
                    echo "<td><small>" . htmlspecialchars($row['punch_in_location'] ?? '-') . "</small></td>";
                    echo "<td>" . ($row['punch_out'] ? substr($row['punch_out'], 0, 5) : '-') . "</td>";
                    echo "<td><small>" . htmlspecialchars($row['punch_out_location'] ?? '-') . "</small></td>";
                    echo "<td>" . ($row['status'] ?? '-') . "</td>";
                    echo "</tr>";
                }
                
                echo "</tbody>";
                echo "</table>";
                echo "</div>";
            } else {
                echo "<div class='alert alert-warning'>❌ No records found for you in the database!</div>";
            }
            
            $stmt_all->close();
            
            ?>
        </div>
    </div>

    <!-- Grouped by Date (Like My Attendance Page) -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">🟢 Grouped by Date (First Punch In / Last Punch Out)</h5>
        </div>
        <div class="card-body">
            <?php
            
            $sql_grouped = "SELECT 
                date,
                COUNT(*) as record_count,
                MIN(punch_in) as first_punch_in,
                MAX(punch_out) as last_punch_out,
                GROUP_CONCAT(DISTINCT punch_in_location) as in_locations,
                GROUP_CONCAT(DISTINCT punch_out_location) as out_locations,
                GROUP_CONCAT(DISTINCT status) as statuses
            FROM attendance 
            WHERE user_id = ? 
            GROUP BY date 
            ORDER BY date DESC";
            
            $stmt_grouped = $conn->prepare($sql_grouped);
            $stmt_grouped->bind_param("i", $user_id);
            $stmt_grouped->execute();
            $result_grouped = $stmt_grouped->get_result();
            
            if ($result_grouped->num_rows > 0) {
                echo "<div class='table-responsive'>";
                echo "<table class='table table-sm table-striped'>";
                echo "<thead class='table-dark'>";
                echo "<tr>";
                echo "<th>Date</th>";
                echo "<th>Records</th>";
                echo "<th>First Punch In</th>";
                echo "<th>Last Punch Out</th>";
                echo "<th>In Location(s)</th>";
                echo "<th>Out Location(s)</th>";
                echo "<th>Status</th>";
                echo "</tr>";
                echo "</thead>";
                echo "<tbody>";
                
                while ($row = $result_grouped->fetch_assoc()) {
                    $hours = '-';
                    if ($row['first_punch_in'] && $row['last_punch_out']) {
                        $in_time = strtotime($row['date'] . ' ' . $row['first_punch_in']);
                        $out_time = strtotime($row['date'] . ' ' . $row['last_punch_out']);
                        if ($out_time < $in_time) {
                            $out_time = strtotime(date('Y-m-d', strtotime($row['date'] . ' +1 day')) . ' ' . $row['last_punch_out']);
                        }
                        $diff = ($out_time - $in_time) / 3600;
                        $hours = round($diff, 1) . 'h';
                    }
                    
                    echo "<tr>";
                    echo "<td><strong>" . $row['date'] . "</strong></td>";
                    echo "<td><span class='badge bg-primary'>" . $row['record_count'] . "</span></td>";
                    echo "<td>" . ($row['first_punch_in'] ? substr($row['first_punch_in'], 0, 5) : '-') . "</td>";
                    echo "<td>" . ($row['last_punch_out'] ? substr($row['last_punch_out'], 0, 5) : '-') . "</td>";
                    echo "<td><small>" . htmlspecialchars($row['in_locations'] ?? '-') . "</small></td>";
                    echo "<td><small>" . htmlspecialchars($row['out_locations'] ?? '-') . "</small></td>";
                    echo "<td><small>" . htmlspecialchars($row['statuses'] ?? '-') . "</small></td>";
                    echo "</tr>";
                }
                
                echo "</tbody>";
                echo "</table>";
                echo "</div>";
            } else {
                echo "<div class='alert alert-warning'>❌ No grouped records found!</div>";
            }
            
            $stmt_grouped->close();
            
            ?>
        </div>
    </div>

    <!-- Current Month Filter (What my_attendance.php shows) -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">🔵 Current Month (Month: <?php echo date('m'); ?>, Year: <?php echo date('Y'); ?>)</h5>
        </div>
        <div class="card-body">
            <?php
            
            $current_month = date('m');
            $current_year = date('Y');
            
            $sql_month = "SELECT 
                date,
                COUNT(*) as record_count,
                MIN(punch_in) as first_punch_in,
                MAX(punch_out) as last_punch_out
            FROM attendance 
            WHERE user_id = ? 
            AND MONTH(date) = ? 
            AND YEAR(date) = ?
            GROUP BY date 
            ORDER BY date DESC";
            
            $stmt_month = $conn->prepare($sql_month);
            $stmt_month->bind_param("iii", $user_id, $current_month, $current_year);
            $stmt_month->execute();
            $result_month = $stmt_month->get_result();
            
            if ($result_month->num_rows > 0) {
                echo "<p><strong>Records found: " . $result_month->num_rows . "</strong></p>";
                echo "<div class='table-responsive'>";
                echo "<table class='table table-sm table-striped'>";
                echo "<thead class='table-dark'>";
                echo "<tr>";
                echo "<th>Date</th>";
                echo "<th>Records</th>";
                echo "<th>First Punch In</th>";
                echo "<th>Last Punch Out</th>";
                echo "</tr>";
                echo "</thead>";
                echo "<tbody>";
                
                while ($row = $result_month->fetch_assoc()) {
                    echo "<tr class='table-success'>";
                    echo "<td><strong>" . $row['date'] . "</strong></td>";
                    echo "<td><span class='badge bg-success'>" . $row['record_count'] . "</span></td>";
                    echo "<td>" . ($row['first_punch_in'] ? substr($row['first_punch_in'], 0, 5) : '-') . "</td>";
                    echo "<td>" . ($row['last_punch_out'] ? substr($row['last_punch_out'], 0, 5) : '-') . "</td>";
                    echo "</tr>";
                }
                
                echo "</tbody>";
                echo "</table>";
                echo "</div>";
            } else {
                echo "<div class='alert alert-warning'>";
                echo "❌ No records found for current month (";
                echo $current_month . "/" . $current_year . ")";
                echo "</div>";
            }
            
            $stmt_month->close();
            
            ?>
        </div>
    </div>

    <!-- Statistics -->
    <div class="card">
        <div class="card-header bg-warning">
            <h5 class="mb-0">📊 Statistics</h5>
        </div>
        <div class="card-body">
            <?php
            
            $stats_sql = "SELECT 
                COUNT(*) as total_records,
                COUNT(DISTINCT DATE(date)) as days_with_records,
                COUNT(DISTINCT CASE WHEN punch_in IS NOT NULL THEN 1 END) as records_with_punch_in,
                COUNT(DISTINCT CASE WHEN punch_out IS NOT NULL THEN 1 END) as records_with_punch_out
            FROM attendance 
            WHERE user_id = ?";
            
            $stats_stmt = $conn->prepare($stats_sql);
            $stats_stmt->bind_param("i", $user_id);
            $stats_stmt->execute();
            $stats_result = $stats_stmt->get_result();
            $stats_row = $stats_result->fetch_assoc();
            
            echo "<div class='row'>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-primary mb-0'>";
            echo "<strong>Total Records:</strong><br>" . $stats_row['total_records'];
            echo "</div>";
            echo "</div>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-success mb-0'>";
            echo "<strong>Days:</strong><br>" . $stats_row['days_with_records'];
            echo "</div>";
            echo "</div>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-info mb-0'>";
            echo "<strong>Punch In Records:</strong><br>" . $stats_row['records_with_punch_in'];
            echo "</div>";
            echo "</div>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-danger mb-0'>";
            echo "<strong>Punch Out Records:</strong><br>" . $stats_row['records_with_punch_out'];
            echo "</div>";
            echo "</div>";
            echo "</div>";
            
            $stats_stmt->close();
            
            ?>
        </div>
    </div>

    <!-- Links -->
    <div class="mt-4">
        <a href="employee/my_attendance.php" class="btn btn-primary">← Go to My Attendance</a>
        <a href="employee/dashboard.php" class="btn btn-secondary">← Go to Dashboard</a>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
$conn->close();
?>
