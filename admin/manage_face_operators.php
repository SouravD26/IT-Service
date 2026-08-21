<?php
session_start();
include('../config/db.php');

// SuperAdmin and Admin can access
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'suparadmin' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../auth/login.php");
    exit();
}

$message = '';
$message_type = '';

// Create face operator user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_operator'])) {
    $name = trim(htmlspecialchars($_POST['name']));
    $phone = trim(htmlspecialchars($_POST['phone']));
    $password = $_POST['password'];
    
    if (empty($name) || empty($phone) || empty($password)) {
        $message = "All fields required";
        $message_type = "danger";
    } else {
        // Check if phone already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE phone = ?");
        $check_stmt->bind_param("s", $phone);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $message = "Phone number already exists";
            $message_type = "warning";
        } else {
            // Create new operator
            $unique_email = 'operator_' . time() . '@local';
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            
            $insert_stmt = $conn->prepare("INSERT INTO users (name, phone, password, email, role) VALUES (?, ?, ?, ?, 'face_operator')");
            $insert_stmt->bind_param("ssss", $name, $phone, $hashed_password, $unique_email);
            
            if ($insert_stmt->execute()) {
                $message = "✅ Face operator created! Phone: $phone";
                $message_type = "success";
            } else {
                $message = "Error: " . $insert_stmt->error;
                $message_type = "danger";
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}

// Fetch all operators
$operators_stmt = $conn->prepare("SELECT id, name, phone, created_at FROM users WHERE role = 'face_operator' ORDER BY created_at DESC");
$operators_stmt->execute();
$operators = $operators_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$operators_stmt->close();

// Change password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $op_id = intval($_POST['operator_id']);
    $new_password = $_POST['new_password'];
    
    if (empty($new_password)) {
        $message = "Password cannot be empty";
        $message_type = "danger";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'face_operator'");
        $update_stmt->bind_param("si", $hashed_password, $op_id);
        
        if ($update_stmt->execute()) {
            $message = "✅ Password updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating password: " . $update_stmt->error;
            $message_type = "danger";
        }
        $update_stmt->close();
    }
}

// Delete operator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_operator'])) {
    $op_id = intval($_POST['operator_id']);
    $delete_stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'face_operator'");
    $delete_stmt->bind_param("i", $op_id);
    if ($delete_stmt->execute()) {
        $message = "Operator deleted";
        $message_type = "success";
        header("Location: manage_face_operators.php?deleted=1");
        exit;
    }
    $delete_stmt->close();
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Face Operators</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f5f5f5; }
        .navbar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .card { box-shadow: 0 2px 10px rgba(0,0,0,0.1); border: none; }
        .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    </style>
</head>
<body>
    <?php include('_navbar.php'); ?>

    <div class="container mt-4 mb-5">
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible">
                <?php echo $message; ?>
                <button class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Create New Operator -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-user-plus"></i> Create New Face Operator</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Operator Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g., Main Hall Operator" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="e.g., 9876543210" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="create_operator" class="btn btn-primary btn-lg">
                            <i class="fas fa-plus"></i> Create Operator
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- List of Operators -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Existing Face Operators (<?php echo count($operators); ?>)</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operators as $idx => $op): ?>
                        <tr>
                            <td><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($op['name']); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($op['phone']); ?></code></td>
                            <td><?php echo date('d-M-Y', strtotime($op['created_at'])); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#passwordModal<?php echo $op['id']; ?>">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="operator_id" value="<?php echo $op['id']; ?>">
                                    <button type="submit" name="delete_operator" class="btn btn-sm btn-danger" onclick="return confirm('Delete this operator?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (count($operators) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No operators created yet</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Password Change Modals -->
        <?php foreach ($operators as $op): ?>
        <div class="modal fade" id="passwordModal<?php echo $op['id']; ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-key"></i> Change Password</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <p><strong>Operator:</strong> <?php echo htmlspecialchars($op['name']); ?></p>
                            <p><strong>Phone:</strong> <?php echo htmlspecialchars($op['phone']); ?></p>
                            <div class="mb-3">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" placeholder="Enter new password" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <input type="hidden" name="operator_id" value="<?php echo $op['id']; ?>">
                            <button type="submit" name="change_password" class="btn btn-warning">
                                <i class="fas fa-save"></i> Update Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="alert alert-info mt-4">
            <h6><i class="fas fa-info-circle"></i> How to Use:</h6>
            <ol class="mb-0 mt-2">
                <li>Create a new face operator above</li>
                <li>Operator logs in at: <code><?php echo 'http://' . ($_SERVER['HTTP_HOST'] ?? 'yourserver') . '/attendence/face_operator_login.php'; ?></code></li>
                <li>Operator uses the Face Dashboard to add employee photos and record attendance</li>
            </ol>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
