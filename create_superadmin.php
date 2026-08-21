<?php
/**
 * Create SuperAdmin User
 */

include('config/db.php');

echo "🔐 CREATING SUPERADMIN USER\n";
echo str_repeat("=", 60) . "\n\n";

$phone = '9748302601';
$password = 'admin123';
$name = 'Super Admin';
$email = 'superadmin@company.com';

// Hash password using bcrypt
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

echo "📝 User Details:\n";
echo "  Phone: $phone\n";
echo "  Password: $password (entered)\n";
echo "  Hashed: " . substr($hashed_password, 0, 30) . "...\n";
echo "  Role: suparadmin\n\n";

// Insert user
$sql = "INSERT INTO users (name, email, password, role, phone) 
        VALUES (?, ?, ?, 'suparadmin', ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("❌ Prepare failed: " . $conn->error);
}

$stmt->bind_param("ssss", $name, $email, $hashed_password, $phone);

if ($stmt->execute()) {
    echo "✅ SuperAdmin user created successfully!\n\n";
    
    // Verify
    $verify = $conn->query("SELECT id, name, email, role, phone FROM users WHERE phone='$phone'");
    if ($verify && $row = $verify->fetch_assoc()) {
        echo "🔍 Verification:\n";
        echo "  ID: " . $row['id'] . "\n";
        echo "  Name: " . $row['name'] . "\n";
        echo "  Email: " . $row['email'] . "\n";
        echo "  Phone: " . $row['phone'] . "\n";
        echo "  Role: " . $row['role'] . "\n";
    }
} else {
    echo "❌ Error: " . $stmt->error . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ User ready to login!\n";
echo "\n📱 LOGIN CREDENTIALS:\n";
echo "  URL: http://localhost/attendence\n";
echo "  Phone: 9748302601\n";
echo "  Password: admin123\n";

?>
