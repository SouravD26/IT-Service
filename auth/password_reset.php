<?php
include "../config/db.php";

// Reset admin password
$phone = '9748302601';
$new_password = 'admin123';
$hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE phone = ?");
$stmt->bind_param("ss", $hashed_password, $phone);

if ($stmt->execute()) {
    echo "✓ Admin password reset successfully!<br>";
    echo "Phone: " . htmlspecialchars($phone) . "<br>";
    echo "Password: " . htmlspecialchars($new_password) . "<br>";
    echo "Hash: " . htmlspecialchars($hashed_password) . "<br><br>";
    echo "<strong>You can now login with these credentials</strong>";
} else {
    echo "✗ Error: " . $stmt->error;
}

$stmt->close();

// Also reset employees
$phones = ['9830376201', '9830376203'];
foreach ($phones as $emp_phone) {
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE phone = ?");
    $stmt->bind_param("ss", $hashed_password, $emp_phone);
    $stmt->execute();
    $stmt->close();
}

echo "<br><br>";
echo "✓ All passwords reset to 'admin123'";
?>
