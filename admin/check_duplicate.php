<?php
/**
 * Live duplicate check for the Add / Edit Employee forms.
 *
 * GET  field=phone|aadhar_number|pan_number|bank_account_number
 *      value=<what the admin typed>
 *      exclude_id=<employee being edited, optional>
 * ->   {"duplicate": true|false, "message": "..."}
 */
session_start();
include('../config/db.php');
require_once __DIR__ . '/employee_unique_check.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'suparadmin')) {
    http_response_code(403);
    echo json_encode(['duplicate' => false, 'message' => 'Not authorised']);
    exit();
}

$field      = $_GET['field'] ?? '';
$value      = trim($_GET['value'] ?? '');
$exclude_id = isset($_GET['exclude_id']) && $_GET['exclude_id'] !== '' ? (int)$_GET['exclude_id'] : null;

$fields = employee_unique_fields();
if (!isset($fields[$field])) {
    echo json_encode(['duplicate' => false, 'message' => '']);
    exit();
}

// The forms store these values htmlspecialchars()-encoded, so compare like for like
$clash = find_duplicate_employee($conn, $field, htmlspecialchars($value), $exclude_id);

echo json_encode([
    'duplicate' => (bool)$clash,
    'message'   => $clash
        ? $fields[$field] . ' already used by ' . $clash['name'] . ' (' . $clash['employee_id'] . ')'
        : '',
]);
