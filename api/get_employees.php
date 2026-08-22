<?php
include('../config/db.php');
include('../config/api_auth.php');

// Accepts either a browser session (web app) or a Bearer token (Flutter app)
$authUser = api_authenticate_flexible($conn);

// Fetch all employees (simple list - no photos)
$employees = [];
$stmt = $conn->prepare("SELECT id, name, employee_id FROM users WHERE role = 'employee' ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $employees[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'employee_id' => $row['employee_id'] ?? 'N/A'
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'employees' => $employees,
    'count' => count($employees)
]);
?>
