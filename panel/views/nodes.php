<div class="page-actions">
    <p class="muted">节点每 15 秒主动连接主控；主控无需访问节点 SSH 或开放管理端口。</p>
    <a href="/groups" class="button secondary">查看安装命令</a>
</div>

<section class="panel-card">
<?php if ($nodes === []): ?>
    <div class="empty-state"><strong>暂无节点</strong><p>到服务器组页面复制命令，并在 Ubuntu 或 Debian 节点上执行。</p><a class="button primary" href="/groups">前往服务器组</a></div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>节点</th><th>服务器组</th><th>地址</th><th>版本</th><th>同步</th><th>状态</th><th>错误</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($nodes as $node): $status = $node['revoked_at'] !== null ? 'revoked' : node_status($node['last_seen_at']); $synced = $status === 'revoked' || (int) $node['applied_revision'] === (int) $node['desired_revision']; ?>
                <tr>
                    <td><strong><?= e($node['name']) ?></strong><small class="cell-sub"><?= e($node['hostname']) ?></small></td>
                    <td><?= e($node['group_name']) ?></td>
                    <td><span class="mono"><?= e($node['remote_ip'] ?: '—') ?></span><small class="cell-sub"><?= e(trim($node['os_name'] . ' ' . $node['architecture'])) ?></small></td>
                    <td class="mono"><?= e($node['agent_version'] ?: '—') ?></td>
                    <td><span class="badge <?= $synced ? 'online' : 'pending' ?>"><?= $synced ? '已同步' : e($node['applied_revision'] . ' / ' . $node['desired_revision']) ?></span></td>
                    <td><span class="badge <?= $status ?>"><?= $status === 'online' ? '在线' : ($status === 'revoked' ? '已撤销' : '离线') ?></span><small class="cell-sub"><?= e($node['revoked_at'] ?: ($node['last_seen_at'] ?: '从未')) ?></small></td>
                    <td class="error-cell" title="<?= e($node['last_error']) ?>"><?= e($node['last_error'] ?: '—') ?></td>
                    <td>
                        <form method="post" action="/nodes/delete" data-confirm="删除后该节点凭据立即失效，但节点当前已运行的转发会继续工作，直到你在节点上卸载。确认删除？">
                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($node['id']) ?>">
                            <button class="button danger small" type="submit" <?= $status === 'revoked' ? 'disabled' : '' ?>><?= $status === 'revoked' ? '已撤销' : '撤销' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>
