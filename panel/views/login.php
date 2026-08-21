<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · GOST Relay</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <div class="brand-mark">GR</div>
    <p class="eyebrow">GOST RELAY CONTROL</p>
    <h1>登录主控</h1>
    <p class="muted">管理服务器组、节点与 TCP IPv4 转发。</p>
    <?php if ($installed): ?><div class="alert alert-success">初始化完成，请登录。</div><?php endif; ?>
    <?php if ($flash !== null): ?><div class="alert alert-error"><?= e($flash['message']) ?></div><?php endif; ?>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>管理员账号<input name="username" autocomplete="username" required autofocus></label>
        <label>密码<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button primary" type="submit">进入控制台</button>
    </form>
</main>
</body>
</html>
