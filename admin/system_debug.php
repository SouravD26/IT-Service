<?php
session_start();
include('./config/db.php');

// Only super admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'suparadmin') {
    die("Access denied. Only Super Admin can access this page.");
}

$debug_info = [];

// Test 1: Database connection
$debug_info['database_connection'] = $conn ? '✓ Connected' : '✗ Failed';

// Test 2: Check if users table exists
$result = $conn->query("SHOW TABLES LIKE 'users'");
$debug_info['users_table'] = ($result && $result->num_rows > 0) ? '✓ Exists' : '✗ Does not exist';

// Test 3: Count total users
$result = $conn->query("SELECT COUNT(*) as count FROM users");
$row = $result->fetch_assoc();
$debug_info['total_users'] = $row['count'] ?? 0;

// Test 4: Count employees
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'employee'");
$row = $result->fetch_assoc();
$debug_info['total_employees'] = $row['count'] ?? 0;

// Test 5: List all employees
$result = $conn->query("SELECT id, name, employee_id, role FROM users WHERE role = 'employee' ORDER BY name ASC");
$employees_list = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $employees_list[] = $row;
    }
}
$debug_info['employees_found'] = count($employees_list);

// Test 6: Check profile_photo column
$result = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo'");
$debug_info['profile_photo_column'] = ($result && $result->num_rows > 0) ? '✓ Exists' : '✗ Missing (run migration)';

// Test 7: Check attendance table columns
$result = $conn->query("SHOW COLUMNS FROM attendance");
$attendance_columns = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $attendance_columns[] = $row['Field'];
    }
}
$debug_info['source_column'] = in_array('source', $attendance_columns) ? '✓ Exists' : '✗ Missing';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Debug Tool</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 30px; }
        .test-row { padding: 12px; margin: 8px 0; border-radius: 5px; }
        .pass { background-color: #d4edda; border-left: 4px solid #28a745; }
        .fail { background-color: #f8d7da; border-left: 4px solid #dc3545; }
        .warn { background-color: #fff3cd; border-left: 4px solid #ffc107; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 10px; margin-bottom: 30px; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-stethoscope"></i> System Debug Tool</h1>
            <p>Check system health and configuration</p>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">System Health Check</h5>
            </div>
            <div class="card-body">
                <?php foreach ($debug_info as $test => $result): 
                    $is_pass = strpos($result, '✓') === 0;
                    $class = $is_pass ? 'pass' : (strpos($result, '✗') === 0 ? 'fail' : 'warn');
                ?>
                <div class="test-row <?php echo $class; ?>">
                    <strong><?php echo ucfirst(str_replace('_', ' ', $test)); ?>:</strong>
                    <?php echo htmlspecialchars($result); ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Employees Found: <?php echo count($employees_list); ?></h5>
            </div>
            <div class="card-body">
                <?php if (empty($employees_list)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>No employees found!</strong>
                        <p>There are no users with role = 'employee' in the database.</p>
                        <p>Please ensure the database was properly initialized with sample data.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Employee ID</th>
                                <th>Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees_list as $emp): ?>
                            <tr>
                                <td><?php echo $emp['id']; ?></td>
                                <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['employee_id']); ?></td>
                                <td><?php echo htmlspecialchars($emp['role']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">Next Steps</h5>
            </div>
            <div class="card-body">
                <?php if ($debug_info['total_employees'] == 0): ?>
                    <div class="alert alert-danger">
                        <strong>No employees in database!</strong>
                        <p>You need to add employees first. Options:</p>
                        <ol>
                            <li><strong>Use Admin Panel:</strong> Go to admin/employees.php to add employees</li>
                            <li><strong>Or Run Fresh Setup:</strong> 
                                <ul>
                                    <li>Backup your database</li>
                                    <li>Run database.sql again to recreate with sample data</li>
                                </ul>
                            </li>
                        </ol>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        <strong>✓ Everything looks good!</strong>
                        <p>Go to: <a href="admin/manage_employee_photos.php" class="alert-link">Employee Photos Manager</a></p>
                        <p>You should now see <?php echo count($employees_list); ?> employee(s) in the dropdown.</p>
                    </div>
                <?php endif; ?>

                <?php if (strpos($debug_info['profile_photo_column'], '✗') === 0): ?>
                    <div class="alert alert-warning mt-3">
                        <strong>⚠ Database migration not run!</strong>
                        <p>You still need to run the database migration to add face recognition columns.</p>
                        <p><a href="admin/face_recognition_setup.php" class="alert-link">Go to Setup Page</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="admin/dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <a href="admin/manage_employee_photos.php" class="btn btn-primary">
                <i class="fas fa-camera"></i> Go to Employee Photos
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
