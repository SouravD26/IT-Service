<?php
/**
 * One-time migration: creates the auth_tokens table used for
 * token-based login (Flutter / mobile app), alongside the existing
 * session-based login used by the browser.
 *
 * Run this once by visiting it in the browser, then you can delete it.
 */
include __DIR__ . '/db.php';

$sql = "CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    device_info VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB";

if ($conn->query($sql) === TRUE) {
    echo "auth_tokens table ready.";
} else {
    echo "Error creating table: " . $conn->error;
}
