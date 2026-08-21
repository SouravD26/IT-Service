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

// Create shifts table if it doesn't exist
$check_table = $conn->query("SHOW TABLES LIKE 'shifts'");
if ($check_table->num_rows === 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS shifts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        start_time TIME,
        end_time TIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_shift (start_time, end_time)
    )";
    $conn->query($create_table);
}

// Columns are already created with the table, no need to add them again

// Handle Add Shift
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_shift'])) {
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    
    if (empty($start_time) || empty($end_time)) {
        $message = "Start time and end time are required";
        $message_type = "danger";
    } else {
        $stmt_insert = $conn->prepare("INSERT INTO shifts (start_time, end_time) VALUES (?, ?)");
        if (!$stmt_insert) {
            $message = "✗ Database error: " . $conn->error;
            $message_type = "danger";
        } else {
            $stmt_insert->bind_param("ss", $start_time, $end_time);
            
            if ($stmt_insert->execute()) {
                $message = "✓ Shift added successfully!";
                $message_type = "success";
            } else {
                $message = "Error: " . $stmt_insert->error;
                $message_type = "danger";
            }
            $stmt_insert->close();
        }
    }
}

// Handle Delete Shift
if (isset($_GET['delete_shift'])) {
    $shift_id = (int)$_GET['delete_shift'];
    
    // Check if shift is being used by any employee
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE shift_time = (SELECT name FROM shifts WHERE id = ?) LIMIT 1");
    if ($stmt_check) {
        $stmt_check->bind_param("i", $shift_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $message = "Cannot delete shift that is assigned to employees";
            $message_type = "danger";
        } else {
            $stmt_delete = $conn->prepare("DELETE FROM shifts WHERE id = ?");
            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $shift_id);
                
                if ($stmt_delete->execute()) {
                    $message = "✓ Shift deleted successfully!";
                    $message_type = "success";
                } else {
                    $message = "✗ Error: " . $stmt_delete->error;
                    $message_type = "danger";
                }
                $stmt_delete->close();
            } else {
                $message = "✗ Database error: " . $conn->error;
                $message_type = "danger";
            }
        }
        $stmt_check->close();
    } else {
        $message = "✗ Database error: " . $conn->error;
        $message_type = "danger";
    }
}

// Fetch all shifts
$stmt = $conn->prepare("SELECT id, start_time, end_time, created_at FROM shifts ORDER BY start_time");
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = null;
    $message = "✗ Error loading shifts: " . $conn->error;
    $message_type = "danger";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Management - Attendance System</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-5">
        <!-- Add Shift Form -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">➕ Add New Shift</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Start Time</label>
                        <input type="time" name="start_time" class="form-control" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">End Time</label>
                        <input type="time" name="end_time" class="form-control" required>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" name="add_shift" class="btn btn-success w-100">✓ Add</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Shifts List -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">All Shifts</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Start Time</th>
                                <th>End Time</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $start_time = date('h:i A', strtotime($row['start_time']));
                                    $end_time = date('h:i A', strtotime($row['end_time']));
                                    $shift_display = $start_time . ' - ' . $end_time;
                                    echo "<tr>";
                                    echo "<td>" . $row['id'] . "</td>";
                                    echo "<td>" . $start_time . "</td>";
                                    echo "<td>" . $end_time . "</td>";
                                    echo "<td>" . date('d-m-Y H:i', strtotime($row['created_at'])) . "</td>";
                                    echo "<td>";
                                    echo "<button type='button' onclick=\"confirmDeleteShift(" . $row['id'] . ", '" . $shift_display . "')\" class='btn btn-sm btn-danger'>Delete</button>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='5' class='text-center text-muted'>No shifts found. Add one to get started!</td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <a href="<?php echo htmlspecialchars($back_dashboard); ?>" class="btn btn-secondary">Back to Dashboard</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDeleteShift(shiftId, shiftName) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'Delete shift: ' + shiftName,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_shift=' + shiftId;
                }
            });
        }

        <?php
        if ($message) {
            $icon = 'success';
            $title = 'Success';
            if ($message_type === 'danger') {
                $icon = 'error';
                $title = 'Error';
            } elseif ($message_type === 'warning') {
                $icon = 'warning';
                $title = 'Warning';
            }
            $message_escaped = addslashes($message);
            echo "Swal.fire({
                icon: '$icon',
                title: '$title',
                text: '$message_escaped',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'OK'
            });";
        }
        ?>
    </script>
</body>
</html>

<?php
$stmt->close();
?>

