<?php
/**
 * JSON login endpoint for the Flutter app.
 * Mirrors login_process.php's logic but returns a Bearer token instead
 * of a redirect + PHP session, since a mobile app can't rely on cookies.
 *
 * POST phone, password
 * -> { success, token, user: {...} }
 */
header('Content-Type: application/json');
include '../config/db.php';
include '../config/api_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$phone = trim($_POST['phone'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($phone) || strlen($phone) < 10 || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid phone or password']);
    exit;
}

$stmt = $conn->prepare("SELECT id, phone, password, role, name, email, department, employee_id, location FROM users WHERE phone = ?");
$stmt->bind_param("s", $phone);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid phone or password']);
    exit;
}

// Issue a token valid for 30 days
api_ensure_tokens_table($conn);
$token = api_generate_token();
$device_info = trim($_POST['device_info'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));

$insert = $conn->prepare("INSERT INTO auth_tokens (user_id, token, device_info, created_at, expires_at) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))");
$insert->bind_param("iss", $user['id'], $token, $device_info);
$insert->execute();

unset($user['password']);

echo json_encode([
    'success' => true,
    'token' => $token,
    'user' => $user
]);
