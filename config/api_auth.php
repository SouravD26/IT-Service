<?php
/**
 * Token-based authentication helper for JSON API endpoints (Flutter app).
 * Web pages keep using PHP sessions as before — this is additive, not a
 * replacement.
 *
 * Usage in an api/*.php endpoint:
 *   include '../config/db.php';
 *   include '../config/api_auth.php';
 *   $user = api_authenticate($conn); // sends 401 JSON + exits if invalid
 */

function api_generate_token(): string {
    return bin2hex(random_bytes(32)); // 64-char token
}

/**
 * Creates auth_tokens if it is missing, so token login works on a fresh
 * database without anyone remembering to run config/create_auth_tokens_table.php.
 */
function api_ensure_tokens_table(mysqli $conn): void {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS auth_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            device_info VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
}

/**
 * Validates the Bearer token from the Authorization header (or ?token=
 * query param, useful for quick testing) and returns the associated user
 * row. Sends a 401 JSON response and exits if missing/invalid/expired.
 */
function api_authenticate(mysqli $conn): array {
    api_ensure_tokens_table($conn);
    $token = null;

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0 && stripos($value, 'Bearer ') === 0) {
            $token = trim(substr($value, 7));
        }
    }
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $token = trim(substr($_SERVER['HTTP_AUTHORIZATION'], 7));
    }
    if (!$token && isset($_GET['token'])) {
        $token = trim($_GET['token']);
    }

    if (!$token) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Missing token']);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT u.id, u.name, u.email, u.phone, u.role, u.department, u.employee_id
         FROM auth_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW()"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }

    return $result->fetch_assoc();
}

/**
 * Backward-compatible auth: accepts EITHER a valid Bearer token (Flutter)
 * OR an existing PHP session (browser). Use this in endpoints that are
 * shared between the web app and the mobile app.
 */
function api_authenticate_flexible(mysqli $conn): array {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $hasBearer = false;
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0 && stripos($value, 'Bearer ') === 0) {
            $hasBearer = true;
        }
    }
    if (!$hasBearer && isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ') === 0) {
        $hasBearer = true;
    }

    if ($hasBearer || isset($_GET['token'])) {
        return api_authenticate($conn);
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'phone' => $_SESSION['phone'] ?? '',
            'role' => $_SESSION['role'] ?? '',
            'department' => $_SESSION['department'] ?? '',
            'employee_id' => $_SESSION['employee_id'] ?? '',
        ];
    }

    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}
