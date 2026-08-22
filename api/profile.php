<?php
/**
 * My Profile API for the Flutter app.
 * GET -> the logged-in user's own profile.
 */
header('Content-Type: application/json');
require_once '../config/db.php';
require_once '../config/api_auth.php';

$authUser = api_authenticate_flexible($conn);

$stmt = $conn->prepare("SELECT id, name, employee_id, phone, email, role, department, date_of_joining, week_off, geo_restricted, status FROM users WHERE id = ?");
$stmt->bind_param("i", $authUser['id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

echo json_encode(['success' => true, 'user' => $user]);
