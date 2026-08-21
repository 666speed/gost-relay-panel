<div class="page-actions">
    <div class="filter-pills"><span class="pill active">全部 <?= e(count($rules)) ?></span><span class="pill">TCP</span><span class="pill">IPv4</span></div>
    <button class="button primary" type="button" data-rule-new <?= $groups === [] ? 'disabled' : '' ?>>＋ 添加规则</button>
</div>

<?php if ($groups === []): ?>
    <div class="alert alert-error">请先创建服务器组，再添加转发规则。</div>
<?php endif; ?>

<section class="panel-card rule-card">
<?php if ($rules === []): ?>
    <div class="empty-state"><strong>暂无 TCP 转发规则</strong><p>一个监听端口只对应一个固定 IPv4 与端口；不处理 UDP、IPv6 或 SNI。</p></div>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>规则名</th><th>服务器组</th><th>监听</th><th>固定目标</th><th>配置版本</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><strong><?= e($rule['name']) ?></strong><small class="cell-sub">#<?= e($rule['id']) ?></small></td>
                    <td><?= e($rule['group_name']) ?></td>
                    <td><span class="protocol">TCP</span><span class="mono">0.0.0.0:<?= e($rule['listen_port']) ?></span></td>
                    <td class="mono"><?= e($rule['target_ipv4']) ?>:<?= e($rule['target_port']) ?></td>
                    <td class="mono">REV <?= e($rule['group_revision']) ?></td>
                    <td><span class="badge <?= (int) $rule['enabled'] === 1 ? 'online' : 'offline' ?>"><?= (int) $rule['enabled'] === 1 ? '启用' : '暂停' ?></span></td>
                    <td><div class="inline-actions">
                        <form method="post" action="/forwards/toggle"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($rule['id']) ?>"><button class="button secondary small" type="submit"><?= (int) $rule['enabled'] === 1 ? '暂停' : '启用' ?></button></form>
                        <button class="button secondary small" type="button" data-rule-edit
                            data-id="<?= e($rule['id']) ?>" data-group="<?= e($rule['group_id']) ?>" data-name="<?= e($rule['name']) ?>"
                            data-listen="<?= e($rule['listen_port']) ?>" data-target="<?= e($rule['target_ipv4']) ?>" data-target-port="<?= e($rule['target_port']) ?>"
                            data-enabled="<?= e($rule['enabled']) ?>">编辑</button>
                        <form method="post" action="/forwards/delete" data-confirm="确认删除这条转发规则？节点同步后会停止监听。"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= e($rule['id']) ?>"><button class="button danger small" type="submit">删除</button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>

<dialog id="rule-dialog" class="modal wide">
    <form method="post" action="/forwards/save" id="rule-form">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="">
        <div class="modal-head"><div><p class="eyebrow">TCP FORWARD</p><h2 data-rule-title>添加转发规则</h2></div><button type="button" class="modal-close button-reset" data-dialog-close aria-label="关闭">×</button></div>
        <div class="form-grid two-columns">
            <label class="span-two">规则名称<input name="name" maxlength="100" placeholder="例如：Cloudflare-443" required></label>
            <label class="span-two">服务器组<select name="group_id" required><option value="">请选择节点组</option><?php foreach ($groups as $group): ?><option value="<?= e($group['id']) ?>"><?= e($group['name']) ?></option><?php endforeach; ?></select></label>
            <label>TCP 监听端口<input name="listen_port" inputmode="numeric" min="1" max="65535" placeholder="443" required></label>
            <label>目标端口<input name="target_port" inputmode="numeric" min="1" max="65535" value="443" required></label>
            <label class="span-two">固定目标 IPv4<input name="target_ipv4" inputmode="decimal" placeholder="104.16.1.2" required></label>
            <label class="check-row span-two"><input type="checkbox" name="enabled" value="1" checked><span>创建后立即启用并下发到组内全部节点</span></label>
        </div>
        <div class="scope-note"><strong>规则范围</strong><span>只生成原始 TCP 转发；不检查 SNI，不创建 UDP/IPv6 监听。</span></div>
        <div class="modal-actions"><button type="button" class="button secondary" data-dialog-close>取消</button><button class="button primary" type="submit">保存规则</button></div>
    </form>
</dialog>
