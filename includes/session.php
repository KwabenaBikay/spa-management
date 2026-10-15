<?php
require_once __DIR__ . '/headers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Session Idle Timeout — 30 minutes (1800 seconds)
if (isset($_SESSION['user'])) {
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - (int)$_SESSION['last_activity'] > 1800)) {
        unset($_SESSION['user'], $_SESSION['csrf'], $_SESSION['last_activity']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_contains($script, '/api/')) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Session timed out due to inactivity. Please log in again.']);
        } else {
            $prefix = (str_contains($script, '/admin/') || str_contains($script, '/frontdesk/') || str_contains($script, '/reception/') || str_contains($script, '/sections/')) ? '../' : '';
            header('Location: ' . $prefix . 'index.php?timeout=1');
        }
        exit;
    }
    $_SESSION['last_activity'] = $now;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify(): bool
{
    $sent = $_POST['csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    return is_string($sent) && is_string($known) && $known !== '' && hash_equals($known, $sent);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !csrf_verify()) {
    http_response_code(403);
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/api/')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid request.']);
    } else {
        echo 'This form expired. Go back, refresh the page, and try again.';
    }
    exit;
}

function login_attempt_file(string $username): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = hash('sha256', $ip . '|' . strtolower(trim($username)));
    return sys_get_temp_dir() . '/breeze-login-' . $key . '.json';
}

function login_attempt_state(string $username): array
{
    $file = login_attempt_file($username);
    if (!is_file($file)) {
        return ['count' => 0, 'start' => time(), 'until' => 0];
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        return ['count' => 0, 'start' => time(), 'until' => 0];
    }
    return $data;
}

function login_is_locked(string $username): bool
{
    $data = login_attempt_state($username);
    return (int)($data['until'] ?? 0) > time();
}

function login_register_failure(string $username): void
{
    $data = login_attempt_state($username);
    $now = time();
    if ((int)($data['start'] ?? 0) < $now - 900) {
        $data = ['count' => 0, 'start' => $now, 'until' => 0];
    }
    $data['count'] = (int)($data['count'] ?? 0) + 1;
    if ($data['count'] >= 5) {
        $data['until'] = $now + 900;
    }
    file_put_contents(login_attempt_file($username), json_encode($data), LOCK_EX);
}

function login_clear_failures(string $username): void
{
    $file = login_attempt_file($username);
    if (is_file($file)) {
        unlink($file);
    }
}

function login_failure_text(string $username): string
{
    login_register_failure($username);
    if (login_is_locked($username)) {
        return 'Too many attempts. Try again in 15 minutes.';
    }
    return 'Invalid username or password.';
}

function begin_authenticated_session(array $user): void
{
    unset($user['password'], $user['reset_token'], $user['reset_expires']);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['last_activity'] = time();
}

function home_for_role(string $role): string
{
    $homes = [
        'admin' => 'admin/dashboard.php',
        'supervisor' => 'admin/dashboard.php',
        'frontdesk' => 'frontdesk/dashboard.php',
        'reception' => 'frontdesk/dashboard.php',
        'massage' => 'sections/massage.php',
        'therapist' => 'sections/massage.php',
        'barbering' => 'sections/barbering.php',
        'facials' => 'sections/facials.php',
        'nails' => 'sections/nails.php',
        'salon' => 'sections/salon.php',
    ];
    return $homes[$role] ?? 'index.php';
}

function is_password_complex(string $password, ?string &$errorMsg = null): bool
{
    if (strlen($password) < 10) {
        $errorMsg = 'Password must be at least 10 characters long.';
        return false;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errorMsg = 'Password must contain at least one uppercase letter.';
        return false;
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errorMsg = 'Password must contain at least one lowercase letter.';
        return false;
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errorMsg = 'Password must contain at least one number.';
        return false;
    }
    return true;
}

function check_api_rate_limit(int $maxRequests = 100, int $windowSeconds = 60): void
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api'));
    $key = hash('sha256', 'rate_' . $ip . '_' . $script);
    $file = sys_get_temp_dir() . '/breeze-rate-' . $key . '.json';
    $now = time();
    $data = ['count' => 0, 'start' => $now];
    if (is_file($file)) {
        $stored = json_decode((string)file_get_contents($file), true);
        if (is_array($stored) && isset($stored['start'], $stored['count'])) {
            if ($now - (int)$stored['start'] < $windowSeconds) {
                $data = $stored;
            }
        }
    }
    $data['count'] = (int)($data['count'] ?? 0) + 1;
    file_put_contents($file, json_encode($data), LOCK_EX);
    if ($data['count'] > $maxRequests) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $windowSeconds - ($now - (int)$data['start'])));
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Too many requests. Please slow down.']);
        exit;
    }
}
