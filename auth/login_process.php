<?php
session_start();
include "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['phone']) || !isset($_POST['password'])) {
    header("Location: login.php?error=invalid_request");
    exit();
}

// Sanitize input
$phone = trim(htmlspecialchars($_POST['phone']));
$password = $_POST['password'];

// Validate phone format (basic validation)
if (empty($phone) || strlen($phone) < 10) {
    header("Location: login.php?error=invalid_phone");
    exit();
}

// Use prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT id, phone, password, role FROM users WHERE phone = ?");

if (!$stmt) {
    header("Location: login.php?error=database_error");
    exit();
}

$stmt->bind_param("s", $phone);

if (!$stmt->execute()) {
    header("Location: login.php?error=database_error");
    exit();
}

$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    
    // Verify password
    $password_check = password_verify($password, $user['password']);
    
    if ($password_check) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['phone'] = $user['phone'];
        $_SESSION['role'] = $user['role'];
        
        // Get full user info for session
        $user_info = $conn->prepare("SELECT id, name, email, role, department, employee_id FROM users WHERE id = ?");
        $user_info->bind_param("i", $user['id']);
        $user_info->execute();
        $full_user = $user_info->get_result()->fetch_assoc();
        
        // Set additional session variables
        $_SESSION['user_name'] = $full_user['name'] ?? '';
        $_SESSION['email'] = $full_user['email'] ?? '';
        $_SESSION['department'] = $full_user['department'] ?? '';
        $_SESSION['employee_id'] = $full_user['employee_id'] ?? '';
        
        // Redirect based on role
        if ($user['role'] === 'suparadmin') {
            header("Location: ../admin/dashboard.php");
        } elseif ($user['role'] === 'admin') {
            header("Location: ../admin/admin_dashboard.php");
        } elseif ($user['role'] === 'supervisor') {
            header("Location: ../supervisor/dashboard.php");
        } elseif ($user['role'] === 'employee') {
            header("Location: ../employee/dashboard.php");
        } elseif ($user['role'] === 'face_operator') {
            header("Location: ../face_dashboard.php");
        } else {
            header("Location: ../employee/dashboard.php");
        }
        exit();
    } else {
        error_log("Login failed - Phone: $phone, Role: {$user['role']}");
        header("Location: login.php?error=invalid_password");
        exit();
    }
} else {
    header("Location: login.php?error=user_not_found");
    exit();
}

$stmt->close();
