<?php
/**
 * Database Setup for Face Attendance Feature
 * Run this ONCE to add the required 'role' column to users table
 */

include('db.php');

$results = [];

// Check if 'role' column exists in users table
$check_role = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");

if ($check_role->num_rows === 0) {
    // Add 'role' column
    $add_role = $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'user' AFTER password");
    
    if ($add_role) {
        $results[] = "✅ Added 'role' column to users table";
    } else {
        $results[] = "❌ Error adding 'role' column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ 'role' column already exists";
}

// Check if 'dashboard_role' column exists
$check_dashboard = $conn->query("SHOW COLUMNS FROM users LIKE 'dashboard_role'");

if ($check_dashboard->num_rows === 0) {
    // Add 'dashboard_role' column
    $add_dashboard = $conn->query("ALTER TABLE users ADD COLUMN dashboard_role VARCHAR(50) AFTER role");
    
    if ($add_dashboard) {
        $results[] = "✅ Added 'dashboard_role' column to users table";
    } else {
        $results[] = "❌ Error adding 'dashboard_role' column: " . $conn->error;
    }
} else {
    $results[] = "ℹ️ 'dashboard_role' column already exists";
}

// Set default role for suparadmin and admin users if they don't have one
$update_admins = $conn->query("
    UPDATE users 
    SET role = 'admin' 
    WHERE role IS NULL OR role = '' AND id > 1
");

if ($update_admins) {
    $results[] = "✅ Updated roles for existing users";
} else {
    $results[] = "⚠️ Could not update existing roles: " . $conn->error;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Face Attendance Setup</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { padding: 20px; background: #f5f5f5; }
        .setup-card { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 30px auto; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .result { padding: 10px; margin: 10px 0; border-radius: 5px; font-size: 1.1rem; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    </style>
</head>
<body>
    <div class="setup-card">
        <h2><i class="fas fa-cog"></i> Face Attendance Database Setup</h2>
        <p class="text-muted mb-4">Initializing required database columns...</p>
        
        <div>
            <?php foreach ($results as $result): ?>
                <div class="result <?php 
                    if (strpos($result, '✅') !== false) echo 'success';
                    elseif (strpos($result, '❌') !== false) echo 'error';
                    else echo 'info';
                ?>">
                    <?php echo $result; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <hr>
        
        <div class="alert alert-success mt-4">
            <strong>✅ Setup Complete!</strong><br>
            The database is now configured for face attendance.<br>
            <a href="../admin/manage_face_attendance_logins.php" class="btn btn-success btn-sm mt-2">
                Go to Manage Face Attendance Logins
            </a>
        </div>

        <small class="text-muted d-block mt-3">
            This script only needs to run once. The required columns have been added to your database.
        </small>
    </div>
</body>
</html>
