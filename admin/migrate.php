<?php
session_start();
include('../config/db.php');

// This script updates the database to support suparadmin role
// It adds 'suparadmin' to the role ENUM and changes existing admin to suparadmin

$messages = [];
$errors = [];

try {
    // Step 1: Alter the role column to add 'suparadmin'
    $alter_sql = "ALTER TABLE users MODIFY role ENUM('suparadmin', 'admin', 'employee') DEFAULT 'employee'";
    
    if ($conn->query($alter_sql)) {
        $messages[] = "✅ Updated role ENUM to include 'suparadmin'";
    } else {
        $errors[] = "❌ Could not update role ENUM: " . $conn->error;
    }
    
    // Step 2: Update existing admin user to suparadmin
    $update_sql = "UPDATE users SET role = 'suparadmin' WHERE phone = '9830376202' AND role = 'admin'";
    
    if ($conn->query($update_sql)) {
        $affected = $conn->affected_rows;
        if ($affected > 0) {
            $messages[] = "✅ Changed admin user (9830376202) to 'suparadmin' role";
        } else {
            $messages[] = "⚠️ No admin user found with phone 9830376202, or already suparadmin";
        }
    } else {
        $errors[] = "❌ Could not update user role: " . $conn->error;
    }
    
} catch (Exception $e) {
    $errors[] = "❌ Error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Migration</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 600px;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 30px;
        }
        .card-body {
            padding: 30px;
        }
        .alert {
            border-radius: 10px;
            margin-bottom: 15px;
            border: none;
        }
        .btn-container {
            text-align: center;
            margin-top: 30px;
        }
        .btn {
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0">🔧 Database Migration</h2>
            </div>
            <div class="card-body">
                <p class="text-muted mb-4">Setting up SuperAdmin role...</p>
                
                <?php if (!empty($messages)): ?>
                    <?php foreach ($messages as $msg): ?>
                        <div class="alert alert-success" role="alert">
                            <?php echo htmlspecialchars($msg); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (empty($errors)): ?>
                    <div class="alert alert-info">
                        <strong>✅ Migration Complete!</strong><br><br>
                        Your database is now ready. The admin user has been updated to 'suparadmin' role.<br><br>
                        <strong>Login Details:</strong><br>
                        📱 Phone: <code>9830376202</code><br>
                        🔑 Password: <code>admin123</code><br><br>
                        You will now see the <strong>SuperAdmin Dashboard</strong> with the "Manage Admin" option.
                    </div>
                    <div class="btn-container">
                        <a href="../auth/login.php" class="btn btn-primary">Go to Login</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        Please try again or contact support if the issue persists.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
