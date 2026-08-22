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

// Photo is stored on disk as uploads/employee_photos/{id}.{ext} (same lookup
// employee/dashboard.php uses) — not a DB column, so build a full URL here.
$photos = glob(__DIR__ . '/../uploads/employee_photos/' . $user['id'] . '.*');
$user['photo_url'] = null;
if (!empty($photos)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $user['photo_url'] = $scheme . '://' . $host . '/uploads/employee_photos/' . basename($photos[0]);
}

echo json_encode(['success' => true, 'user' => $user]);
