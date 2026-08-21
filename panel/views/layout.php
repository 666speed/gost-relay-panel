<?php $flashMessage = pull_flash(); ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '控制台') ?> · GOST Relay</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="/">
            <span class="brand-mark">GR</span>
            <span><strong>GOST Relay</strong><small>TCP CONTROL</small></span>
        </a>
        <nav class="nav-list" aria-label="主导航">
            <a class="nav-item <?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" href="/"><span>⌂</span>运行概览</a>
            <a class="nav-item <?= ($active ?? '') === 'groups' ? 'active' : '' ?>" href="/groups"><span>▦</span>服务器组</a>
            <a class="nav-item <?= ($active ?? '') === 'nodes' ? 'active' : '' ?>" href="/nodes"><span>◉</span>节点状态</a>
            <a class="nav-item <?= ($active ?? '') === 'forwards' ? 'active' : '' ?>" href="/forwards"><span>⇄</span>TCP 转发</a>
        </nav>
        <div class="sidebar-note">
            <span class="status-dot"></span>
            <div><strong>纯 TCP / IPv4</strong><small>无 SNI · 无 UDP · 无 IPv6</small></div>
        </div>
        <form method="post" action="/logout" class="logout-form">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="nav-item button-reset"><span>↪</span>退出 <?= e($admin['username'] ?? '') ?></button>
        </form>
    </aside>
    <main class="main-area">
        <header class="topbar">
            <button class="mobile-menu button-reset" type="button" data-menu-toggle aria-label="打开导航">☰</button>
            <div><p class="eyebrow">GOST RELAY CONTROL</p><h1><?= e($title ?? '控制台') ?></h1></div>
            <div class="topbar-meta"><span class="status-dot"></span><span>主控运行中</span></div>
        </header>
        <section class="page-content">
            <?php if ($flashMessage !== null): ?>
                <div class="alert <?= $flashMessage['type'] === 'error' ? 'alert-error' : 'alert-success' ?>" data-flash>
                    <?= e($flashMessage['message']) ?>
                </div>
            <?php endif; ?>
            <?= $content ?>
        </section>
    </main>
</div>
<script src="/assets/app.js" defer></script>
</body>
</html>
