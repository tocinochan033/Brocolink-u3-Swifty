<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '1800');

    $https = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    if ($https) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

require_once __DIR__ . '/database.php';

function redirect($location) {
    header("Location: $location");
    exit();
}

function isLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && $lastActivity < time() - 1800) {
        destroySession();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function registrationEnabled() {
    $explicitSetting = getenv('ALLOW_REGISTRATION');
    if ($explicitSetting !== false) {
        return in_array(strtolower($explicitSetting), ['true', '1', 'yes'], true);
    }

    return strtolower(getenv('APP_ENV') ?: 'development') !== 'production';
}

function requireAuth() {
    if (!isLoggedIn()) {
        redirect('/swift_invoice/auth/login.php');
    }
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function requireCsrf($json = false) {
    $submittedToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submittedToken) || !is_string($sessionToken) ||
        $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        http_response_code(403);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Token CSRF inválido o ausente.']);
            exit;
        }
        exit('Solicitud no válida. Recarga la página e inténtalo de nuevo.');
    }
}

function loginRateLimitKey() {
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return hash('sha256', $ipAddress);
}

function isLoginRateLimited($conn, $key) {
    $statement = $conn->prepare(
        'SELECT locked_until FROM auth_login_attempts WHERE ip_hash = :ip_hash LIMIT 1'
    );
    $statement->execute([':ip_hash' => $key]);
    $lockedUntil = $statement->fetchColumn();
    return $lockedUntil && strtotime($lockedUntil) > time();
}

function recordFailedLogin($conn, $key) {
    $statement = $conn->prepare(
        'INSERT INTO auth_login_attempts (ip_hash, attempts, window_started_at)
         VALUES (:ip_hash, 1, NOW())
         ON DUPLICATE KEY UPDATE
            attempts = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, attempts + 1),
            locked_until = IF(
                window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE),
                NULL,
                IF(attempts >= 10, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)
            ),
            window_started_at = IF(window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_started_at)'
    );
    $statement->execute([':ip_hash' => $key]);
}

function clearLoginAttempts($conn, $key) {
    $statement = $conn->prepare('DELETE FROM auth_login_attempts WHERE ip_hash = :ip_hash');
    $statement->execute([':ip_hash' => $key]);
}

function destroySession() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }
    session_destroy();
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postedString($name, $default = '') {
    if (!array_key_exists($name, $_POST)) {
        return $default;
    }
    if (!is_string($_POST[$name])) {
        http_response_code(400);
        exit('Formato de solicitud no válido.');
    }
    return $_POST[$name];
}

function jsonForScript($value) {
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
    );
}
