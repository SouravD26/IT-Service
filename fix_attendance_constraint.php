<?php
/**
 * DIRECT FIX: Allow Duplicate Punch Records Per Day
 * This removes the unique constraint blocking multiple punches
 */

include('config/db.php');

header('Content-Type: text/html; charset=utf-8');

?>
<!DOCTYPE html>
<html>
<head>
    <title>Allow Multiple Punches - Fix</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 0; 
            padding: 20px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h2 { margin-top: 0; }
        .success { color: #28a745; font-size: 18px; font-weight: bold; }
        .error { color: #dc3545; font-size: 18px; font-weight: bold; }
        .info { background: #e7f3ff; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; }
        a { color: #667eea; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class='container'>

<?php

// First, show current status
echo "<h2>🔧 Fixing Multiple Punch Records Issue</h2>";

// Check current constraints
$check_sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
              WHERE TABLE_NAME='attendance' 
              AND TABLE_SCHEMA=DATABASE() 
              AND CONSTRAINT_NAME LIKE '%unique%'";

$check_result = $conn->query($check_sql);

echo "<h3>Current Constraints:</h3>";
if ($check_result->num_rows > 0) {
    echo "<ul>";
    while ($row = $check_result->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($row['CONSTRAINT_NAME']) . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color: orange;'>No unique constraints found</p>";
}

echo "<hr>";

// Now remove the constraint
echo "<h3>Removing unique_daily_record constraint...</h3>";

try {
    // Check if constraint exists first
    $constraint_check = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                         WHERE TABLE_NAME='attendance' 
                         AND TABLE_SCHEMA=DATABASE()
                         AND CONSTRAINT_NAME='unique_daily_record'";
    
    $constraint_result = $conn->query($constraint_check);
    
    if ($constraint_result->num_rows > 0) {
        // Constraint exists, drop it
        $drop_sql = "ALTER TABLE attendance DROP INDEX unique_daily_record";
        
        if ($conn->query($drop_sql)) {
            echo "<p class='success'>✅ Constraint REMOVED successfully!</p>";
            echo "<div class='info'>";
            echo "<strong>✓ Multiple punch records are now ALLOWED</strong><br>";
            echo "Employee 3 can now punch in/out multiple times on the same day<br>";
            echo "Each punch will create a new record in the database<br>";
            echo "Dashboard will automatically show first IN and last OUT";
            echo "</div>";
        } else {
            echo "<p class='error'>❌ Error dropping constraint: " . $conn->error . "</p>";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ Constraint 'unique_daily_record' not found</p>";
        echo "<p>Trying alternative constraint names...</p>";
        
        // Try to find and drop other unique constraints on user_id, date
        $find_all = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                     WHERE TABLE_NAME='attendance' 
                     AND TABLE_SCHEMA=DATABASE()
                     AND COLUMN_NAME IN ('user_id', 'date')
                     AND CONSTRAINT_NAME != 'PRIMARY'";
        
        $find_result = $conn->query($find_all);
        
        if ($find_result->num_rows > 0) {
            while ($row = $find_result->fetch_assoc()) {
                $constraint_name = $row['CONSTRAINT_NAME'];
                $drop_alt = "ALTER TABLE attendance DROP INDEX " . $constraint_name;
                
                if ($conn->query($drop_alt)) {
                    echo "<p class='success'>✅ Dropped constraint: " . htmlspecialchars($constraint_name) . "</p>";
                }
            }
        }
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";

// Verify the fix
echo "<h3>Verification:</h3>";

$verify_sql = "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
               WHERE TABLE_NAME='attendance' 
               AND TABLE_SCHEMA=DATABASE() 
               AND CONSTRAINT_NAME LIKE '%unique%'";

$verify_result = $conn->query($verify_sql);

if ($verify_result->num_rows === 0) {
    echo "<p class='success'>✅ SUCCESS! All unique constraints removed.</p>";
    echo "<div class='info'>";
    echo "<strong>System is ready for multiple punches:</strong><br>";
    echo "👉 <a href='face_attendance.php'>Go to Face Attendance</a><br>";
    echo "👉 <a href='employee/dashboard.php'>Go to Employee Dashboard</a><br>";
    echo "👉 Try punching in again!";
    echo "</div>";
} else {
    echo "<p style='color: orange;'>⚠️ Some constraints still exist:</p>";
    echo "<ul>";
    while ($row = $verify_result->fetch_assoc()) {
        echo "<li>" . htmlspecialchars($row['CONSTRAINT_NAME']) . "</li>";
    }
    echo "</ul>";
}

$conn->close();

?>

</div>
</body>
</html>
