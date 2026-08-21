<?php
session_start();
include('../config/db.php');

// Check authentication - only admin and suparadmin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    header("Location: ../auth/login.php");
    exit();
}

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'punch') {
    $employee_id = intval($_POST['employee_id']);
    $punch_date = $_POST['punch_date'];
    $punch_in_time = $_POST['punch_in_time'];
    $punch_out_time = isset($_POST['punch_out_time']) && !empty($_POST['punch_out_time']) ? $_POST['punch_out_time'] : null;
    $location = $_POST['location'];

    // Validate employee exists
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee' AND status = 'Working'");
    $stmt_check->bind_param("i", $employee_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows === 0) {
        $message = 'Invalid employee selected!';
        $message_type = 'danger';
    } else {
        // Create full timestamp
        $punch_in_datetime = $punch_date . ' ' . $punch_in_time . ':00';
        $punch_out_datetime = $punch_out_time ? $punch_date . ' ' . $punch_out_time . ':00' : null;

        // Check if record exists for this date
        $stmt_exist = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND DATE(date) = ?");
        $stmt_exist->bind_param("is", $employee_id, $punch_date);
        $stmt_exist->execute();
        $result_exist = $stmt_exist->get_result();

        if ($result_exist->num_rows > 0) {
            // Update existing record
            $attendance_id = $result_exist->fetch_assoc()['id'];
            
            if ($punch_out_time) {
                $stmt_update = $conn->prepare("UPDATE attendance SET punch_in = ?, punch_out = ?, punch_in_location = ?, punch_out_location = ? WHERE id = ?");
                
                if ($stmt_update === false) {
                    $message = 'Database error: ' . $conn->error;
                    $message_type = 'danger';
                } else {
                    $stmt_update->bind_param("ssssi", $punch_in_datetime, $punch_out_datetime, $location, $location, $attendance_id);
                    
                    if ($stmt_update->execute()) {
                        $message = 'Attendance updated successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Error updating attendance: ' . $stmt_update->error;
                        $message_type = 'danger';
                    }
                    $stmt_update->close();
                }
            } else {
                $stmt_update = $conn->prepare("UPDATE attendance SET punch_in = ?, punch_in_location = ? WHERE id = ?");
                
                if ($stmt_update === false) {
                    $message = 'Database error: ' . $conn->error;
                    $message_type = 'danger';
                } else {
                    $stmt_update->bind_param("ssi", $punch_in_datetime, $location, $attendance_id);
                    
                    if ($stmt_update->execute()) {
                        $message = 'Attendance updated successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Error updating attendance: ' . $stmt_update->error;
                        $message_type = 'danger';
                    }
                    $stmt_update->close();
                }
            }
        } else {
            // Insert new record
            $status = 'Present';
            $stmt_insert = $conn->prepare("INSERT INTO attendance (user_id, date, punch_in, punch_out, punch_in_location, punch_out_location, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt_insert === false) {
                $message = 'Database error: ' . $conn->error;
                $message_type = 'danger';
            } else {
                $stmt_insert->bind_param("issssss", $employee_id, $punch_date, $punch_in_datetime, $punch_out_datetime, $location, $location, $status);

                if ($stmt_insert->execute()) {
                    $message = 'Attendance recorded successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error recording attendance: ' . $stmt_insert->error;
                    $message_type = 'danger';
                }
                $stmt_insert->close();
            }
        }
        $stmt_exist->close();
    }
    $stmt_check->close();
}

// Fetch employees
$employees_stmt = $conn->prepare("SELECT id, employee_id, name, department FROM users WHERE role = 'employee' AND status = 'Working' ORDER BY name");
$employees_stmt->execute();
$employees_result = $employees_stmt->get_result();
$employees = array();
while ($row = $employees_result->fetch_assoc()) {
    $employees[] = $row;
}
$employees_stmt->close();

// Fetch locations
$locations_stmt = $conn->query("SELECT id, name FROM locations ORDER BY name");
$locations = array();
if ($locations_stmt) {
    while ($row = $locations_stmt->fetch_assoc()) {
        $locations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Attendance - Admin</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.6.2/dist/sweetalert2.all.min.js"></script>
    <style>
        .form-card {
            border-left: 4px solid #0d6efd;
            transition: all 0.3s ease;
        }
        .form-card:hover {
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container mt-4">
    <!-- Form Card -->
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card form-card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-clock"></i> Record Manual Attendance</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="punch">

                        <!-- Employee Selection -->
                        <div class="form-group mb-4">
                            <label for="employee_id" class="form-label fw-bold">
                                <i class="fas fa-user"></i> Select Employee:
                            </label>
                            <select name="employee_id" id="employee_id" class="form-control form-control-lg" required>
                                <option value="">-- Select Employee --</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>">
                                        <?php echo htmlspecialchars($emp['employee_id'] . ' - ' . $emp['name'] . ' (' . $emp['department'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date Selection -->
                        <div class="form-group mb-4">
                            <label for="punch_date" class="form-label fw-bold">
                                <i class="fas fa-calendar"></i> Date:
                            </label>
                            <input type="date" name="punch_date" id="punch_date" class="form-control form-control-lg" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <!-- Punch Times -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="punch_in_time" class="form-label fw-bold">
                                        <i class="fas fa-arrow-down"></i> Punch In Time:
                                    </label>
                                    <input type="time" name="punch_in_time" id="punch_in_time" class="form-control form-control-lg" 
                                           value="<?php echo date('H:i'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="punch_out_time" class="form-label fw-bold">
                                        <i class="fas fa-arrow-up"></i> Punch Out Time <small class="text-muted">(Optional)</small>:
                                    </label>
                                    <input type="time" name="punch_out_time" id="punch_out_time" class="form-control form-control-lg">
                                </div>
                            </div>
                        </div>

                        <!-- Location Selection -->
                        <div class="form-group mb-4">
                            <label for="location" class="form-label fw-bold">
                                <i class="fas fa-map-marker-alt"></i> Location:
                            </label>
                            <select name="location" id="location" class="form-control form-control-lg" required>
                                <option value="">-- Select Location --</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo htmlspecialchars($loc['name']); ?>">
                                        <?php echo htmlspecialchars($loc['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Submit Attendance
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // SweetAlert for messages
    <?php if (!empty($message)): ?>
        Swal.fire({
            title: <?php echo ($message_type === 'success') ? "'Success!'" : "'Error!'"; ?>,
            text: <?php echo json_encode($message); ?>,
            icon: <?php echo ($message_type === 'success') ? "'success'" : "'error'"; ?>,
            confirmButtonColor: '<?php echo ($message_type === 'success') ? '#28a745' : '#dc3545'; ?>',
            confirmButtonText: 'OK'
        });
    <?php endif; ?>
</script>

</body>
</html>
