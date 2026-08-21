<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$databaseHost = getenv('MYSQL_HOST') ?: '127.0.0.1';
$databasePort = (int) (getenv('MYSQL_PORT') ?: 3306);
$databaseName = getenv('MYSQL_DATABASE') ?: 'gost_relay_test';
$databaseUser = getenv('MYSQL_USER') ?: 'root';
$databasePassword = getenv('MYSQL_PASSWORD') ?: '';
$panelUrl = getenv('PANEL_URL') ?: 'http://127.0.0.1:18080';
$config = [
    'database' => [
        'host' => $databaseHost,
        'port' => $databasePort,
        'name' => $databaseName,
        'user' => $databaseUser,
        'password' => $databasePassword,
    ],
    'app' => [
        'url' => $panelUrl,
        'key' => base64_encode(str_repeat('k', 32)),
        'timezone' => 'Asia/Shanghai',
        'poll_interval' => 5,
    ],
];
file_put_contents(
    $root . '/panel/config/config.php',
    "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n",
    LOCK_EX
);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $databaseHost, $databasePort, $databaseName),
    $databaseUser,
    $databasePassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
);
$schema = file_get_contents($root . '/panel/database/schema.sql');
if (!is_string($schema)) {
    throw new RuntimeException('schema missing');
}
$pdo->exec($schema);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0; TRUNCATE TABLE forward_rules; TRUNCATE TABLE nodes; TRUNCATE TABLE server_groups; TRUNCATE TABLE admins; SET FOREIGN_KEY_CHECKS=1');

require $root . '/panel/src/bootstrap.php';
$pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
    ->execute(['admin', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT)]);
$token = 'ci-enrollment-token-1234567890-ABCDEFGH';
$pdo->prepare('INSERT INTO server_groups (name, token_hash, token_encrypted, revision) VALUES (?, ?, ?, ?)')
    ->execute(['CI Group', hash('sha256', $token), Crypto::encrypt($token), 2]);
$groupId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO forward_rules (group_id, name, listen_port, target_ipv4, target_port, enabled) VALUES (?, ?, ?, ?, ?, 1)')
    ->execute([$groupId, 'CI Rule', 19445, '127.0.0.1', 19443]);
echo $token, PHP_EOL;
