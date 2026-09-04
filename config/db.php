<?php
// Database Configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "attendance";

// Create connection.
//
// PHP 8.1+ makes mysqli throw on failure, so a bad host, wrong password or
// stopped server raises an exception here and the old $conn->connect_error
// check below it never runs - the visitor just gets a blank HTTP 500. Catching
// it keeps the real reason in the server log and puts a short, credential-free
// message on the page instead.
try {
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Still checked, for PHP builds configured not to throw
    if ($conn->connect_error) {
        throw new RuntimeException($conn->connect_error, (int)$conn->connect_errno);
    }
} catch (Throwable $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("The site cannot reach its database right now. Please try again shortly.\n");
}

// Set charset
$conn->set_charset("utf8");
