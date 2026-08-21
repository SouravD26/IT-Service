<?php
session_start();
include('../config/db.php');

// Only Super Admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'suparadmin') {
    die("Access denied. Only Super Admin.");
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$result_msg = '';
$result_type = 'info';

if ($action === 'add_sample_employees') {
    // Check if employees already exist
    $check = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'employee'");
    $check_result = $check->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        $result_msg = '✓ Employees already exist! Found: ' . $check_result['count'] . ' employee(s)';
        $result_type = 'success';
    } else {
        // Insert sample employees
        $employees = [
            ['name' => 'John Doe', 'email' => 'john@test.com', 'employee_id' => 'E001', 'department' => 'IT', 'phone' => '9830376201'],
            ['name' => 'Jane Smith', 'email' => 'jane@test.com', 'employee_id' => 'E002', 'department' => 'Finance', 'phone' => '9830376202'],
            ['name' => 'Mike Johnson', 'email' => 'mike@test.com', 'employee_id' => 'E003', 'department' => 'HR', 'phone' => '9830376203'],
            ['name' => 'Sarah Williams', 'email' => 'sarah@test.com', 'employee_id' => 'E004', 'department' => 'Marketing', 'phone' => '9830376204'],
            ['name' => 'David Brown', 'email' => 'david@test.com', 'employee_id' => 'E005', 'department' => 'Sales', 'phone' => '9830376205'],
        ];
        
        $password = '$2y$10$YHqsQJPvLEOl3gRd1C.ape8c5QNgYTVMcQUVfLswDpQdYQiWhh2/q'; // "admin123" hashed
        $added = 0;
        
        foreach ($employees as $emp) {
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, department, employee_id, company, phone) VALUES (?, ?, ?, 'employee', ?, 'Company ABC', ?)");
            $stmt->bind_param("sssss", $emp['name'], $emp['email'], $password, $emp['department'], $emp['phone']);
            
            if ($stmt->execute()) {
                $added++;
            }
            $stmt->close();
        }
        
        $result_msg = "✓ Added $added sample employee(s) to the system!";
        $result_type = 'success';
    }
}

// Get current employee count
$count_result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'employee'");
$count_row = $count_result->fetch_assoc();
$employee_count = $count_row['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup & Employee Initialization</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; margin-bottom: 30px; border-radius: 10px; }
        .status-box { padding: 20px; margin: 20px 0; border-radius: 8px; }
        .status-success { background-color: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .status-info { background-color: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .status-warning { background-color: #fff3cd; border: 1px solid #ffeaa7; color: #856404; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>
    <div class="container mt-5 mb-5">
        <div class="header">
            <h1><i class="fas fa-database"></i> Database Initialization</h1>
            <p>Initialize employees and face recognition system</p>
        </div>

        <?php if (!empty($result_msg)): ?>
        <div class="alert alert-<?php echo $result_type; ?>">
            <?php echo htmlspecialchars($result_msg); ?>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Current Status</h5>
            </div>
            <div class="card-body">
                <div class="status-box <?php echo $employee_count > 0 ? 'status-success' : 'status-warning'; ?>">
                    <h6>Employees in Database:</h6>
                    <p style="font-size: 2rem; font-weight: bold; margin: 0;">
                        <?php echo $employee_count; ?>
                    </p>
                    <small>
                        <?php if ($employee_count == 0): ?>
                            No employees found. Click the button below to add sample employees.
                        <?php else: ?>
                            <?php echo $employee_count; ?> employee(s) ready for photo upload.
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">Setup Steps</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <h6><i class="fas fa-check-circle"></i> Step 1: Run Database Migration</h6>
                        <p class="small">Add face recognition columns to database.</p>
                        <a href="face_recognition_setup.php" class="btn btn-sm btn-info">
                            <i class="fas fa-cogs"></i> Run Migration
                        </a>
                    </div>
                    <div class="col-md-6 mb-3">
                        <h6><i class="fas fa-check-circle"></i> Step 2: Add Employees (If Needed)</h6>
                        <p class="small">Initialize with sample employees for testing.</p>
                        <form method="GET" style="display: inline;">
                            <input type="hidden" name="action" value="add_sample_employees">
                            <button type="submit" class="btn btn-sm btn-warning" <?php echo $employee_count > 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-plus"></i> Add Sample Employees
                            </button>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle"></i> Step 3: Upload Photos</h6>
                        <p class="small">Upload photos for each employee.</p>
                        <a href="manage_employee_photos.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-camera"></i> Photo Manager
                        </a>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-check-circle"></i> Step 4: Test System</h6>
                        <p class="small">Test face recognition panel.</p>
                        <a href="../multi_user_attendance.php" class="btn btn-sm btn-success" target="_blank">
                            <i class="fas fa-video"></i> Open Panel
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Information</h5>
            </div>
            <div class="card-body">
                <p><strong>What sample employees will be added?</strong></p>
                <ul>
                    <li>John Doe (E001) - IT Department</li>
                    <li>Jane Smith (E002) - Finance Department</li>
                    <li>Mike Johnson (E003) - HR Department</li>
                    <li>Sarah Williams (E004) - Marketing Department</li>
                    <li>David Brown (E005) - Sales Department</li>
                </ul>
                <p><strong>Note:</strong> You can always add more employees later through the admin panel.</p>
            </div>
        </div>

        <div style="text-align: center; margin-top: 30px;">
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
