<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (isset($_GET['theme'])) {
    set_site_theme((string)$_GET['theme']);
    $params = $_GET;
    unset($params['theme']);
    redirect('/delete.php' . ($params ? '?' . http_build_query($params) : ''));
}

$postId = (int)($_GET['post'] ?? $_POST['post'] ?? 0);
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$post = $postId > 0 ? load_post($postId) : null;
if (!$post) {
    http_response_code(404);
    echo 'Post not found.';
    exit;
}

$canDelete = $token !== ''
    && !empty($post['author_email'])
    && !empty($post['email_verified_at'])
    && hash_equals((string)$post['delete_token_hash'], hash('sha256', $token));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$canDelete) {
        flash('删除链接无效，或邮箱尚未验证。请联系管理员删除。', 'err');
        redirect('/post.php?id=' . $postId);
    }
    delete_post($postId, 'email-owner');
    flash('留言已删除。');
    redirect('/');
}

$title = '删除留言 - ' . config_value('site_name');
$assetVersion = asset_version();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/social.css?v=<?= h($assetVersion) ?>">
</head>
<body class="social-page utility-page <?= h(theme_class()) ?>">
<header class="mobile-topbar detail-mobile-topbar">
    <a class="mobile-brand" href="/"><?= h(config_value('site_name')) ?></a>
    <nav aria-label="快捷导航">
        <a href="<?= h(theme_toggle_url()) ?>"><?= site_theme() === 'light' ? '黑色' : '白色' ?></a>
        <a href="/">留言流</a>
    </nav>
</header>
<main class="utility-shell">
    <section class="utility-card">
        <h1>删除留言</h1>
        <?php if ($canDelete): ?>
            <p>确认删除这条留言：<?= h($post['title'] ?: '无标题留言 #' . $post['id']) ?></p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="post" value="<?= (int)$postId ?>">
                <input type="hidden" name="token" value="<?= h($token) ?>">
                <button type="submit">确认删除</button>
            </form>
        <?php else: ?>
            <p>删除链接无效，或邮箱尚未验证。未提供邮箱的留言只能由管理员删除。</p>
            <a class="open-thread" href="/post.php?id=<?= (int)$postId ?>">返回留言</a>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
