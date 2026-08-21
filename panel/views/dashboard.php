<div class="hero-panel">
    <div>
        <p class="eyebrow">CONTROL PLANE</p>
        <h2>所有节点，一处管理</h2>
        <p>规则由节点主动拉取；即使主控短暂离线，已经运行的转发也不会中断。</p>
    </div>
    <a class="button primary" href="/forwards">＋ 添加转发规则</a>
</div>

<div class="stat-grid">
    <article class="stat-card"><span>服务器组</span><strong><?= e($stats['groups']) ?></strong><small>独立下发范围</small></article>
    <article class="stat-card"><span>全部节点</span><strong><?= e($stats['nodes']) ?></strong><small>已登记服务器</small></article>
    <article class="stat-card accent"><span>在线节点</span><strong><?= e($stats['online']) ?></strong><small>最近 60 秒心跳</small></article>
    <article class="stat-card"><span>启用规则</span><strong><?= e($stats['rules']) ?></strong><small>TCP IPv4 映射</small></article>
</div>

<section class="panel-card">
    <div class="section-heading">
        <div><p class="eyebrow">NODE HEALTH</p><h2>最近节点</h2></div>
        <a class="button secondary small" href="/nodes">查看全部</a>
    </div>
    <?php if ($recentNodes === []): ?>
        <div class="empty-state"><strong>还没有节点</strong><p>先创建服务器组，然后复制一键安装命令到节点执行。</p><a href="/groups" class="button primary">创建服务器组</a></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>节点</th><th>服务器组</th><th>公网地址</th><th>系统</th><th>状态</th><th>最后心跳</th></tr></thead>
                <tbody>
                <?php foreach ($recentNodes as $node): $status = $node['revoked_at'] !== null ? 'revoked' : node_status($node['last_seen_at']); ?>
                    <tr>
                        <td><strong><?= e($node['name']) ?></strong><small class="cell-sub"><?= e($node['hostname']) ?></small></td>
                        <td><?= e($node['group_name']) ?></td>
                        <td class="mono"><?= e($node['remote_ip'] ?: '—') ?></td>
                        <td><?= e(trim($node['os_name'] . ' ' . $node['architecture']) ?: '—') ?></td>
                        <td><span class="badge <?= $status ?>"><?= $status === 'online' ? '在线' : ($status === 'revoked' ? '已撤销' : '离线') ?></span></td>
                        <td><?= e($node['last_seen_at'] ?: '从未') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
