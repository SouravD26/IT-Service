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
$search_query = '';
$employees = array();
$admin_id = $_SESSION['user_id'];

// Create table if doesn't exist
$result = $conn->query("SHOW TABLES LIKE 'comp_off_requests'");
if ($result->num_rows === 0) {
    $create_table = "CREATE TABLE IF NOT EXISTS comp_off_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        comp_off_date DATE NOT NULL,
        earned_date DATE,
        marked_by INT NOT NULL,
        marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_comp_off (user_id, comp_off_date)
    )";
    
    if (!$conn->query($create_table)) {
        $message = 'Error creating comp_off_requests table: ' . htmlspecialchars($conn->error);
        $message_type = 'danger';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'search') {
        $search_query = trim($_POST['search_name']);
        
        if (!empty($search_query)) {
            $search_term = '%' . $search_query . '%';
            $stmt = $conn->prepare("SELECT id, employee_id, name, department, location, company FROM users WHERE role = 'employee' AND status = 'Working' AND (name LIKE ? OR employee_id LIKE ?) ORDER BY name");
            $stmt->bind_param("ss", $search_term, $search_term);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $employees[] = $row;
            }
            $stmt->close();
        }
    } elseif ($_POST['action'] === 'mark_comp_off') {
        $user_id = intval($_POST['user_id']);
        $earned_date = $_POST['earned_date'];
        $comp_off_date = $_POST['comp_off_date'];

        // Validate employee exists
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
        $stmt_check->bind_param("i", $user_id);
        $stmt_check->execute();
        
        if ($stmt_check->get_result()->num_rows === 0) {
            $message = 'Invalid employee selected!';
            $message_type = 'danger';
        } else {
            // Check if comp off already exists for this date
            $stmt_exist = $conn->prepare("SELECT id FROM comp_off_requests WHERE user_id = ? AND comp_off_date = ?");
            $stmt_exist->bind_param("is", $user_id, $comp_off_date);
            $stmt_exist->execute();

            if ($stmt_exist->get_result()->num_rows > 0) {
                $message = 'Comp Off already marked for this date!';
                $message_type = 'warning';
            } else {
                // Insert new comp off
                $stmt_insert = $conn->prepare("INSERT INTO comp_off_requests (user_id, comp_off_date, earned_date, marked_by) VALUES (?, ?, ?, ?)");
                
                if ($stmt_insert === false) {
                    $message = 'Database error: ' . $conn->error;
                    $message_type = 'danger';
                } else {
                    $stmt_insert->bind_param("issi", $user_id, $comp_off_date, $earned_date, $admin_id);
                    
                    if ($stmt_insert->execute()) {
                        $message = 'Comp Off marked successfully!';
                        $message_type = 'success';
                    } else {
                        $message = 'Error: ' . $stmt_insert->error;
                        $message_type = 'danger';
                    }
                    $stmt_insert->close();
                }
            }
            $stmt_exist->close();
        }
        $stmt_check->close();
    } elseif ($_POST['action'] === 'delete_comp_off') {
        $comp_off_id = intval($_POST['comp_off_id']);
        
        $stmt_delete = $conn->prepare("DELETE FROM comp_off_requests WHERE id = ?");
        $stmt_delete->bind_param("i", $comp_off_id);
        
        if ($stmt_delete->execute()) {
            $message = 'Comp Off removed successfully!';
            $message_type = 'success';
        } else {
            $message = 'Error deleting: ' . $stmt_delete->error;
            $message_type = 'danger';
        }
        $stmt_delete->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comp Off Management</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.6.2/dist/sweetalert2.all.min.js"></script>
    <style>
        .employee-card {
            border-left: 4px solid #ffc107;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .employee-card:hover {
            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container-fluid mt-4">
    <?php if (!empty($message)): ?>
        <script>
            Swal.fire({
                title: <?php echo ($message_type === 'success') ? "'Success!'" : "'Error!'"; ?>,
                text: <?php echo json_encode($message); ?>,
                icon: <?php echo ($message_type === 'success') ? "'success'" : "'error'"; ?>,
                confirmButtonColor: '<?php echo ($message_type === 'success') ? '#28a745' : '#dc3545'; ?>',
                confirmButtonText: 'OK'
            });
        </script>
    <?php endif; ?>

    <div class="row">
        <!-- Search and Employee List -->
        <div class="col-md-5">
            <div class="card shadow mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-search"></i> Search Employee</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="search">
                        <div class="input-group mb-3">
                            <input type="text" name="search_name" class="form-control" placeholder="Search by name or ID" value="<?php echo htmlspecialchars($search_query); ?>" required>
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </form>

                    <!-- Employee Results -->
                    <div id="employeeList">
                        <?php if (!empty($employees)): ?>
                            <?php foreach ($employees as $emp): ?>
                                <div class="card employee-card mb-2" style="cursor: pointer; border-left: 4px solid #ffc107;" data-emp-id="<?php echo $emp['id']; ?>" data-emp-name="<?php echo htmlspecialchars($emp['name']); ?>">
                                    <div class="card-body py-2">
                                        <h6 class="mb-1">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($emp['name']); ?>
                                        </h6>
                                        <small class="text-muted">
                                            ID: <?php echo htmlspecialchars($emp['employee_id']); ?> | 
                                            Dept: <?php echo htmlspecialchars($emp['department']); ?> | 
                                            <?php echo htmlspecialchars($emp['company']); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($search_query): ?>
                            <div class="alert alert-info">No employees found.</div>
                        <?php else: ?>
                            <div class="alert alert-secondary">Search for an employee to get started.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comp Off Form -->
        <div class="col-md-7">
            <div class="card shadow">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="fas fa-calendar-plus"></i> Assign Comp Off</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="compOffForm">
                        <input type="hidden" name="action" value="mark_comp_off">

                        <!-- Selected Employee -->
                        <div class="form-group mb-4">
                            <label class="form-label fw-bold">Selected Employee:</label>
                            <div class="alert alert-light border" id="selectedEmployeeDiv">
                                <span class="text-muted">Click on an employee to select</span>
                            </div>
                            <input type="hidden" name="user_id" id="user_id">
                        </div>

                        <!-- Earned Date -->
                        <div class="form-group mb-4">
                            <label for="earned_date" class="form-label fw-bold">
                                <i class="fas fa-calendar-day"></i> Date When Worked (Earned Comp Off):
                            </label>
                            <input type="date" name="earned_date" id="earned_date" class="form-control form-control-lg" required>
                            <small class="text-muted">Date when employee worked extra / on their rest day</small>
                        </div>

                        <!-- Comp Off Date -->
                        <div class="form-group mb-4">
                            <label for="comp_off_date" class="form-label fw-bold">
                                <i class="fas fa-calendar-check"></i> Comp Off Date:
                            </label>
                            <input type="date" name="comp_off_date" id="comp_off_date" class="form-control form-control-lg" required>
                            <small class="text-muted">Date when employee will take the compensatory off</small>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg" id="submitBtn" disabled>
                                <i class="fas fa-save"></i> Mark Comp Off
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recent Comp Off Records -->
            <div class="card shadow mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Recent Comp Off Records</h5>
                </div>
                <div class="card-body">
                    <?php
                    $recent_stmt = $conn->prepare("
                        SELECT c.id, c.user_id, u.name, u.employee_id, c.earned_date, c.comp_off_date, c.marked_at
                        FROM comp_off_requests c 
                        JOIN users u ON c.user_id = u.id 
                        ORDER BY c.marked_at DESC 
                        LIMIT 10
                    ");
                    $recent_stmt->execute();
                    $recent_result = $recent_stmt->get_result();
                    
                    if ($recent_result->num_rows > 0):
                        while ($record = $recent_result->fetch_assoc()):
                    ?>
                        <div class="border-bottom pb-2 mb-2">
                            <div class="row">
                                <div class="col-md-5">
                                    <strong><?php echo htmlspecialchars($record['name']); ?></strong><br>
                                    <small class="text-muted">ID: <?php echo htmlspecialchars($record['employee_id']); ?></small>
                                </div>
                                <div class="col-md-3">
                                    <small><strong>Earned:</strong> <?php echo date('d-M', strtotime($record['earned_date'])); ?></small>
                                </div>
                                <div class="col-md-2">
                                    <small><strong>CO:</strong> <?php echo date('d-M', strtotime($record['comp_off_date'])); ?></small>
                                </div>
                                <div class="col-md-2 text-end">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this Comp Off?');">
                                        <input type="hidden" name="action" value="delete_comp_off">
                                        <input type="hidden" name="comp_off_id" value="<?php echo $record['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php
                        endwhile;
                    else:
                    ?>
                        <div class="text-center text-muted py-3">No comp off records yet.</div>
                    <?php
                    endif;
                    $recent_stmt->close();
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Initialize employee card click handlers
    document.addEventListener('DOMContentLoaded', function() {
        const employeeCards = document.querySelectorAll('.employee-card');
        console.log('Found ' + employeeCards.length + ' employee cards');
        
        employeeCards.forEach(card => {
            card.addEventListener('click', function() {
                const empId = this.getAttribute('data-emp-id');
                const empName = this.getAttribute('data-emp-name');
                console.log('Clicked on employee:', empId, empName);
                selectEmployee(empId, empName);
            });
        });
    });

    function selectEmployee(employeeId, employeeName) {
        console.log('Employee Selected:', employeeId, employeeName);
        
        // Set employee ID
        const userIdInput = document.getElementById('user_id');
        if (userIdInput) {
            userIdInput.value = employeeId;
            console.log('Set user_id to:', userIdInput.value);
        }
        
        // Update display text
        const selectedDiv = document.getElementById('selectedEmployeeDiv');
        if (selectedDiv) {
            selectedDiv.innerHTML = `<strong><i class="fas fa-check-circle text-success"></i> ${employeeName}</strong>`;
        }
        
        // Enable submit button
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
        }
        
        // Scroll to form
        const warningHeader = document.querySelector('.card-header.bg-warning');
        if (warningHeader) {
            warningHeader.scrollIntoView({ behavior: 'smooth' });
        }
    }
</script>

</body>
</html>

