<?php
include('../config/db.php');

$message = '';
$error = '';
$phone_display = '';
$password_display = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $old_password = trim($_POST['old_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    // Validate input
    if (empty($phone) || empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required!";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match!";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters!";
    } else {
        // Check if user exists and get their current password
        $check = $conn->prepare("SELECT id, password FROM users WHERE phone = ?");
        $check->bind_param("s", $phone);
        $check->execute();
        $check_result = $check->get_result();
        
        if ($check_result->num_rows > 0) {
            $user = $check_result->fetch_assoc();
            // Verify old password
            if (!password_verify($old_password, $user['password'])) {
                $error = "❌ Old password is incorrect!";
            } else {
                // Hash the new password
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                
                // Update password
                $update = $conn->prepare("UPDATE users SET password = ? WHERE phone = ?");
                $update->bind_param("ss", $hashed_password, $phone);
                
                if ($update->execute()) {
                    $message = "✅ Password updated successfully!";
                    $phone_display = $phone;
                    $password_display = $new_password;
                } else {
                    $error = "❌ Failed to update password: " . $conn->error;
                }
                $update->close();
            }
        } else {
            $error = "❌ User not found with phone: $phone";
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 30px;
            text-align: center;
        }
        .card-body {
            padding: 40px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
        }
        .form-control {
            border-radius: 8px;
            padding: 12px;
            border: 1px solid #ddd;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .password-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }
        .password-input-group .form-control {
            padding-right: 45px;
        }
        .toggle-password-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toggle-password-btn:hover {
            color: #764ba2;
        }
        .btn {
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
            width: 100%;
        }
        .alert {
            border-radius: 8px;
            border: none;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h3 class="mb-0">🔐 Reset Password</h3>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="form-group">
                    <label for="phone" class="form-label">📱 Phone Number:</label>
                    <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g., 9830376200" required>
                    <small class="text-muted">Enter the user's phone number</small>
                </div>
                
                <div class="form-group">
                    <label for="old_password" class="form-label">🔒 Current Password:</label>
                    <div class="password-input-group">
                        <input type="password" class="form-control" id="old_password" name="old_password" placeholder="Enter current password" required>
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordField('old_password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <small class="text-muted">Required for security verification</small>
                </div>
                
                <div class="form-group">
                    <label for="new_password" class="form-label">🔑 New Password:</label>
                    <div class="password-input-group">
                        <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Enter new password" required>
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordField('new_password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <small class="text-muted">Minimum 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">✓ Confirm Password:</label>
                    <div class="password-input-group">
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                        <button type="button" class="toggle-password-btn" onclick="togglePasswordField('confirm_password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    Reset Password
                </button>
            </form>
            
            <div class="mt-3 text-center">
                <p class="text-muted mb-2">Already reset your password?</p>
                <a href="../auth/login.php" class="btn btn-outline-secondary w-100">
                    🔓 Go to Login Page
                </a>
            </div>
            
            <hr class="my-4">
            
            <!-- <div class="alert alert-info">
                <strong>Example:</strong><br>
                Phone: <code>9830376200</code><br>
                New Password: <code>newpassword123</code>
            </div> -->
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>
    <script>
        function togglePasswordField(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = event.target.closest('.toggle-password-btn');
            const icon = btn.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
    
    <?php if (!empty($message) && !empty($phone_display)): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'Password Reset Successful!',
            html: `
                <p style="font-size: 16px; margin: 15px 0;"><strong>Your credentials:</strong></p>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: left;">
                    <p style="margin: 10px 0;"><strong>📱 Phone:</strong> <code><?php echo htmlspecialchars($phone_display); ?></code></p>
                    <p style="margin: 10px 0;"><strong>🔑 Password:</strong> <code><?php echo htmlspecialchars($password_display); ?></code></p>
                </div>
            `,
            confirmButtonColor: '#667eea',
            confirmButtonText: 'Go to Login',
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../auth/login.php';
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
