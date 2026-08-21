<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

send_security_headers();
if (panel_is_configured()) {
    redirect('/login');
}
start_secure_session();

$errors = [];
$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'gost_relay',
    'db_user' => 'gost_relay',
    'app_url' => (request_is_https() ? 'https://' : 'http://') . (string) ($_SERVER['HTTP_HOST'] ?? 'relay.example.com'),
    'admin_user' => 'admin',
];

if (request_method() === 'POST') {
    verify_csrf();
    foreach (array_keys($defaults) as $key) {
        $defaults[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $dbPassword = (string) ($_POST['db_password'] ?? '');
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminConfirm = (string) ($_POST['admin_confirm'] ?? '');

    if (!extension_loaded('pdo_mysql')) {
        $errors[] = 'PHP 未启用 pdo_mysql 扩展。';
    }
    if (!extension_loaded('openssl')) {
        $errors[] = 'PHP 未启用 openssl 扩展。';
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $defaults['db_host']) !== 1) {
        $errors[] = '数据库主机格式无效。';
    }
    if (!valid_port($defaults['db_port'])) {
        $errors[] = '数据库端口无效。';
    }
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $defaults['db_name']) !== 1) {
        $errors[] = '数据库名称只能包含字母、数字和下划线。';
    }
    if ($defaults['db_user'] === '' || strlen($defaults['db_user']) > 128) {
        $errors[] = '数据库用户名无效。';
    }
    if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $defaults['admin_user']) !== 1) {
        $errors[] = '管理员用户名需为 3-64 位字母、数字、点、下划线或连字符。';
    }
    if (strlen($adminPassword) < 12) {
        $errors[] = '管理员密码至少需要 12 位。';
    }
    if (!hash_equals($adminPassword, $adminConfirm)) {
        $errors[] = '两次输入的管理员密码不一致。';
    }

    $url = rtrim($defaults['app_url'], '/');
    $parsed = parse_url($url);
    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    $host = strtolower((string) ($parsed['host'] ?? ''));
    $path = (string) ($parsed['path'] ?? '');
    $localHttp = $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true);
    if (($scheme !== 'https' && !$localHttp) || $host === '' || ($path !== '' && $path !== '/')) {
        $errors[] = '面板地址必须是域名根目录的 HTTPS 地址；仅本机测试允许 HTTP。';
    }

    if ($errors === []) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $defaults['db_host'],
                (int) $defaults['db_port'],
                $defaults['db_name']
            );
            $pdo = new PDO($dsn, $defaults['db_user'], $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
            ]);
            $schema = file_get_contents(RELAY_PANEL_ROOT . '/database/schema.sql');
            if (!is_string($schema)) {
                throw new RuntimeException('无法读取数据库结构文件。');
            }
            $pdo->exec($schema);
            $stmt = $pdo->prepare(
                'INSERT INTO admins (username, password_hash) VALUES (?, ?) '
                . 'ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
            );
            $stmt->execute([$defaults['admin_user'], password_hash($adminPassword, PASSWORD_DEFAULT)]);

            $config = [
                'database' => [
                    'host' => $defaults['db_host'],
                    'port' => (int) $defaults['db_port'],
                    'name' => $defaults['db_name'],
                    'user' => $defaults['db_user'],
                    'password' => $dbPassword,
                ],
                'app' => [
                    'url' => $url,
                    'key' => base64_encode(random_bytes(32)),
                    'timezone' => 'Asia/Shanghai',
                    'poll_interval' => 15,
                ],
            ];
            $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            $configPath = panel_config_path();
            if (file_put_contents($configPath, $contents, LOCK_EX) === false) {
                throw new RuntimeException('无法写入 panel/config/config.php，请检查目录权限。');
            }
            @chmod($configPath, 0600);
            $_SESSION['setup_complete'] = true;
            redirect('/login?installed=1');
        } catch (Throwable $exception) {
            error_log('[gost-relay setup] ' . $exception->getMessage());
            $errors[] = '初始化失败：' . $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>初始化 · GOST Relay</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-page">
<main class="auth-card setup-card">
    <div class="brand-mark">GR</div>
    <h1>初始化 GOST Relay</h1>
    <p class="muted">连接宝塔中创建的 MySQL 数据库，并建立首个管理员。</p>
    <?php if ($errors !== []): ?>
        <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>
    <form method="post" class="form-grid two-columns" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>数据库主机<input name="db_host" value="<?= e($defaults['db_host']) ?>" required></label>
        <label>数据库端口<input name="db_port" value="<?= e($defaults['db_port']) ?>" inputmode="numeric" required></label>
        <label>数据库名称<input name="db_name" value="<?= e($defaults['db_name']) ?>" required></label>
        <label>数据库用户<input name="db_user" value="<?= e($defaults['db_user']) ?>" required></label>
        <label class="span-two">数据库密码<input type="password" name="db_password" required></label>
        <label class="span-two">面板 HTTPS 地址<input type="url" name="app_url" value="<?= e($defaults['app_url']) ?>" required></label>
        <label>管理员账号<input name="admin_user" value="<?= e($defaults['admin_user']) ?>" required></label>
        <span></span>
        <label>管理员密码<input type="password" name="admin_password" minlength="12" required></label>
        <label>确认密码<input type="password" name="admin_confirm" minlength="12" required></label>
        <button class="button primary span-two" type="submit">完成初始化</button>
    </form>
</main>
</body>
</html>
