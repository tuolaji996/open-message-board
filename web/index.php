<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (isset($_GET['theme'])) {
    set_site_theme((string)$_GET['theme']);
    $params = $_GET;
    unset($params['theme']);
    redirect('/' . ($params ? '?' . http_build_query($params) : ''));
}

$assetVersion = asset_version();
$q = clean_text($_GET['q'] ?? '', 80);
$tag = clean_text($_GET['tag'] ?? '', 40);
$imagesOnly = (string)($_GET['images'] ?? '') === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

$where = ["p.status = 'published'"];
$params = [];
$join = '';
if ($q !== '') {
    $where[] = '(p.title LIKE ? OR p.body LIKE ? OR p.seo_keywords LIKE ?)';
    $needle = '%' . $q . '%';
    array_push($params, $needle, $needle, $needle);
}
if ($tag !== '') {
    $join = ' INNER JOIN post_hashtags ph ON ph.post_id = p.id INNER JOIN hashtags h ON h.id = ph.hashtag_id ';
    $where[] = 'h.slug = ?';
    $params[] = tag_slug($tag);
}
if ($imagesOnly) {
    $where[] = 'EXISTS (SELECT 1 FROM images ei WHERE ei.post_id = p.id)';
}

$countStmt = db()->prepare('SELECT COUNT(DISTINCT p.id) FROM posts p' . $join . ' WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$sql = 'SELECT DISTINCT p.*, (SELECT COUNT(*) FROM images i WHERE i.post_id = p.id) image_count
        FROM posts p' . $join . ' WHERE ' . implode(' AND ', $where) . '
        ORDER BY p.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
$stmt = db()->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

$tagRows = db()->query("SELECT h.tag, h.slug, COUNT(*) total FROM hashtags h INNER JOIN post_hashtags ph ON ph.hashtag_id = h.id INNER JOIN posts p ON p.id = ph.post_id AND p.status = 'published' GROUP BY h.id ORDER BY total DESC, h.tag LIMIT 24")->fetchAll();
$stats = db()->query("SELECT
    (SELECT COUNT(*) FROM posts WHERE status = 'published') AS posts_count,
    (SELECT COUNT(*) FROM images i INNER JOIN posts p ON p.id = i.post_id AND p.status = 'published') AS images_count,
    (SELECT COUNT(DISTINCT h.id) FROM hashtags h INNER JOIN post_hashtags ph ON ph.hashtag_id = h.id INNER JOIN posts p ON p.id = ph.post_id AND p.status = 'published') AS tags_count")->fetch();
$market = yahoo_market_quote();
$flash = flash();
$botToken = make_bot_challenge();

$title = config_value('site_name') . ' - PHP Message Board';
$desc = 'A self-hosted message board for posts, comments, hashtags, SEO keywords, optional contact details, image/PDF attachments, and moderation.';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <meta name="description" content="<?= h($desc) ?>">
    <meta name="keywords" content="<?= keywords_for(null, [$tag]) ?>">
    <link rel="canonical" href="<?= h(site_url('/')) ?>">
    <meta property="og:title" content="<?= h($title) ?>">
    <meta property="og:description" content="<?= h($desc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= h(site_url('/')) ?>">
    <link rel="stylesheet" href="/social.css?v=<?= h($assetVersion) ?>">
    <?php if (turnstile_enabled()): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
    <script src="/social.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="social-page <?= h(theme_class()) ?>">
<header class="mobile-topbar">
    <a class="mobile-brand" href="/"><?= h(config_value('site_name')) ?></a>
    <nav aria-label="快捷导航">
        <a href="<?= h(theme_toggle_url()) ?>"><?= site_theme() === 'light' ? '黑色' : '白色' ?></a>
        <a href="#composer">投稿</a>
        <a href="/admin.php">管理</a>
    </nav>
</header>

<main class="social-shell">
    <aside class="left-rail">
        <a class="brand-lockup" href="/">
            <span class="brand-mark">M</span>
            <span>
                <strong><?= h(config_value('site_name')) ?></strong>
                <small>Community board</small>
            </span>
        </a>
        <nav class="rail-nav" aria-label="主导航">
            <a class="is-active" href="/" aria-current="page"><span></span>留言流</a>
            <a class="nav-compose" href="#composer"><span></span>发布留言</a>
            <a href="/admin.php"><span></span>管理员</a>
        </nav>
        <a class="theme-toggle" href="<?= h(theme_toggle_url()) ?>" aria-label="切换黑白主题">
            <span>黑</span>
            <strong><?= site_theme() === 'light' ? '白色' : '黑色' ?></strong>
            <span>白</span>
        </a>
        <div class="rail-stats">
            <div><strong><?= (int)$stats['posts_count'] ?></strong><span>留言</span></div>
            <div><strong><?= (int)$stats['images_count'] ?></strong><span>图片</span></div>
            <div><strong><?= (int)$stats['tags_count'] ?></strong><span>标签</span></div>
        </div>
        <a class="primary-pill" href="#composer">发一条</a>
        <section class="market-card <?= ((float)$market['change']) >= 0 ? 'is-up' : 'is-down' ?>">
            <div class="market-top">
                <span>Yahoo Finance</span>
                <strong><?= h($market['ticker']) ?></strong>
            </div>
            <?php if ($market['error']): ?>
                <p class="market-error"><?= h($market['error']) ?></p>
            <?php else: ?>
                <div class="market-price">
                    <strong><?= number_format((float)$market['price'], 2) ?></strong>
                    <span><?= h($market['currency']) ?></span>
                </div>
                <div class="market-change">
                    <?= ((float)$market['change']) >= 0 ? '+' : '' ?><?= number_format((float)$market['change'], 2) ?>
                    / <?= ((float)$market['change_percent']) >= 0 ? '+' : '' ?><?= number_format((float)$market['change_percent'], 2) ?>%
                </div>
                <?php if ($market['sparkline']): ?>
                    <svg class="market-spark" viewBox="0 0 100 100" role="img" aria-label="<?= h($market['ticker']) ?> 1 month trend" preserveAspectRatio="none">
                        <polyline points="<?= h($market['sparkline']) ?>"></polyline>
                    </svg>
                <?php endif; ?>
                <div class="market-meta">
                    <span><?= h($market['exchange'] ?: 'Market') ?></span>
                    <span><?= h($market['as_of']) ?></span>
                </div>
            <?php endif; ?>
        </section>
    </aside>

    <section class="timeline">
        <div class="timeline-head">
            <div>
                <p class="overline">Posts / Comments / Hashtags</p>
                <h1>公开留言板</h1>
            </div>
            <form class="top-search" method="get" action="/">
                <input name="q" value="<?= h($q) ?>" placeholder="搜关键词、标题、内容">
                <button type="submit">搜索</button>
            </form>
        </div>

        <nav class="feed-tabs" aria-label="留言筛选">
            <a class="<?= !$imagesOnly && !$q && !$tag ? 'is-active' : '' ?>" href="/">全部</a>
            <a class="<?= $imagesOnly ? 'is-active' : '' ?>" href="/?images=1">有图片</a>
            <a href="#composer">我要留言</a>
            <a href="/?q=feedback">Feedback</a>
            <a href="/?q=community">Community</a>
        </nav>

        <?php if ($flash): ?>
            <div class="toast <?= $flash['type'] === 'err' ? 'is-error' : '' ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($q || $tag): ?>
            <div class="filter-chip">
                <span><?= $q ? '关键词：' . h($q) : '' ?><?= $tag ? ' #' . h($tag) : '' ?><?= $imagesOnly ? ' 有图片' : '' ?></span>
                <a href="/">清除</a>
            </div>
        <?php endif; ?>

        <section class="composer-card" id="composer">
            <div class="avatar">M</div>
            <form action="/submit.php" method="post" enctype="multipart/form-data" id="postForm" class="composer" data-human-form>
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="client_timezone" value="">
                <input type="hidden" name="browser_language" value="">
                <?php if (!turnstile_enabled()): ?>
                    <input type="hidden" name="bot_token" value="<?= h($botToken) ?>">
                    <input type="hidden" name="bot_verified" id="botVerified" value="0">
                <?php endif; ?>
                <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">

                <input class="composer-title" name="title" maxlength="180" placeholder="标题可选：比如反馈、公告、问题或经验分享">
                <textarea name="body" maxlength="12000" placeholder="写下内容、背景、时间线或补充说明。可以直接加 #feedback #question #community"></textarea>

                <div class="composer-meta">
                    <input name="author_name" maxlength="80" placeholder="名字可选">
                    <input name="author_email" maxlength="190" type="email" placeholder="邮箱可选，验证后可自助删除">
                </div>
                <div class="composer-meta">
                    <input name="hashtags" maxlength="240" placeholder="#Hashtag，用空格或逗号分隔">
                    <input name="seo_keywords" maxlength="500" placeholder="SEO keyword，可选">
                </div>

                <div class="dropzone asset-dropzone" id="assetDropzone" tabindex="0" role="button" aria-label="上传图片或 PDF 附件">
                    <input class="file-input" id="assetInput" type="file" name="attachments[]" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.pdf" multiple>
                    <span class="drop-icon">+</span>
                    <strong>拖拽图片或 PDF 到这里，或点击选择文件</strong>
                    <small id="uploadMeta">图片最多 8 张、单张 5MB；PDF 最多 4 个、单个 15MB</small>
                </div>
                <div class="upload-preview" id="assetPreview" aria-live="polite"></div>

                <div class="composer-footer">
                    <?php if (turnstile_enabled()): ?>
                        <div class="turnstile-card">
                            <div class="cf-turnstile" data-sitekey="<?= h(turnstile_site_key()) ?>" data-theme="<?= h(site_theme()) ?>" data-size="flexible" data-callback="onHumanVerified" data-expired-callback="onHumanExpired" data-error-callback="onHumanExpired"></div>
                        </div>
                    <?php else: ?>
                        <div class="slider-check" id="botSlider">
                            <div class="slider-track">
                                <div class="slider-fill"></div>
                                <button type="button" class="slider-thumb" aria-label="拖动完成验证">→</button>
                                <span id="sliderStatus" class="slider-status">拖动验证</span>
                            </div>
                        </div>
                    <?php endif; ?>
                    <button type="submit" id="submitButton" data-submit-button data-waiting-text="完成验证后发布" data-ready-text="发布留言" data-pending-text="正在提交留言..." disabled>完成验证后发布</button>
                </div>
            </form>
        </section>

        <div class="feed-list">
            <?php foreach ($posts as $post): ?>
                <?php $tags = load_post_tags((int)$post['id']); ?>
                <?php $author = display_name($post['author_name'] ?? ''); ?>
                <article class="post-card">
                    <div class="avatar small"><?= h(mb_substr($author, 0, 1)) ?></div>
                    <div class="post-body">
                        <div class="post-meta">
                            <strong><?= h($author) ?></strong>
                            <span><?= h(date('Y-m-d H:i', strtotime($post['created_at']))) ?></span>
                            <?php if ((int)$post['image_count'] > 0): ?><span><?= (int)$post['image_count'] ?> 图</span><?php endif; ?>
                        </div>
                        <h2><a href="/post.php?id=<?= (int)$post['id'] ?>"><?= h($post['title'] ?: '无标题留言 #' . $post['id']) ?></a></h2>
                        <div class="post-text"><?= render_body(mb_substr((string)$post['body'], 0, 620)) ?><?= mb_strlen((string)$post['body']) > 620 ? '...' : '' ?></div>
                        <?php if ($tags): ?>
                            <div class="tag-row"><?php foreach ($tags as $t): ?><a class="tag" href="/?tag=<?= rawurlencode($t) ?>">#<?= h($t) ?></a><?php endforeach; ?></div>
                        <?php endif; ?>
                        <a class="open-thread" href="/post.php?id=<?= (int)$post['id'] ?>">查看详情</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if (!$posts): ?>
            <div class="empty-feed">
                <strong>还没有公开留言</strong>
                <span>第一条可以从问题、反馈、经验或图片附件开始。</span>
            </div>
        <?php endif; ?>

        <?php if ($total > $perPage): ?>
            <div class="pager">
                <?php if ($page > 1): ?><a href="/?page=<?= $page - 1 ?>&q=<?= rawurlencode($q) ?>&tag=<?= rawurlencode($tag) ?>&images=<?= $imagesOnly ? '1' : '0' ?>">上一页</a><?php else: ?><span></span><?php endif; ?>
                <?php if ($offset + $perPage < $total): ?><a href="/?page=<?= $page + 1 ?>&q=<?= rawurlencode($q) ?>&tag=<?= rawurlencode($tag) ?>&images=<?= $imagesOnly ? '1' : '0' ?>">下一页</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="right-rail">
        <section class="side-card">
            <h3>热门 Hashtag</h3>
            <div class="tag-cloud">
                <?php foreach ($tagRows as $row): ?>
                    <a class="tag" href="/?tag=<?= rawurlencode($row['tag']) ?>">#<?= h($row['tag']) ?> <span><?= (int)$row['total'] ?></span></a>
                <?php endforeach; ?>
                <?php if (!$tagRows): ?><span class="muted">暂无标签</span><?php endif; ?>
            </div>
        </section>
        <section class="side-card">
            <h3>投稿建议</h3>
            <ul class="rules-list">
                <li>尽量给出背景、时间、上下文和必要的图片说明。</li>
                <li>邮箱是可选项；填写并验证后可自助删除。</li>
                <li>不要上传身份证、银行卡、住址等敏感隐私。</li>
                <li>页面按用户陈述展示，管理员可删除违规内容。</li>
            </ul>
        </section>
        <section class="side-card tone-card">
            <h3>SEO 关键词</h3>
            <p>站点可配置默认关键词，每条留言也可以填写自定义 SEO keyword。</p>
        </section>
    </aside>
</main>
</body>
</html>
