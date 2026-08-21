<?php
session_start();
include('../config/db.php');

// Enable authentication check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

// Determine dashboard to return to based on 'from' parameter or user role
$from = isset($_GET['from']) ? htmlspecialchars($_GET['from']) : '';
if ($from === 'suparadmin') {
    $back_dashboard = 'dashboard.php';
} elseif ($from === 'admin') {
    $back_dashboard = 'admin_dashboard.php';
} else {
    $back_dashboard = ($_SESSION['role'] === 'suparadmin') ? 'dashboard.php' : 'admin_dashboard.php';
}

$message = "";
$message_type = "";

// Database Migration - Remove UNIQUE constraint to allow multiple punch in/out per day
$migration_result = "🔄 Checking database structure...";
$migration_steps = [];

// Step 1: Get the foreign key constraint name
$migration_steps[] = "Step 1: Checking for foreign key constraints...";

$fk_check = $conn->query("
    SELECT CONSTRAINT_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_NAME='attendance' 
    AND REFERENCED_TABLE_NAME='users'
    LIMIT 1
");

$fk_name = null;
if ($fk_check && $fk_check->num_rows > 0) {
    $fk_row = $fk_check->fetch_assoc();
    $fk_name = $fk_row['CONSTRAINT_NAME'];
    $migration_steps[] = "✓ Found foreign key: " . $fk_name;
} else {
    $migration_steps[] = "ℹ No foreign key constraint found";
}

// Step 2: Drop foreign key if it exists
if ($fk_name) {
    $migration_steps[] = "Step 2: Dropping foreign key constraint...";
    $drop_fk = "ALTER TABLE attendance DROP FOREIGN KEY " . $fk_name;
    if ($conn->query($drop_fk)) {
        $migration_steps[] = "✓ Foreign key dropped successfully";
    } else {
        $migration_steps[] = "⚠ Could not drop foreign key: " . $conn->error;
    }
}

// Step 3: Drop the UNIQUE index
$migration_steps[] = "Step 3: Checking for UNIQUE constraint...";

$index_check = $conn->query("
    SELECT CONSTRAINT_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_NAME='attendance' 
    AND CONSTRAINT_NAME='unique_daily_attendance'
");

if ($index_check && $index_check->num_rows > 0) {
    $migration_steps[] = "✓ Found UNIQUE constraint";
    $migration_steps[] = "Step 4: Removing UNIQUE constraint...";
    
    // Drop the unique index
    if ($conn->query("ALTER TABLE attendance DROP INDEX unique_daily_attendance")) {
        $migration_steps[] = "✓ UNIQUE constraint removed successfully";
        $migration_result = "✓ SUCCESS: Multiple punch in/out per day is now enabled!";
        $message_type = "success";
    } else {
        $migration_steps[] = "✗ Error removing constraint: " . $conn->error;
        $migration_result = "✗ Error removing constraint: " . $conn->error;
        $message_type = "danger";
    }
} else {
    $migration_steps[] = "✓ UNIQUE constraint already removed";
    $migration_result = "✓ Database structure is correct - UNIQUE constraint is already removed";
    $message_type = "info";
}

// Step 4: Re-add the foreign key without the unique constraint
if ($message_type !== 'danger') {
    $migration_steps[] = "Step 5: Re-adding foreign key constraint...";
    
    $check_fk = $conn->query("
        SELECT CONSTRAINT_NAME 
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS 
        WHERE TABLE_NAME='attendance' 
        AND REFERENCED_TABLE_NAME='users'
    ");
    
    if (!$check_fk || $check_fk->num_rows == 0) {
        // Add foreign key back
        if ($conn->query("ALTER TABLE attendance ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE")) {
            $migration_steps[] = "✓ Foreign key re-added successfully";
        } else {
            $migration_steps[] = "ℹ Foreign key already exists or could not be added";
        }
    } else {
        $migration_steps[] = "✓ Foreign key already present";
    }
}

// Verify the structure
$verify_stmt = $conn->query("DESC attendance");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    <?php include('_navbar.php'); ?>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">🔧 Database Setup & Migration</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-<?php echo $message_type === 'danger' ? 'danger' : ($message_type === 'info' ? 'info' : 'success'); ?>">
                            <h5><?php echo $migration_result; ?></h5>
                        </div>

                        <!-- Migration Steps -->
                        <div class="card mt-4 bg-light">
                            <div class="card-header">
                                <h6 class="mb-0">📋 Migration Process Log:</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach ($migration_steps as $step): ?>
                                    <div class="mb-2">
                                        <code><?php echo htmlspecialchars($step); ?></code>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <h6 class="mt-4 mb-3">📋 Database Structure:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Field</th>
                                        <th>Type</th>
                                        <th>Null</th>
                                        <th>Key</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $verify_stmt->fetch_assoc()): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['Field']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['Type']); ?></td>
                                            <td><?php echo htmlspecialchars($row['Null']); ?></td>
                                            <td><?php echo !empty($row['Key']) ? htmlspecialchars($row['Key']) : '-'; ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-4">
                            <h6>✅ What was fixed:</h6>
                            <ul class="mb-0">
                                <li>Removed UNIQUE constraint on (user_id, date)</li>
                                <li>Employees can now have multiple punch in/out records per day</li>
                                <li>Supports lunch breaks and multiple work sessions</li>
                                <li>Admin export shows first punch in and last punch out per day</li>
                            </ul>
                        </div>

                        <div class="mt-4">
                            <a href="setup.php" class="btn btn-primary me-2">🔄 Refresh</a>
                            <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary">← Back to Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        <?php if ($message_type === 'success'): ?>
            Swal.fire({
                icon: 'success',
                title: 'Migration Successful!',
                text: 'Database has been updated to support multiple punch in/out per day',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'Got it!'
            });
        <?php endif; ?>
    </script>
</body>
</html>
<?php
$conn->close();
?>
