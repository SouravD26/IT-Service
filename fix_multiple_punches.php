<?php
/**
 * Quick Fix: Remove Unique Constraint Blocking Multiple Punches
 * Visit this page to automatically fix the issue
 */

include('config/db.php');

echo "<!DOCTYPE html>
<html>
<head>
    <title>Fix Multiple Punches</title>
    <link rel='icon' type='image/png' href='assets/images/favicon.png'>
    <style>
        body { font-family: Arial; margin: 40px; background: #f5f5f5; }
        .box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 600px; }
        .success { color: green; font-size: 18px; }
        .error { color: red; font-size: 18px; }
    </style>
</head>
<body>
<div class='box'>";

try {
    // Try to drop the unique constraint
    $drop_query = "ALTER TABLE attendance DROP INDEX IF EXISTS unique_daily_record";
    
    if ($conn->query($drop_query)) {
        echo "<h2 class='success'>✅ SUCCESS! Multiple Punches Enabled</h2>";
        echo "<p>The unique constraint has been removed.</p>";
        echo "<p><strong>You can now:</strong></p>";
        echo "<ul>";
        echo "<li>✓ Punch In multiple times per day</li>";
        echo "<li>✓ Punch Out multiple times per day</li>";
        echo "<li>✓ Take lunch breaks with multiple cycles</li>";
        echo "</ul>";
    } else {
        echo "<h2 class='error'>❌ Error: " . $conn->error . "</h2>";
    }
    
} catch (Exception $e) {
    echo "<h2 class='error'>❌ Exception: " . $e->getMessage() . "</h2>";
}

echo "<hr>";
echo "<p><a href='face_attendance.php'>← Back to Face Attendance</a></p>";
echo "<p><a href='employee/dashboard.php'>← Back to Employee Dashboard</a></p>";

echo "</div>
</body>
</html>";

$conn->close();
?>
