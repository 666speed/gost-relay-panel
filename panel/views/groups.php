<div class="page-actions">
    <div><p class="muted">同一服务器组内的所有节点共享一份 TCP 转发规则。</p></div>
    <button class="button primary" type="button" data-dialog-open="group-dialog">＋ 添加服务器组</button>
</div>

<?php if ($groups === []): ?>
    <section class="panel-card empty-state"><strong>尚未创建服务器组</strong><p>创建后会生成一条节点一键安装命令。</p><button class="button primary" type="button" data-dialog-open="group-dialog">创建第一个组</button></section>
<?php else: ?>
    <div class="group-grid">
    <?php foreach ($groups as $group): ?>
        <article class="group-card">
            <div class="group-card-head">
                <div><span class="group-icon">▦</span><h2><?= e($group['name']) ?></h2></div>
                <span class="revision">REV <?= e($group['revision']) ?></span>
            </div>
            <div class="group-metrics">
                <div><strong><?= e($group['node_count']) ?></strong><span>节点</span></div>
                <div><strong><?= e($group['rule_count']) ?></strong><span>规则</span></div>
            </div>
            <label class="command-label">节点一键安装命令</label>
            <div class="copy-field">
                <code><?= e($group['install_command']) ?></code>
                <button class="button secondary small" type="button" data-copy="<?= e($group['install_command']) ?>">复制</button>
            </div>
            <div class="card-actions">
                <form method="post" action="/groups/rotate" data-confirm="轮换后，旧安装命令会立即失效；已上线节点不受影响。确认继续？">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($group['id']) ?>">
                    <button class="button secondary small" type="submit">轮换令牌</button>
                </form>
                <form method="post" action="/groups/delete" data-confirm="只有已撤销全部节点的服务器组才能删除；确认继续？">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($group['id']) ?>">
                    <button class="button danger small" type="submit">删除</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<dialog id="group-dialog" class="modal">
    <form method="post" action="/groups/create">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div class="modal-head"><div><p class="eyebrow">SERVER GROUP</p><h2>添加服务器组</h2></div><button type="button" class="modal-close button-reset" data-dialog-close aria-label="关闭">×</button></div>
        <label>组名称<input name="name" maxlength="100" placeholder="例如：香港中转组" required autofocus></label>
        <p class="form-help">组内每台节点会收到相同的监听端口与固定 IPv4 目标配置。</p>
        <div class="modal-actions"><button type="button" class="button secondary" data-dialog-close>取消</button><button class="button primary" type="submit">创建并生成命令</button></div>
    </form>
</dialog>
