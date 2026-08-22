<?php
/**
 * Revokes the Bearer token sent by the Flutter app (call on user logout).
 */
header('Content-Type: application/json');
include '../config/db.php';

$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = null;
foreach ($headers as $name => $value) {
    if (strcasecmp($name, 'Authorization') === 0 && stripos($value, 'Bearer ') === 0) {
        $token = trim(substr($value, 7));
    }
}
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
    $token = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
}

if (!$token) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing token']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM auth_tokens WHERE token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();

echo json_encode(['success' => true]);
