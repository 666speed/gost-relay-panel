(() => {
    'use strict';

    const menuButton = document.querySelector('[data-menu-toggle]');
    menuButton?.addEventListener('click', () => document.body.classList.toggle('menu-open'));

    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        button.addEventListener('click', () => document.getElementById(button.dataset.dialogOpen)?.showModal());
    });
    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
    document.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) {
                dialog.close();
            }
        });
    });

    document.querySelectorAll('[data-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.copy || '');
                const oldText = button.textContent;
                button.textContent = '已复制';
                setTimeout(() => { button.textContent = oldText; }, 1400);
            } catch (_) {
                window.prompt('复制下面的命令：', button.dataset.copy || '');
            }
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || '确认继续？')) event.preventDefault();
        });
    });

    const ruleDialog = document.getElementById('rule-dialog');
    const ruleForm = document.getElementById('rule-form');
    const openRule = (data = null) => {
        if (!ruleDialog || !ruleForm) return;
        ruleForm.reset();
        ruleForm.elements.id.value = data?.id || '';
        ruleForm.elements.name.value = data?.name || '';
        ruleForm.elements.group_id.value = data?.group || '';
        ruleForm.elements.listen_port.value = data?.listen || '';
        ruleForm.elements.target_ipv4.value = data?.target || '';
        ruleForm.elements.target_port.value = data?.targetPort || '443';
        ruleForm.elements.enabled.checked = data ? data.enabled === '1' : true;
        const title = ruleDialog.querySelector('[data-rule-title]');
        if (title) title.textContent = data ? '编辑转发规则' : '添加转发规则';
        ruleDialog.showModal();
    };
    document.querySelector('[data-rule-new]')?.addEventListener('click', () => openRule());
    document.querySelectorAll('[data-rule-edit]').forEach((button) => {
        button.addEventListener('click', () => openRule({
            id: button.dataset.id,
            group: button.dataset.group,
            name: button.dataset.name,
            listen: button.dataset.listen,
            target: button.dataset.target,
            targetPort: button.dataset.targetPort,
            enabled: button.dataset.enabled,
        }));
    });

    const flash = document.querySelector('[data-flash]');
    if (flash) setTimeout(() => flash.remove(), 6000);
})();
