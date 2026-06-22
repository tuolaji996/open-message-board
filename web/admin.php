<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (isset($_GET['theme'])) {
    set_site_theme((string)$_GET['theme']);
    $params = $_GET;
    unset($params['theme']);
    redirect('/admin.php' . ($params ? '?' . http_build_query($params) : ''));
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_ok']);
    flash('已退出管理后台。');
    redirect('/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    require_csrf();
    if (admin_check((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        $_SESSION['admin_ok'] = true;
        redirect('/admin.php');
    }
    flash('管理员账号或密码不正确。', 'err');
    redirect('/admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_admin();
    require_csrf();
    delete_post((int)($_POST['post'] ?? 0), 'admin');
    flash('管理员已删除留言。');
    redirect('/admin.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_comment') {
    require_admin();
    require_csrf();
    delete_comment((int)($_POST['comment'] ?? 0), 'admin');
    flash('管理员已删除评论。');
    $returnTo = (string)($_POST['return_to'] ?? '');
    $safeReturn = str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !preg_match('/[\r\n]/', $returnTo);
    redirect($safeReturn ? $returnTo : '/admin.php#posts-admin');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'turnstile') {
    require_admin();
    require_csrf();
    $enabled = !empty($_POST['enabled']);
    $siteKey = clean_text($_POST['site_key'] ?? '', 180);
    $secretKey = clean_text($_POST['secret_key'] ?? '', 220);
    if ($secretKey === '') {
        $secretKey = (string)config_value('turnstile.secret_key', '');
    }
    if ($enabled && ($siteKey === '' || $secretKey === '')) {
        flash('启用 Turnstile 需要同时填写 site key 和 secret key。', 'err');
        redirect('/admin.php#turnstile-settings');
    }
    save_turnstile_settings($enabled, $siteKey, $secretKey);
    flash($enabled ? 'Turnstile 已启用并保存。' : 'Turnstile 已关闭，前台会回到备用滑块验证。');
    redirect('/admin.php#turnstile-settings');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'market') {
    require_admin();
    require_csrf();
    $ticker = normalize_ticker((string)($_POST['ticker'] ?? 'NVDA'));
    save_market_settings($ticker);
    flash('行情 Ticker 已更新为 ' . $ticker . '。');
    redirect('/admin.php#market-settings');
}

$flash = flash();
$posts = [];
$commentsByPost = [];
$marketPreview = null;
if (is_admin()) {
    $stmt = db()->query("SELECT p.*,
        (SELECT COUNT(*) FROM images i WHERE i.post_id = p.id) image_count,
        (SELECT COUNT(*) FROM attachments a WHERE a.post_id = p.id AND a.comment_id IS NULL) attachment_count,
        (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id AND c.status = 'published') comment_count
        FROM posts p WHERE p.status = 'published' ORDER BY p.created_at DESC LIMIT 200");
    $posts = $stmt->fetchAll();
    $postIds = array_map(static fn(array $post): int => (int)$post['id'], $posts);
    if ($postIds) {
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));
        $commentStmt = db()->prepare("SELECT c.*
            FROM comments c
            WHERE c.status = 'published' AND c.post_id IN ($placeholders)
            ORDER BY c.created_at DESC, c.id DESC");
        $commentStmt->execute($postIds);
        foreach ($commentStmt->fetchAll() as $comment) {
            $commentsByPost[(int)$comment['post_id']][] = $comment;
        }
    }
    $marketPreview = yahoo_market_quote();
}
$assetVersion = asset_version();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理后台 - <?= h(config_value('site_name')) ?></title>
    <meta name="robots" content="noindex">
    <link rel="stylesheet" href="/social.css?v=<?= h($assetVersion) ?>">
    <script src="/social.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="social-page utility-page <?= h(theme_class()) ?>">
<header class="mobile-topbar detail-mobile-topbar">
    <a class="mobile-brand" href="/"><?= h(config_value('site_name')) ?></a>
    <nav aria-label="快捷导航">
        <a href="<?= h(theme_toggle_url()) ?>"><?= site_theme() === 'light' ? '黑色' : '白色' ?></a>
        <a href="/">留言流</a>
        <a href="/#composer">投稿</a>
    </nav>
</header>
<main class="utility-shell admin-shell">
    <?php if ($flash): ?><div class="toast <?= $flash['type'] === 'err' ? 'is-error' : '' ?>"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php if (!is_admin()): ?>
        <section class="utility-card login-panel">
            <h1>管理员登录</h1>
            <form method="post" class="utility-form">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="login">
                <label>用户名
                    <input name="username" autocomplete="username" required>
                </label>
                <label>密码
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit">登录</button>
            </form>
        </section>
    <?php else: ?>
        <section class="utility-card">
            <div class="admin-head">
                <h1>留言管理</h1>
                <div class="admin-actions">
                    <a class="theme-toggle compact" href="<?= h(theme_toggle_url()) ?>" aria-label="切换黑白主题"><span>黑</span><strong><?= site_theme() === 'light' ? '白' : '黑' ?></strong><span>白</span></a>
                    <a class="open-thread" href="/admin.php?logout=1">退出</a>
                </div>
            </div>
            <section class="admin-settings" id="turnstile-settings">
                <div>
                    <h2>Turnstile 人类验证</h2>
                    <p>如果 Cloudflare 重新生成了 key，把新的 site key 和 secret key 粘到这里保存。</p>
                </div>
                <form method="post" class="utility-form settings-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="turnstile">
                    <label class="switch-row">
                        <input class="switch-input" type="checkbox" name="enabled" value="1" <?= turnstile_enabled() ? 'checked' : '' ?>>
                        <span class="switch-ui" aria-hidden="true"><span></span></span>
                        <span>启用 Cloudflare Turnstile</span>
                    </label>
                    <label>Site key
                        <input name="site_key" value="<?= h((string)config_value('turnstile.site_key', '')) ?>" autocomplete="off" placeholder="0x4AAAA...">
                    </label>
                    <label>Secret key
                        <input type="password" name="secret_key" autocomplete="new-password" placeholder="留空则保持当前 secret key 不变">
                    </label>
                    <div class="settings-status">
                        当前状态：<?= turnstile_enabled() ? 'Turnstile 已启用' : '备用滑块验证' ?>
                    </div>
                    <button type="submit">保存 Turnstile 设置</button>
                </form>
            </section>
            <section class="admin-settings" id="market-settings">
                <div>
                    <h2>左侧行情卡片</h2>
                    <p>前台左侧空位会显示 Yahoo Finance 行情和 1 个月走势。可以切换成任意 Ticker。</p>
                    <?php if ($marketPreview): ?>
                        <div class="settings-status">
                            当前：<?= h($marketPreview['ticker']) ?>
                            <?php if (!$marketPreview['error']): ?>
                                · <?= number_format((float)$marketPreview['price'], 2) ?> <?= h($marketPreview['currency']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="post" class="utility-form settings-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="market">
                    <label>Ticker
                        <input name="ticker" value="<?= h(current_market_ticker()) ?>" autocomplete="off" placeholder="NVDA">
                    </label>
                    <button type="submit">保存行情 Ticker</button>
                </form>
            </section>
            <section class="admin-post-list" id="posts-admin">
                <?php if (!$posts): ?>
                    <p class="muted">暂无留言。</p>
                <?php endif; ?>
                <?php foreach ($posts as $post): ?>
                    <?php
                    $postId = (int)$post['id'];
                    $postAuthor = display_name($post['author_name'] ?? '', '匿名');
                    $postTitle = $post['title'] ?: mb_substr((string)$post['body'], 0, 50) ?: '无标题';
                    $postComments = $commentsByPost[$postId] ?? [];
                    $commentPanelId = 'admin-comments-' . $postId;
                    ?>
                    <article class="admin-post-card" id="admin-post-<?= $postId ?>">
                        <div class="admin-post-main">
                            <div class="admin-post-title">
                                <span>#<?= $postId ?></span>
                                <a href="/post.php?id=<?= $postId ?>" target="_blank" rel="noopener"><?= h($postTitle) ?></a>
                            </div>
                            <form method="post" onsubmit="return confirm('确认删除这条留言？');">
                                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="post" value="<?= $postId ?>">
                                <button type="submit">删除</button>
                            </form>
                        </div>
                        <div class="admin-post-meta">
                            <div class="admin-metric">
                                <small>作者</small>
                                <strong><?= h($postAuthor) ?></strong>
                                <?php if (!empty($post['author_email'])): ?><span class="hint"><?= h($post['author_email']) ?></span><?php endif; ?>
                            </div>
                            <div class="admin-metric">
                                <small>附件</small>
                                <strong><?= (int)$post['image_count'] + (int)($post['attachment_count'] ?? 0) ?></strong>
                            </div>
                            <div class="admin-metric">
                                <small>评论</small>
                                <button
                                    type="button"
                                    class="admin-comment-toggle"
                                    data-admin-comments-toggle
                                    data-comment-count="<?= (int)$post['comment_count'] ?>"
                                    aria-controls="<?= h($commentPanelId) ?>"
                                    aria-expanded="false"
                                    <?= (int)$post['comment_count'] === 0 ? 'disabled' : '' ?>
                                >查看 <?= (int)$post['comment_count'] ?></button>
                            </div>
                            <div class="admin-metric">
                                <small>时间</small>
                                <strong><?= h($post['created_at']) ?></strong>
                            </div>
                        </div>
                        <div class="admin-visitor">
                            <small>访客数据</small>
                            <strong><?= h(ip_binary_to_text($post['created_ip'] ?? null) ?: '未知 IP') ?></strong>
                            <div class="visitor-meta">
                                <span><?= h($post['client_timezone'] ?: '未知时区') ?></span>
                                <span><?= h($post['browser_language'] ?: '未知语言') ?></span>
                                <details>
                                    <summary>浏览器 / User-Agent</summary>
                                    <p><?= h($post['user_agent'] ?: '未知 User-Agent') ?></p>
                                </details>
                            </div>
                        </div>
                        <section class="admin-comment-drawer" id="<?= h($commentPanelId) ?>" hidden>
                            <div class="admin-comment-drawer-head">
                                <strong>评论管理</strong>
                                <span><?= count($postComments) ?> 条评论</span>
                            </div>
                            <?php if (!$postComments): ?>
                                <p class="muted">暂无评论。</p>
                            <?php endif; ?>
                            <?php foreach ($postComments as $comment): ?>
                                <?php $commentAuthor = display_name($comment['author_name'] ?? '', '匿名'); ?>
                                <article class="admin-comment">
                                    <div>
                                        <a href="/post.php?id=<?= $postId ?>#comment-<?= (int)$comment['id'] ?>" target="_blank" rel="noopener">查看原评论</a>
                                        <p><?= render_body(mb_substr((string)$comment['body'], 0, 220)) ?><?= mb_strlen((string)$comment['body']) > 220 ? '...' : '' ?></p>
                                        <span class="hint"><?= h($commentAuthor) ?> · <?= h($comment['created_at']) ?></span>
                                        <div class="visitor-meta">
                                            <span><?= h(ip_binary_to_text($comment['created_ip'] ?? null) ?: '未知 IP') ?></span>
                                            <span><?= h($comment['client_timezone'] ?: '未知时区') ?></span>
                                            <span><?= h($comment['browser_language'] ?: '未知语言') ?></span>
                                            <details>
                                                <summary>User-Agent</summary>
                                                <p><?= h($comment['user_agent'] ?: '未知 User-Agent') ?></p>
                                            </details>
                                        </div>
                                    </div>
                                    <form method="post" onsubmit="return confirm('确认删除这条评论？');">
                                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_comment">
                                        <input type="hidden" name="comment" value="<?= (int)$comment['id'] ?>">
                                        <input type="hidden" name="return_to" value="/admin.php#admin-post-<?= $postId ?>">
                                        <button type="submit">删除</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </section>
                    </article>
                <?php endforeach; ?>
            </section>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
