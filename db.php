<?php
// db.php — Connect to MySQL Database
date_default_timezone_set('UTC');

// Load .env file if it exists
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                [$key, $val] = explode('=', $line, 2);
                $key = trim($key);
                $val = trim($val);
                if (preg_match('/^([\'"])(.*)\1$/', $val, $m)) {
                    $val = $m[2];
                }
                if (getenv($key) === false) {
                    putenv("$key=$val");
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                }
            }
        }
    }
}

// Database configuration via environment variables or safe local defaults
$host = getenv('SPA_DB_HOST') ?: 'localhost';
$user = getenv('SPA_DB_USER') ?: 'root';
$envPass = getenv('SPA_DB_PASS');
$pass = $envPass === false ? '' : $envPass;
$dbname = getenv('SPA_DB_NAME') ?: 'spa_db';
$port = (int)(getenv('SPA_DB_PORT') ?: 3306);

// Create connection
$conn = new mysqli($host, $user, $pass, $dbname, $port);

// Check connection
if ($conn->connect_error) {
    error_log('Database connection failed');
    http_response_code(500);
    die('Database connection failed.');
}

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    unset($_SESSION['user']['password'], $_SESSION['user']['reset_token'], $_SESSION['user']['reset_expires']);
}

require_once __DIR__ . '/includes/money.php';
ensure_money_schema($conn);

require_once __DIR__ . '/includes/client_code.php';
ensure_client_code_schema($conn);
