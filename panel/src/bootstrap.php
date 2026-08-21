<?php
declare(strict_types=1);

const RELAY_PANEL_ROOT = __DIR__ . '/..';
const RELAY_PANEL_VERSION = '1.0.0';

function panel_config_path(): string
{
    return RELAY_PANEL_ROOT . '/config/config.php';
}

function panel_is_configured(): bool
{
    return is_file(panel_config_path());
}

function panel_config(?string $section = null): array
{
    static $config = null;
    if ($config === null) {
        if (!panel_is_configured()) {
            throw new RuntimeException('控制面板尚未完成初始化。');
        }
        $loaded = require panel_config_path();
        if (!is_array($loaded)) {
            throw new RuntimeException('控制面板配置文件无效。');
        }
        $config = $loaded;
    }

    if ($section === null) {
        return $config;
    }
    $value = $config[$section] ?? null;
    if (!is_array($value)) {
        throw new RuntimeException('缺少配置项：' . $section);
    }
    return $value;
}

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $db = panel_config('database');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) $db['host'],
            (int) $db['port'],
            (string) $db['name']
        );
        self::$pdo = new PDO($dsn, (string) $db['user'], (string) $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }
}

final class Crypto
{
    private static function key(): string
    {
        $app = panel_config('app');
        $key = base64_decode((string) ($app['key'] ?? ''), true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('APP_KEY 必须是 Base64 编码的 32 字节密钥。');
        }
        return hash_hkdf('sha256', $key, 32, 'gost-relay-enrollment-token');
    }

    public static function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'gost-relay-panel',
            16
        );
        if (!is_string($cipherText)) {
            throw new RuntimeException('加密服务器组令牌失败。');
        }
        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if (!is_string($payload) || strlen($payload) < 29) {
            throw new RuntimeException('服务器组令牌数据无效。');
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipherText = substr($payload, 28);
        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'gost-relay-panel'
        );
        if (!is_string($plainText)) {
            throw new RuntimeException('解密服务器组令牌失败。');
        }
        return $plainText;
    }
}

final class Auth
{
    public static function user(): ?array
    {
        start_secure_session();
        if (!isset($_SESSION['admin_id'])) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT id, username FROM admins WHERE id = ?');
        $stmt->execute([(int) $_SESSION['admin_id']]);
        $user = $stmt->fetch();
        return is_array($user) ? $user : null;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user === null) {
            redirect('/login');
        }
        return $user;
    }

    public static function attempt(string $username, string $password): bool
    {
        start_secure_session();
        $blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);
        if ($blockedUntil > time()) {
            return false;
        }

        $stmt = Database::connection()->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        $valid = is_array($admin) && password_verify($password, (string) $admin['password_hash']);
        if (!$valid) {
            $failures = ((int) ($_SESSION['login_failures'] ?? 0)) + 1;
            $_SESSION['login_failures'] = $failures;
            if ($failures >= 5) {
                $_SESSION['login_blocked_until'] = time() + 60;
                $_SESSION['login_failures'] = 0;
            }
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $admin['id'];
        unset($_SESSION['login_failures'], $_SESSION['login_blocked_until']);
        $update = Database::connection()->prepare('UPDATE admins SET last_login_at = UTC_TIMESTAMP() WHERE id = ?');
        $update->execute([(int) $admin['id']]);
        return true;
    }

    public static function logout(): void
    {
        start_secure_session();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = request_is_https();
    session_name('gost_relay_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function send_security_headers(bool $api = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'");
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header($api ? 'Cache-Control: no-store' : 'Cache-Control: no-cache, private');
}

function request_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return '/';
    }
    $path = '/' . ltrim($path, '/');
    return $path !== '/' ? rtrim($path, '/') : '/';
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    start_secure_session();
    $provided = (string) ($_POST['_csrf'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(419);
        exit('页面已过期，请刷新后重试。');
    }
}

function flash(string $type, string $message): void
{
    start_secure_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    start_secure_session();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function render(string $view, array $data = [], bool $withLayout = true): never
{
    extract($data, EXTR_SKIP);
    $viewFile = RELAY_PANEL_ROOT . '/views/' . $view . '.php';
    if (!is_file($viewFile)) {
        throw new RuntimeException('视图不存在：' . $view);
    }
    ob_start();
    require $viewFile;
    $content = (string) ob_get_clean();
    if ($withLayout) {
        require RELAY_PANEL_ROOT . '/views/layout.php';
    } else {
        echo $content;
    }
    exit;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function json_input(int $maxBytes = 65536): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > $maxBytes) {
        json_response(['ok' => false, 'error' => 'request_too_large'], 413);
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (!is_string($raw) || strlen($raw) > $maxBytes) {
        json_response(['ok' => false, 'error' => 'request_too_large'], 413);
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['ok' => false, 'error' => 'invalid_json'], 400);
    }
    if (!is_array($decoded)) {
        json_response(['ok' => false, 'error' => 'invalid_json'], 400);
    }
    return $decoded;
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function valid_ipv4_target(string $value): bool
{
    if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    return $value !== '0.0.0.0' && $value !== '255.255.255.255';
}

function valid_port(mixed $value): bool
{
    $text = (string) $value;
    return preg_match('/^[1-9][0-9]{0,4}$/D', $text) === 1 && (int) $text <= 65535;
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return substr($ip, 0, 45);
}

function node_status(?string $lastSeenAt): string
{
    if ($lastSeenAt === null || $lastSeenAt === '') {
        return 'offline';
    }
    $timestamp = strtotime($lastSeenAt . ' UTC');
    return is_int($timestamp) && $timestamp >= time() - 60 ? 'online' : 'offline';
}

function app_url(): string
{
    return rtrim((string) panel_config('app')['url'], '/');
}

function group_install_command(array $group): string
{
    $token = Crypto::decrypt((string) $group['token_encrypted']);
    return sprintf(
        'bash <(curl -fLSs %s/download/node-install.sh) rel_nodeclient "-t %s -u %s"',
        app_url(),
        $token,
        app_url()
    );
}

function bump_group_revision(PDO $pdo, int $groupId): void
{
    $stmt = $pdo->prepare('UPDATE server_groups SET revision = revision + 1 WHERE id = ?');
    $stmt->execute([$groupId]);
}
