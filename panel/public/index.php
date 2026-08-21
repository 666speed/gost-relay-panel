<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$path = request_path();
$method = request_method();
$isApi = str_starts_with($path, '/api/');
send_security_headers($isApi);

try {
    if ($path === '/health') {
        if (!panel_is_configured()) {
            json_response(['ok' => false, 'status' => 'setup_required'], 503);
        }
        Database::connection()->query('SELECT 1');
        json_response(['ok' => true, 'status' => 'healthy', 'version' => RELAY_PANEL_VERSION]);
    }

    if (str_starts_with($path, '/download/')) {
        serve_download($path);
    }

    if (!panel_is_configured()) {
        redirect('/setup.php');
    }

    date_default_timezone_set((string) (panel_config('app')['timezone'] ?? 'Asia/Shanghai'));

    if ($isApi) {
        handle_api($path, $method);
    }

    if ($path === '/login') {
        if ($method === 'POST') {
            verify_csrf();
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (Auth::attempt($username, $password)) {
                redirect('/');
            }
            flash('error', '账号或密码错误；连续失败 5 次会暂停登录 60 秒。');
            redirect('/login');
        }
        if (Auth::user() !== null) {
            redirect('/');
        }
        render('login', ['installed' => isset($_GET['installed']), 'flash' => pull_flash()], false);
    }

    if ($path === '/logout' && $method === 'POST') {
        verify_csrf();
        Auth::logout();
        redirect('/login');
    }

    $admin = Auth::requireUser();
    $pdo = Database::connection();

    if ($path === '/' && $method === 'GET') {
        $stats = [
            'groups' => (int) $pdo->query('SELECT COUNT(*) FROM server_groups')->fetchColumn(),
            'nodes' => (int) $pdo->query('SELECT COUNT(*) FROM nodes WHERE revoked_at IS NULL')->fetchColumn(),
            'online' => (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE revoked_at IS NULL AND last_seen_at >= UTC_TIMESTAMP() - INTERVAL 60 SECOND")->fetchColumn(),
            'rules' => (int) $pdo->query('SELECT COUNT(*) FROM forward_rules WHERE enabled = 1')->fetchColumn(),
        ];
        $recentNodes = $pdo->query(
            'SELECT n.*, g.name AS group_name FROM nodes n '
            . 'JOIN server_groups g ON g.id = n.group_id '
            . 'ORDER BY n.last_seen_at DESC, n.created_at DESC LIMIT 8'
        )->fetchAll();
        render('dashboard', compact('admin', 'stats', 'recentNodes') + ['active' => 'dashboard', 'title' => '运行概览']);
    }

    if ($path === '/groups' && $method === 'GET') {
        $groups = $pdo->query(
            'SELECT g.*, COUNT(DISTINCT CASE WHEN n.revoked_at IS NULL THEN n.id END) AS node_count, COUNT(DISTINCT r.id) AS rule_count '
            . 'FROM server_groups g LEFT JOIN nodes n ON n.group_id = g.id '
            . 'LEFT JOIN forward_rules r ON r.group_id = g.id '
            . 'GROUP BY g.id ORDER BY g.id DESC'
        )->fetchAll();
        foreach ($groups as &$group) {
            $group['install_command'] = group_install_command($group);
        }
        unset($group);
        render('groups', compact('admin', 'groups') + ['active' => 'groups', 'title' => '服务器组']);
    }

    if ($path === '/groups/create' && $method === 'POST') {
        verify_csrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || strlen($name) > 100) {
            flash('error', '服务器组名称需要 1-100 个字符。');
            redirect('/groups');
        }
        $token = random_token();
        try {
            $stmt = $pdo->prepare('INSERT INTO server_groups (name, token_hash, token_encrypted) VALUES (?, ?, ?)');
            $stmt->execute([$name, hash('sha256', $token), Crypto::encrypt($token)]);
            flash('success', '服务器组已创建，可复制节点安装命令。');
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                flash('error', '服务器组名称已存在。');
            } else {
                throw $exception;
            }
        }
        redirect('/groups');
    }

    if ($path === '/groups/rotate' && $method === 'POST') {
        verify_csrf();
        $groupId = (int) ($_POST['id'] ?? 0);
        $token = random_token();
        $stmt = $pdo->prepare('UPDATE server_groups SET token_hash = ?, token_encrypted = ? WHERE id = ?');
        $stmt->execute([hash('sha256', $token), Crypto::encrypt($token), $groupId]);
        flash($stmt->rowCount() === 1 ? 'success' : 'error', $stmt->rowCount() === 1 ? '安装令牌已轮换；已上线节点不受影响。' : '服务器组不存在。');
        redirect('/groups');
    }

    if ($path === '/groups/delete' && $method === 'POST') {
        verify_csrf();
        $groupId = (int) ($_POST['id'] ?? 0);
        $nodeCheck = $pdo->prepare('SELECT COUNT(*) FROM nodes WHERE group_id = ? AND revoked_at IS NULL');
        $nodeCheck->execute([$groupId]);
        if ((int) $nodeCheck->fetchColumn() > 0) {
            flash('error', '该组仍有有效节点；请先在节点页面逐台撤销，等待规则清空后再删除服务器组。');
            redirect('/groups');
        }
        $stmt = $pdo->prepare('DELETE FROM server_groups WHERE id = ?');
        $stmt->execute([$groupId]);
        flash($stmt->rowCount() === 1 ? 'success' : 'error', $stmt->rowCount() === 1 ? '服务器组及其规则已删除。' : '服务器组不存在。');
        redirect('/groups');
    }

    if ($path === '/nodes' && $method === 'GET') {
        $nodes = $pdo->query(
            'SELECT n.*, g.name AS group_name, g.revision AS desired_revision '
            . 'FROM nodes n JOIN server_groups g ON g.id = n.group_id '
            . 'ORDER BY n.last_seen_at DESC, n.created_at DESC'
        )->fetchAll();
        render('nodes', compact('admin', 'nodes') + ['active' => 'nodes', 'title' => '节点状态']);
    }

    if ($path === '/nodes/delete' && $method === 'POST') {
        verify_csrf();
        $stmt = $pdo->prepare('UPDATE nodes SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()) WHERE id = ?');
        $stmt->execute([(string) ($_POST['id'] ?? '')]);
        flash($stmt->rowCount() === 1 ? 'success' : 'error', $stmt->rowCount() === 1 ? '节点已撤销；代理下一次同步后会清空该节点转发。' : '节点不存在。');
        redirect('/nodes');
    }

    if ($path === '/forwards' && $method === 'GET') {
        $groups = $pdo->query('SELECT id, name, revision FROM server_groups ORDER BY name')->fetchAll();
        $rules = $pdo->query(
            'SELECT r.*, g.name AS group_name, g.revision AS group_revision '
            . 'FROM forward_rules r JOIN server_groups g ON g.id = r.group_id '
            . 'ORDER BY r.id DESC'
        )->fetchAll();
        render('forwards', compact('admin', 'groups', 'rules') + ['active' => 'forwards', 'title' => 'TCP 转发']);
    }

    if ($path === '/forwards/save' && $method === 'POST') {
        verify_csrf();
        $ruleId = (int) ($_POST['id'] ?? 0);
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $listenPort = (string) ($_POST['listen_port'] ?? '');
        $targetIpv4 = trim((string) ($_POST['target_ipv4'] ?? ''));
        $targetPort = (string) ($_POST['target_port'] ?? '');
        $enabled = isset($_POST['enabled']) ? 1 : 0;

        if ($groupId <= 0 || $name === '' || strlen($name) > 100 || !valid_port($listenPort)
            || !valid_ipv4_target($targetIpv4) || !valid_port($targetPort)) {
            flash('error', '规则参数无效：仅支持 TCP、IPv4 目标和 1-65535 端口。');
            redirect('/forwards');
        }

        $pdo->beginTransaction();
        try {
            if ($ruleId > 0) {
                $oldStmt = $pdo->prepare('SELECT group_id FROM forward_rules WHERE id = ? FOR UPDATE');
                $oldStmt->execute([$ruleId]);
                $oldGroupId = (int) ($oldStmt->fetchColumn() ?: 0);
                if ($oldGroupId <= 0) {
                    throw new RuntimeException('规则不存在。');
                }
                $stmt = $pdo->prepare(
                    'UPDATE forward_rules SET group_id = ?, name = ?, listen_port = ?, target_ipv4 = ?, target_port = ?, enabled = ? WHERE id = ?'
                );
                $stmt->execute([$groupId, $name, (int) $listenPort, $targetIpv4, (int) $targetPort, $enabled, $ruleId]);
                bump_group_revision($pdo, $oldGroupId);
                if ($oldGroupId !== $groupId) {
                    bump_group_revision($pdo, $groupId);
                }
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO forward_rules (group_id, name, listen_port, target_ipv4, target_port, enabled) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$groupId, $name, (int) $listenPort, $targetIpv4, (int) $targetPort, $enabled]);
                bump_group_revision($pdo, $groupId);
            }
            $pdo->commit();
            flash('success', $ruleId > 0 ? '转发规则已更新并等待节点同步。' : '转发规则已创建并等待节点同步。');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                flash('error', '该服务器组内的监听端口已被其他规则使用。');
            } else {
                flash('error', $exception->getMessage());
            }
        }
        redirect('/forwards');
    }

    if ($path === '/forwards/toggle' && $method === 'POST') {
        verify_csrf();
        $ruleId = (int) ($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT group_id, enabled FROM forward_rules WHERE id = ? FOR UPDATE');
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch();
        if (!is_array($rule)) {
            $pdo->rollBack();
            flash('error', '规则不存在。');
            redirect('/forwards');
        }
        $update = $pdo->prepare('UPDATE forward_rules SET enabled = ? WHERE id = ?');
        $update->execute([(int) $rule['enabled'] === 1 ? 0 : 1, $ruleId]);
        bump_group_revision($pdo, (int) $rule['group_id']);
        $pdo->commit();
        flash('success', (int) $rule['enabled'] === 1 ? '规则已暂停。' : '规则已启用。');
        redirect('/forwards');
    }

    if ($path === '/forwards/delete' && $method === 'POST') {
        verify_csrf();
        $ruleId = (int) ($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT group_id FROM forward_rules WHERE id = ? FOR UPDATE');
        $stmt->execute([$ruleId]);
        $groupId = (int) ($stmt->fetchColumn() ?: 0);
        if ($groupId > 0) {
            $delete = $pdo->prepare('DELETE FROM forward_rules WHERE id = ?');
            $delete->execute([$ruleId]);
            bump_group_revision($pdo, $groupId);
        }
        $pdo->commit();
        flash($groupId > 0 ? 'success' : 'error', $groupId > 0 ? '转发规则已删除。' : '规则不存在。');
        redirect('/forwards');
    }

    http_response_code(404);
    render('not-found', compact('admin') + ['active' => '', 'title' => '页面不存在']);
} catch (Throwable $exception) {
    error_log('[gost-relay] ' . $exception);
    if ($isApi) {
        json_response(['ok' => false, 'error' => 'server_error'], 500);
    }
    http_response_code(500);
    echo '服务器内部错误，请查看 PHP 错误日志。';
    exit;
}

function serve_download(string $path): never
{
    $files = [
        '/download/node-install.sh' => RELAY_PANEL_ROOT . '/../node/node-install.sh',
        '/download/agent.py' => RELAY_PANEL_ROOT . '/../node/gost-relay-agent.py',
        '/download/gost-relay.service' => RELAY_PANEL_ROOT . '/../node/gost-relay.service',
        '/download/gost-relay-agent.service' => RELAY_PANEL_ROOT . '/../node/gost-relay-agent.service',
        '/download/relayctl' => RELAY_PANEL_ROOT . '/../node/relayctl',
    ];
    $file = $files[$path] ?? '';
    if ($file === '' || !is_file($file)) {
        http_response_code(404);
        exit('not found');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . basename($file) . '"');
    header('Cache-Control: no-cache');
    readfile($file);
    exit;
}

function handle_api(string $path, string $method): never
{
    if ($method !== 'POST') {
        header('Allow: POST');
        json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
    }
    if ($path === '/api/v1/enroll') {
        api_enroll();
    }
    if ($path === '/api/v1/sync') {
        api_sync();
    }
    json_response(['ok' => false, 'error' => 'not_found'], 404);
}

function api_enroll(): never
{
    $input = json_input();
    $token = (string) ($input['token'] ?? '');
    $name = trim((string) ($input['name'] ?? ''));
    $hostname = trim((string) ($input['hostname'] ?? ''));
    $osName = trim((string) ($input['os'] ?? ''));
    $architecture = trim((string) ($input['architecture'] ?? ''));
    $agentVersion = trim((string) ($input['agent_version'] ?? ''));
    if (strlen($token) < 32 || $name === '' || strlen($name) > 100) {
        json_response(['ok' => false, 'error' => 'invalid_enrollment'], 422);
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, name, revision FROM server_groups WHERE token_hash = ?');
    $stmt->execute([hash('sha256', $token)]);
    $group = $stmt->fetch();
    if (!is_array($group)) {
        usleep(random_int(150000, 350000));
        json_response(['ok' => false, 'error' => 'invalid_token'], 401);
    }

    $nodeId = uuid_v4();
    $secret = random_token();
    $insert = $pdo->prepare(
        'INSERT INTO nodes (id, group_id, name, hostname, remote_ip, os_name, architecture, agent_version, secret_hash, last_seen_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $insert->execute([
        $nodeId,
        (int) $group['id'],
        substr($name, 0, 100),
        substr($hostname, 0, 255),
        client_ip(),
        substr($osName, 0, 100),
        substr($architecture, 0, 32),
        substr($agentVersion, 0, 32),
        hash('sha256', $secret),
    ]);

    json_response([
        'ok' => true,
        'node_id' => $nodeId,
        'node_secret' => $secret,
        'group' => (string) $group['name'],
        'desired_revision' => (int) $group['revision'],
    ], 201);
}

function api_sync(): never
{
    $nodeId = trim((string) ($_SERVER['HTTP_X_NODE_ID'] ?? ''));
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if ($nodeId === '' || preg_match('/^Bearer\s+(.+)$/iD', $authorization, $matches) !== 1) {
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $secret = $matches[1];
    $pdo = Database::connection();
    $stmt = $pdo->prepare(
        'SELECT n.id, n.group_id, n.secret_hash, n.revoked_at, g.revision FROM nodes n '
        . 'JOIN server_groups g ON g.id = n.group_id WHERE n.id = ?'
    );
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch();
    if (!is_array($node) || !hash_equals((string) $node['secret_hash'], hash('sha256', $secret))) {
        usleep(random_int(100000, 250000));
        json_response(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $input = json_input();
    $appliedRevision = max(0, (int) ($input['applied_revision'] ?? 0));
    $lastError = substr(trim((string) ($input['last_error'] ?? '')), 0, 2000);
    $hostname = substr(trim((string) ($input['hostname'] ?? '')), 0, 255);
    $osName = substr(trim((string) ($input['os'] ?? '')), 0, 100);
    $architecture = substr(trim((string) ($input['architecture'] ?? '')), 0, 32);
    $agentVersion = substr(trim((string) ($input['agent_version'] ?? '')), 0, 32);
    $update = $pdo->prepare(
        'UPDATE nodes SET remote_ip = ?, hostname = ?, os_name = ?, architecture = ?, agent_version = ?, '
        . 'applied_revision = ?, last_error = ?, last_seen_at = UTC_TIMESTAMP() WHERE id = ?'
    );
    $update->execute([client_ip(), $hostname, $osName, $architecture, $agentVersion, $appliedRevision, $lastError, $nodeId]);

    if ($node['revoked_at'] !== null) {
        json_response([
            'ok' => true,
            'revoked' => true,
            'desired_revision' => 0,
            'poll_interval' => 60,
            'rules' => [],
        ]);
    }

    $rulesStmt = $pdo->prepare(
        'SELECT id, name, listen_port, target_ipv4, target_port FROM forward_rules '
        . 'WHERE group_id = ? AND enabled = 1 ORDER BY listen_port, id'
    );
    $rulesStmt->execute([(int) $node['group_id']]);
    $rules = [];
    foreach ($rulesStmt->fetchAll() as $rule) {
        $rules[] = [
            'id' => (int) $rule['id'],
            'name' => (string) $rule['name'],
            'listen_port' => (int) $rule['listen_port'],
            'target_ipv4' => (string) $rule['target_ipv4'],
            'target_port' => (int) $rule['target_port'],
        ];
    }
    $interval = min(300, max(5, (int) (panel_config('app')['poll_interval'] ?? 15)));
    json_response([
        'ok' => true,
        'desired_revision' => (int) $node['revision'],
        'poll_interval' => $interval,
        'rules' => $rules,
    ]);
}
