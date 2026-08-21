<?php
/**
 * Verify Locations Being Stored
 * Shows what's in the database for each type of punch
 */
session_start();
include('config/db.php');

if (!isset($_SESSION['user_id'])) {
    die("Please login");
}

if ($_SESSION['role'] === 'employee') {
    $user_id = $_SESSION['user_id'];
} else {
    // Admin checking a specific employee
    $user_id = $_GET['employee_id'] ?? $_SESSION['user_id'];
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Location Verification</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; background: #f5f5f5; }
        table { background: white; margin: 20px 0; }
        .office { background: #e3f2fd; }
        .gps { background: #fff3e0; font-weight: bold; color: #e65100; }
        th { background: #333; color: white; }
    </style>
</head>
<body>

<div class="container">
    <h2>📍 Location Verification Report</h2>
    
    <?php
    $sql = "SELECT 
        id, 
        date,
        punch_in,
        punch_in_location,
        punch_out,
        punch_out_location
    FROM attendance 
    WHERE user_id = ? 
    AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY date DESC, id DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<table class='table table-striped'>";
    echo "<tr><th>Date</th><th>Punch In Time</th><th>📍 Punch In Location</th><th>Punch Out Time</th><th>📍 Punch Out Location</th></tr>";
    
    $current_date = '';
    while ($row = $result->fetch_assoc()) {
        if ($current_date !== $row['date']) {
            if ($current_date !== '') {
                echo "<tr><td colspan='5' style='height: 5px;'></td></tr>";
            }
            $current_date = $row['date'];
        }
        
        // Check if locations are correct
        $pin_location = $row['punch_in_location'] ?? 'NOT SET';
        $pout_location = $row['punch_out_location'] ?? 'NOT SET';
        
        $pin_class = ($pin_location === 'Office') ? 'office' : 'gps';
        $pout_class = ($pout_location === 'NOT SET' || $pout_location === '') ? '' : 'gps';
        
        echo "<tr>";
        echo "<td>{$row['date']}</td>";
        echo "<td>" . ($row['punch_in'] ?? '-') . "</td>";
        echo "<td class='$pin_class'>" . htmlspecialchars($pin_location) . "</td>";
        echo "<td>" . ($row['punch_out'] ?? '-') . "</td>";
        echo "<td class='$pout_class'>" . htmlspecialchars($pout_location) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    // Summary
    echo "<h3 class='mt-4'>Summary:</h3>";
    
    $summary_sql = "SELECT 
        'Punch In' as type,
        COUNT(*) as total,
        SUM(CASE WHEN punch_in_location = 'Office' THEN 1 ELSE 0 END) as office,
        SUM(CASE WHEN punch_in_location != 'Office' AND punch_in_location IS NOT NULL THEN 1 ELSE 0 END) as gps
    FROM attendance 
    WHERE user_id = ? AND punch_in IS NOT NULL
    UNION ALL
    SELECT 
        'Punch Out' as type,
        COUNT(*) as total,
        SUM(CASE WHEN punch_out_location IS NULL OR punch_out_location = 'Office' THEN 1 ELSE 0 END) as office,
        SUM(CASE WHEN punch_out_location IS NOT NULL AND punch_out_location != 'Office' THEN 1 ELSE 0 END) as gps
    FROM attendance 
    WHERE user_id = ? AND punch_out IS NOT NULL";
    
    $summary_stmt = $conn->prepare($summary_sql);
    $summary_stmt->bind_param("ii", $user_id, $user_id);
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    
    echo "<table class='table'>";
    echo "<tr><th>Type</th><th>Total Records</th><th>Default Location</th><th>GPS Location</th></tr>";
    while ($row = $summary_result->fetch_assoc()) {
        echo "<tr>";
        echo "<td><strong>{$row['type']}</strong></td>";
        echo "<td>{$row['total']}</td>";
        echo "<td class='office'>{$row['office']}</td>";
        echo "<td class='gps'>{$row['gps']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    ?>
    
    <div class="alert alert-info mt-4">
        <h5>Expected Behavior:</h5>
        <ul>
            <li><span class="badge bg-primary">Punch In</span> → Should show 'Office' (default)</li>
            <li><span class="badge bg-warning">Punch Out</span> → Should show GPS location (address)</li>
        </ul>
    </div>
    
    <a href="employee/my_attendance.php" class="btn btn-primary">← Back to My Attendance</a>
</div>

</body>
</html>

<?php
$conn->close();
?>
