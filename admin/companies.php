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

// Create companies table if it doesn't exist
$conn->query("CREATE TABLE IF NOT EXISTS companies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle Add Company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $company_name = htmlspecialchars(trim($_POST['company_name']));
    
    if (empty($company_name)) {
        $message = "Company name cannot be empty";
        $message_type = "danger";
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM companies WHERE name = ?");
        $stmt_check->bind_param("s", $company_name);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $message = "This company already exists";
            $message_type = "warning";
        } else {
            $stmt_insert = $conn->prepare("INSERT INTO companies (name) VALUES (?)");
            $stmt_insert->bind_param("s", $company_name);
            
            if ($stmt_insert->execute()) {
                $message = "✓ Company added successfully!";
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

// Handle Update Company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
    $company_id = (int)$_POST['company_id'];
    $new_company_name = htmlspecialchars(trim($_POST['company_name']));
    
    if (empty($new_company_name)) {
        $message = "Company name cannot be empty";
        $message_type = "danger";
    } else {
        // Check if new name already exists (excluding the current company)
        $stmt_check = $conn->prepare("SELECT id FROM companies WHERE name = ? AND id != ?");
        $stmt_check->bind_param("si", $new_company_name, $company_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $message = "This company name already exists";
            $message_type = "warning";
        } else {
            $stmt_update = $conn->prepare("UPDATE companies SET name = ? WHERE id = ?");
            $stmt_update->bind_param("si", $new_company_name, $company_id);
            
            if ($stmt_update->execute()) {
                $message = "✓ Company updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error: " . $stmt_update->error;
                $message_type = "danger";
            }
            $stmt_update->close();
        }
        $stmt_check->close();
    }
}

// Handle Delete Company
if (isset($_GET['delete_company'])) {
    $company_id = (int)$_GET['delete_company'];
    
    // Check if company is being used by any employee
    $stmt_check = $conn->prepare("SELECT id FROM users WHERE company = (SELECT name FROM companies WHERE id = ?) LIMIT 1");
    $stmt_check->bind_param("i", $company_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows > 0) {
        $message = "Cannot delete company that is assigned to employees";
        $message_type = "danger";
    } else {
        $stmt_delete = $conn->prepare("DELETE FROM companies WHERE id = ?");
        $stmt_delete->bind_param("i", $company_id);
        
        if ($stmt_delete->execute()) {
            $message = "✓ Company deleted successfully!";
            $message_type = "success";
        } else {
            $message = "✗ Error: " . $stmt_delete->error;
            $message_type = "danger";
        }
        $stmt_delete->close();
    }
    $stmt_check->close();
}

// Fetch all companies
$stmt = $conn->prepare("SELECT id, name, created_at FROM companies ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Management - Attendance System</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-5">
        <!-- Add Company Form -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">➕ Add New Company</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Company Name</label>
                        <input type="text" name="company_name" class="form-control" placeholder="Enter company name" required>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" name="add_company" class="btn btn-success w-100">✓ Add Company</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Companies List -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">All Companies</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>Company Name</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            if ($result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    echo "<tr>";
                                    echo "<td>" . $row['id'] . "</td>";
                                    echo "<td><strong>" . htmlspecialchars($row['name']) . "</strong></td>";
                                    echo "<td>" . date('d-m-Y H:i', strtotime($row['created_at'])) . "</td>";
                                    echo "<td>";
                                    echo "<button type='button' class='btn btn-sm btn-warning' onclick=\"openEditModal(" . $row['id'] . ", '" . htmlspecialchars($row['name']) . "')\">✎ Edit</button> ";
                                    echo "<button type='button' onclick=\"confirmDeleteCompany(" . $row['id'] . ", '" . htmlspecialchars($row['name']) . "')\" class='btn btn-sm btn-danger'>Delete</button>";
                                    echo "</td>";
                                    echo "</tr>";
                                }
                            } else {
                                echo "<tr><td colspan='4' class='text-center text-muted'>No companies found. Add one to get started!</td></tr>";
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

    <!-- Edit Company Modal -->
    <div class="modal fade" id="editCompanyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title">✎ Edit Company</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editCompanyForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="update_company" value="1">
                        <input type="hidden" name="company_id" id="editCompanyId">
                        <div class="mb-3">
                            <label for="editCompanyName" class="form-label">Company Name</label>
                            <input type="text" class="form-control" id="editCompanyName" name="company_name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">✓ Update Company</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openEditModal(companyId, companyName) {
            document.getElementById('editCompanyId').value = companyId;
            document.getElementById('editCompanyName').value = companyName;
            const modal = new bootstrap.Modal(document.getElementById('editCompanyModal'));
            modal.show();
        }

        function confirmDeleteCompany(companyId, companyName) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'Delete company: ' + companyName,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_company=' + companyId;
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

