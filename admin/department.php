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

// Add columns if they don't exist
$check_col = $conn->query("SHOW COLUMNS FROM users LIKE 'department'");
if ($check_col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN department VARCHAR(100)");
}

// Create departments table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS departments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle Add Department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
    $dept_name = htmlspecialchars(trim($_POST['dept_name']));
    
    if (empty($dept_name)) {
        $message = "Project name is required";
        $message_type = "danger";
    } else {
        // Check if department already exists
        $stmt_check = $conn->prepare("SELECT id FROM departments WHERE name = ?");
        $stmt_check->bind_param("s", $dept_name);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            $message = "Project already exists";
            $message_type = "danger";
        } else {
            // Insert department
            $stmt_insert = $conn->prepare("INSERT INTO departments (name) VALUES (?)");
            $stmt_insert->bind_param("s", $dept_name);

            if ($stmt_insert->execute()) {
                $message = "✓ Project added successfully!";
                $message_type = "success";
            } else {
                $message = "Error: " . $stmt_insert->error;
                $message_type = "danger";
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}

// Handle Update Department
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_department'])) {
    $old_dept = htmlspecialchars(trim($_POST['old_dept']));
    $new_dept = htmlspecialchars(trim($_POST['new_dept']));
    
    if (empty($new_dept)) {
        $message = "Project name is required";
        $message_type = "danger";
    } else {
        // Update department name in departments table
        $stmt_update = $conn->prepare("UPDATE departments SET name = ? WHERE name = ?");
        $stmt_update->bind_param("ss", $new_dept, $old_dept);

        if ($stmt_update->execute()) {
            // Also update users with this department
            $stmt_users = $conn->prepare("UPDATE users SET department = ? WHERE department = ?");
            $stmt_users->bind_param("ss", $new_dept, $old_dept);
            $stmt_users->execute();
            $stmt_users->close();

            $message = "✓ Project updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error: " . $stmt_update->error;
            $message_type = "danger";
        }
        $stmt_update->close();
    }
}

// Handle Delete Department
if (isset($_GET['delete_department'])) {
    $dept_to_delete = htmlspecialchars($_GET['delete_department']);
    
    // Check if any users have this department
    $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE department = ?");
    $stmt_check->bind_param("s", $dept_to_delete);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $row = $result->fetch_assoc();
    $stmt_check->close();
    
    if ($row['count'] > 0) {
        $message = "Cannot delete! " . $row['count'] . " user(s) assigned to this project. Please reassign them first.";
        $message_type = "warning";
    } else {
        // Delete from departments table
        $stmt_delete = $conn->prepare("DELETE FROM departments WHERE name = ?");
        $stmt_delete->bind_param("s", $dept_to_delete);

        if ($stmt_delete->execute()) {
            $message = "✓ Project deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error: " . $stmt_delete->error;
            $message_type = "danger";
        }
        $stmt_delete->close();
    }
}

// Fetch all unique departments
$stmt = $conn->prepare("SELECT name FROM departments ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
$departments = [];
while ($row = $result->fetch_assoc()) {
    $departments[] = $row['name'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Management</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container mt-5">
    <div class="row">
        <div class="col-md-4">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">➕ Add Project</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Project Name</label>
                            <input type="text" name="dept_name" class="form-control" placeholder="Enter project name" required>
                        </div>
                        <button type="submit" name="add_department" class="btn btn-success w-100">Add Project</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">📋 All Projects</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($departments)) : ?>
                        <p class="text-muted">No projects found. Add one to get started!</p>
                    <?php else : ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Project Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($departments as $dept) : ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($dept); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editModal" 
                                                    onclick="editDept('<?php echo htmlspecialchars(addslashes($dept)); ?>')">
                                                    ✏️ Edit
                                                </button>
                                                <a href="?delete_department=<?php echo urlencode($dept); ?>" class="btn btn-sm btn-danger" 
                                                    onclick="confirmDelete(event, '<?php echo htmlspecialchars(addslashes($dept)); ?>')">
                                                    🗑️ Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Project</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="old_dept" id="old_dept">
                    <div class="mb-3">
                        <label class="form-label">New Project Name</label>
                        <input type="text" name="new_dept" id="new_dept" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_department" class="btn btn-warning">Update Project</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editDept(dept) {
    document.getElementById('old_dept').value = dept;
    document.getElementById('new_dept').value = dept;
}

function confirmDelete(event, dept) {
    event.preventDefault();
    Swal.fire({
        icon: 'warning',
        title: 'Delete Project?',
        text: 'Are you sure you want to delete "' + dept + '"?',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Delete',
        showCancelButton: true
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '?delete_department=' + encodeURIComponent(dept);
        }
    });
}

<?php if (!empty($message)) : ?>
    Swal.fire({
        icon: '<?php echo $message_type; ?>',
        title: '<?php echo $message_type === 'success' ? 'Success' : ($message_type === 'danger' ? 'Error' : 'Warning'); ?>',
        text: '<?php echo addslashes($message); ?>',
        confirmButtonColor: '#3085d6'
    });
<?php endif; ?>
</script>

</body>
</html>

