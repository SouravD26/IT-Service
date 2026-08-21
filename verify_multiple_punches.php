<?php
/**
 * Verify Multiple Punch Records
 * View all punch in/out records for employees
 */

session_start();
include('config/db.php');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Multiple Punch Records</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1000px; margin-top: 20px; }
        .card { box-shadow: 0 4px 20px rgba(0,0,0,0.1); border: none; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .table-success { background-color: #d4edda; }
        .badge-punchin { background-color: #28a745; }
        .badge-punchout { background-color: #dc3545; }
        h2 { color: white; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="container">
    <h2>📊 Multiple Punch Records Verification</h2>
    
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">🔍 View Punch Records</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Employee ID</label>
                    <input type="number" name="user_id" class="form-control" placeholder="Enter Employee ID" 
                           value="<?php echo isset($_GET['user_id']) ? htmlspecialchars($_GET['user_id']) : ''; ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" 
                           value="<?php echo isset($_GET['date']) ? htmlspecialchars($_GET['date']) : date('Y-m-d'); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">🔍 Search Records</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    
    // Get all employees for reference
    $employees_sql = "SELECT id, name, employee_id FROM users WHERE role = 'employee' ORDER BY name LIMIT 10";
    $employees_result = $conn->query($employees_sql);
    
    if (isset($_GET['user_id']) && isset($_GET['date'])) {
        $user_id = intval($_GET['user_id']);
        $date = $_GET['date'];
        
        // Get employee info
        $emp_sql = "SELECT name, employee_id FROM users WHERE id = ?";
        $emp_stmt = $conn->prepare($emp_sql);
        $emp_stmt->bind_param("i", $user_id);
        $emp_stmt->execute();
        $emp_result = $emp_stmt->get_result();
        $emp_row = $emp_result->fetch_assoc();
        
        if ($emp_row) {
            echo "<div class='card'>";
            echo "<div class='card-header'>";
            echo "<h5 class='mb-0'>Employee: " . htmlspecialchars($emp_row['name']) . " (ID: " . htmlspecialchars($emp_row['employee_id']) . ")</h5>";
            echo "</div>";
            echo "<div class='card-body'>";
            echo "<p><strong>Date:</strong> " . htmlspecialchars($date) . "</p>";
            echo "</div>";
            echo "</div>";
        }
        
        // Get all punch records for this employee on this date
        $punch_sql = "SELECT id, punch_in, punch_out, punch_in_location, punch_out_location, selfie_punchin, selfie_punchout, status 
                      FROM attendance 
                      WHERE user_id = ? AND date = ? 
                      ORDER BY id ASC";
        
        $punch_stmt = $conn->prepare($punch_sql);
        $punch_stmt->bind_param("is", $user_id, $date);
        $punch_stmt->execute();
        $punch_result = $punch_stmt->get_result();
        
        if ($punch_result->num_rows > 0) {
            echo "<div class='card'>";
            echo "<div class='card-header'>";
            echo "<h5 class='mb-0'>✅ Found " . $punch_result->num_rows . " Record(s)</h5>";
            echo "</div>";
            echo "<div class='card-body'>";
            
            echo "<div class='table-responsive'>";
            echo "<table class='table table-striped table-hover'>";
            echo "<thead class='table-dark'>";
            echo "<tr>";
            echo "<th>#</th>";
            echo "<th>Punch In</th>";
            echo "<th>Punch In Location</th>";
            echo "<th>Punch Out</th>";
            echo "<th>Punch Out Location</th>";
            echo "<th>Status</th>";
            echo "</tr>";
            echo "</thead>";
            echo "<tbody>";
            
            $count = 1;
            $total_in = 0;
            $total_out = 0;
            $first_punch_in = null;
            $last_punch_out = null;
            
            while ($row = $punch_result->fetch_assoc()) {
                if ($row['punch_in']) {
                    $total_in++;
                    if (!$first_punch_in) $first_punch_in = $row['punch_in'];
                }
                if ($row['punch_out']) {
                    $total_out++;
                    $last_punch_out = $row['punch_out'];
                }
                
                $punch_in = $row['punch_in'] ? substr($row['punch_in'], 0, 5) : '-';
                $punch_out = $row['punch_out'] ? substr($row['punch_out'], 0, 5) : '-';
                $in_location = $row['punch_in_location'] ?: '-';
                $out_location = $row['punch_out_location'] ?: '-';
                $status = $row['status'] ?: 'Pending';
                
                echo "<tr>";
                echo "<td><strong>" . $count . "</strong></td>";
                echo "<td>";
                if ($row['punch_in']) {
                    echo "<span class='badge badge-punchin'>IN: " . $punch_in . "</span>";
                } else {
                    echo "-";
                }
                echo "</td>";
                echo "<td>" . htmlspecialchars($in_location) . "</td>";
                echo "<td>";
                if ($row['punch_out']) {
                    echo "<span class='badge badge-punchout'>OUT: " . $punch_out . "</span>";
                } else {
                    echo "-";
                }
                echo "</td>";
                echo "<td>" . htmlspecialchars($out_location) . "</td>";
                echo "<td>";
                if ($status === 'Present') {
                    echo "<span class='badge bg-success'>Present</span>";
                } else {
                    echo "<span class='badge bg-warning'>" . htmlspecialchars($status) . "</span>";
                }
                echo "</td>";
                echo "</tr>";
                
                $count++;
            }
            
            echo "</tbody>";
            echo "</table>";
            echo "</div>";
            
            // Summary
            echo "<hr>";
            echo "<h5>📈 Summary</h5>";
            echo "<div class='row'>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-info mb-0'>";
            echo "<strong>Total Punch In(s):</strong> " . $total_in;
            echo "</div>";
            echo "</div>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-danger mb-0'>";
            echo "<strong>Total Punch Out(s):</strong> " . $total_out;
            echo "</div>";
            echo "</div>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-success mb-0'>";
            echo "<strong>First Punch In:</strong><br>" . ($first_punch_in ? substr($first_punch_in, 0, 5) : 'None');
            echo "</div>";
            echo "</div>";
            echo "<div class='col-md-3'>";
            echo "<div class='alert alert-warning mb-0'>";
            echo "<strong>Last Punch Out:</strong><br>" . ($last_punch_out ? substr($last_punch_out, 0, 5) : 'None');
            echo "</div>";
            echo "</div>";
            echo "</div>";
            
            echo "</div>";
            echo "</div>";
            
        } else {
            echo "<div class='alert alert-warning'>";
            echo "❌ No punch records found for Employee ID " . htmlspecialchars($user_id) . " on " . htmlspecialchars($date);
            echo "</div>";
        }
        
        $punch_stmt->close();
    }
    
    ?>

    <!-- Quick Links -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Recent Employees</h5>
        </div>
        <div class="card-body">
            <p>Click an employee to view their today's records:</p>
            <div class="row">
            <?php
            if ($employees_result->num_rows > 0) {
                while ($emp = $employees_result->fetch_assoc()) {
                    echo "<div class='col-md-4 mb-2'>";
                    echo "<a href='?user_id=" . $emp['id'] . "&date=" . date('Y-m-d') . "' class='btn btn-sm btn-outline-primary w-100'>";
                    echo htmlspecialchars($emp['name']) . " (ID: " . $emp['id'] . ")";
                    echo "</a>";
                    echo "</div>";
                }
            }
            ?>
            </div>
        </div>
    </div>

    <div class="text-white mt-4 text-center">
        <p><a href="face_attendance.php" class="btn btn-light btn-sm">← Back to Face Attendance</a></p>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

<?php
$conn->close();
?>
