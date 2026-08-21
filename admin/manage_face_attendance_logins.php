<?php
session_start();
include('../config/db.php');

// Only SuperAdmin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'suparadmin') {
    header("Location: ../auth/login.php");
    exit();
}

// Auto-setup: Check if 'role' column exists, if not add it
$check_role = $conn->query("SHOW COLUMNS FROM users LIKE 'role'");
if ($check_role->num_rows === 0) {
    // Add role column
    $conn->query("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'user' AFTER password");
}

// Get admin name
$admin_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin_user = $result->fetch_assoc();
$admin_name = $admin_user['name'] ?? 'Admin';
$stmt->close();

$message = "";
$message_type = "";

// Show success on page reload
if (isset($_GET['created'])) {
    $message = "✅ Face attendance login created successfully!";
    $message_type = "success";
} elseif (isset($_GET['updated'])) {
    $message = "✅ Face attendance login updated successfully!";
    $message_type = "success";
}

// Update or create the SINGLE face attendance login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_login'])) {
    $phone = trim(htmlspecialchars($_POST['phone']));
    $password = $_POST['password'];
    
    if (empty($phone) || empty($password)) {
        $message = "Phone and password are required";
        $message_type = "danger";
    } else {
        // Check if face_attendance user already exists
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'face_attendance'");
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_stmt->close();
        
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        if ($check_result->num_rows > 0) {
            // Update existing
            $existing = $check_result->fetch_assoc();
            $update_stmt = $conn->prepare("UPDATE users SET phone = ?, password = ? WHERE role = 'face_attendance'");
            $update_stmt->bind_param("ss", $phone, $hashed_password);
            
            if ($update_stmt->execute()) {
                $message = "Face attendance login updated successfully!";
                $message_type = "success";
                // Redirect to refresh page
                header("Location: manage_face_attendance_logins.php?updated=1");
                exit;
            } else {
                $message = "Error updating login: " . $update_stmt->error;
                $message_type = "danger";
            }
            $update_stmt->close();
        } else {
            // Create new (first time) - SIMPLE
            $unique_email = 'face_attendance_' . time() . '@internal.local';
            $insert_stmt = $conn->prepare("INSERT INTO users (name, phone, password, email, role) VALUES (?, ?, ?, ?, 'face_attendance')");
            $insert_stmt->bind_param("ssss", $phone, $phone, $hashed_password, $unique_email);
            
            if ($insert_stmt->execute()) {
                $insert_stmt->close();
                sleep(1);
                // Simple: just redirect and let page reload show it
                header("Location: manage_face_attendance_logins.php?created=1");
                exit;
            } else {
                $message = "Error: " . $insert_stmt->error;
                $message_type = "danger";
                $insert_stmt->close();
            }
        }
    }
}

// Fetch the SINGLE face attendance login
$login_stmt = $conn->prepare("SELECT id, phone, created_at FROM users WHERE role = 'face_attendance' LIMIT 1");
$login_stmt->execute();
$login_result = $login_stmt->get_result();
$existing_login = $login_result->num_rows > 0 ? $login_result->fetch_assoc() : null;
$login_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Face Attendance Logins</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .navbar-custom {
            position: relative;
        }
        .navbar-logo {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            height: 50px;
            z-index: 10;
        }
        .navbar-logo img {
            height: 100%;
            width: auto;
            max-width: 200px;
        }
        .navbar-welcome {
            position: absolute;
            left: 20px;
            display: flex;
            align-items: center;
            height: 100%;
            color: white;
        }
        .navbar-logout {
            margin-left: auto;
            padding-right: 20px;
        }
        .card {
            border-left: 5px solid #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .table-hover tbody tr:hover {
            background-color: #f8f9fa;
        }
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>

<?php include('_navbar.php'); ?>

<div class="container mt-5 mb-5">
    <div class="row">
        <div class="col-md-12">
            <h2 class="mb-4">🎭 Manage Face Attendance Logins</h2>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

                <!-- Setup Notice -->
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <h6><i class="fas fa-info-circle"></i> First Time Setup</h6>
                    <p class="mb-2">If you encounter any database issues, run the setup script to ensure all required columns exist:</p>
                    <a href="../config/setup_face_attendance.php" class="btn btn-sm btn-info" target="_blank">
                        <i class="fas fa-cog"></i> Run Setup Now
                    </a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                        <i class="fas fa-info-circle"></i> 
                        <strong>One Login for All Employees:</strong> Enter a phone number and password. 
                        ALL employees will use these same credentials to access the face attendance dashboard, 
                        then select their name to punch in/out.
                    </div>

                    <form method="POST" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" class="form-control" name="phone" 
                                   placeholder="e.g., 9876543210" 
                                   value="<?php echo $existing_login ? htmlspecialchars($existing_login['phone']) : ''; ?>" 
                                   required>
                            <small class="text-muted">This is what all employees use to login</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password" 
                                   placeholder="Enter password" 
                                   required>
                            <small class="text-muted">This is what all employees use to login</small>
                        </div>
                        <div class="col-md-12">
                            <button type="submit" name="save_login" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> 
                                <?php echo $existing_login ? 'Update Login' : 'Create Login'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Current Status Card -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-check-circle"></i> Current Status</h5>
                </div>
                <div class="card-body">
                    <?php if ($existing_login): ?>
                        <div class="alert alert-success">
                            <strong>✅ Face Attendance Login is ACTIVE</strong>
                        </div>
                        <table class="table table-sm">
                            <tr>
                                <th>Mobile Number</th>
                                <td><strong><?php echo htmlspecialchars($existing_login['phone']); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Created</th>
                                <td><?php echo date('d-M-Y h:i A', strtotime($existing_login['created_at'])); ?></td>
                            </tr>
                            <tr>
                                <th>Access URL</th>
                                <td><code><?php echo 'http://' . ($_SERVER['HTTP_HOST'] ?? 'yourserver') . '/attendence/auth/login.php'; ?></code></td>
                            </tr>
                        </table>
                        <p class="mt-3 text-muted">
                            <i class="fas fa-users"></i> All employees can use these credentials to access the face attendance dashboard.
                        </p>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <strong>⚠️ No Face Attendance Login Created Yet</strong><br>
                            Create one above to activate face attendance for all employees.
                            <?php if ($fa_count > 0): ?>
                            <br><small class="text-danger mt-2">Debug: Found <?php echo $fa_count; ?> record(s) but query returned no results. Try refreshing the page.</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Instructions Card -->
            <div class="card mt-4 border-info">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-instructions"></i> How It Works</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>Create a <strong>single phone number</strong> and <strong>password</strong> above (e.g., "Kiosk1" / "password123")</li>
                        <li>Share these credentials with all employees</li>
                        <li>Employees go to: <code><?php echo 'http://' . ($_SERVER['HTTP_HOST'] ?? 'yourserver') . '/attendence/auth/login.php'; ?></code></li>
                        <li>All employees login with the SAME phone/password</li>
                        <li>Dashboard loads → Employee selects their name from list</li>
                        <li>Employee clicks "Punch In" or "Punch Out"</li>
                        <li>Camera captures face and records attendance ✅</li>
                    </ol>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
