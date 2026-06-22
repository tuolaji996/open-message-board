<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if (isset($_GET['theme'])) {
    set_site_theme((string)$_GET['theme']);
    $params = $_GET;
    unset($params['theme']);
    redirect('/post.php' . ($params ? '?' . http_build_query($params) : ''));
}

$id = (int)($_GET['id'] ?? 0);
$post = $id > 0 ? load_post($id) : null;
if (!$post) {
    http_response_code(404);
    echo 'Post not found.';
    exit;
}

$tags = load_post_tags($id);
$images = load_post_images($id);
$attachments = load_post_attachments($id);
$comments = load_comments($id);
$commentAttachments = load_comment_attachments(array_map(static fn(array $comment): int => (int)$comment['id'], $comments));
$botToken = turnstile_enabled() ? null : make_bot_challenge();
$assetVersion = asset_version();
$postAuthor = display_name($post['author_name'] ?? '');
$title = ($post['title'] ?: '无标题留言 #' . $post['id']) . ' - ' . config_value('site_name');
$description = mb_substr(trim((string)$post['body']), 0, 150) ?: 'User-submitted message board post';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <meta name="description" content="<?= h($description) ?>">
    <meta name="keywords" content="<?= keywords_for($post, $tags) ?>">
    <link rel="canonical" href="<?= h(site_url('/post.php?id=' . $id)) ?>">
    <meta property="og:title" content="<?= h($title) ?>">
    <meta property="og:description" content="<?= h($description) ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?= h(site_url('/post.php?id=' . $id)) ?>">
    <?php if ($images): ?><meta property="og:image" content="<?= h(site_url('/image.php?id=' . (int)$images[0]['id'])) ?>"><?php endif; ?>
    <link rel="stylesheet" href="/social.css?v=<?= h($assetVersion) ?>">
    <?php if (turnstile_enabled()): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
    <script src="/social.js?v=<?= h($assetVersion) ?>" defer></script>
</head>
<body class="social-page detail-page <?= h(theme_class()) ?>">
<header class="mobile-topbar detail-mobile-topbar">
    <a class="mobile-brand" href="/"><?= h(config_value('site_name')) ?></a>
    <nav aria-label="快捷导航">
        <a href="<?= h(theme_toggle_url()) ?>"><?= site_theme() === 'light' ? '黑色' : '白色' ?></a>
        <a href="/">留言流</a>
        <a href="/#composer">投稿</a>
    </nav>
</header>
<main class="detail-shell">
    <header class="detail-topbar">
        <a class="back-link" href="/">← 返回留言流</a>
        <div class="detail-top-actions">
            <a class="theme-toggle compact" href="<?= h(theme_toggle_url()) ?>" aria-label="切换黑白主题"><span>黑</span><strong><?= site_theme() === 'light' ? '白' : '黑' ?></strong><span>白</span></a>
            <a class="primary-pill small-pill" href="/#composer">继续投稿</a>
        </div>
    </header>

    <?php $flash = flash(); if ($flash): ?><div class="toast <?= $flash['type'] === 'err' ? 'is-error' : '' ?>"><?= h($flash['message']) ?></div><?php endif; ?>

    <article class="thread-detail">
        <div class="avatar detail-avatar"><?= h(mb_substr($postAuthor, 0, 1)) ?></div>
        <div class="thread-body">
            <div class="post-meta">
                <strong><?= h($postAuthor) ?></strong>
                <span><?= h(date('Y-m-d H:i', strtotime($post['created_at']))) ?></span>
                <?php if (!empty($post['author_email'])): ?><span><?= $post['email_verified_at'] ? '邮箱已验证' : '邮箱未验证' ?></span><?php endif; ?>
                <?php if ($images): ?><span><?= count($images) ?> 张图片附件</span><?php endif; ?>
                <?php if ($attachments): ?><span><?= count($attachments) ?> 个文件附件</span><?php endif; ?>
            </div>
            <h1><?= h($post['title'] ?: '无标题留言 #' . $post['id']) ?></h1>
            <div class="detail-content"><?= render_body((string)$post['body']) ?></div>

            <?php if ($tags): ?>
                <div class="tag-row detail-tags"><?php foreach ($tags as $t): ?><a class="tag" href="/?tag=<?= rawurlencode($t) ?>">#<?= h($t) ?></a><?php endforeach; ?></div>
            <?php endif; ?>

            <?php if ($images): ?>
                <div class="attachment-grid">
                    <?php foreach ($images as $image): ?>
                        <a class="attachment-tile" href="/image.php?id=<?= (int)$image['id'] ?>" target="_blank" rel="noopener">
                            <img src="/image.php?id=<?= (int)$image['id'] ?>" alt="<?= h($image['original_name']) ?>" loading="lazy">
                            <span><?= h($image['original_name']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($attachments): ?>
                <div class="file-attachment-grid">
                    <?php foreach ($attachments as $attachment): ?>
                        <a class="file-attachment <?= $attachment['kind'] === 'pdf' ? 'is-pdf' : '' ?>" href="/attachment.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener">
                            <span><?= $attachment['kind'] === 'pdf' ? 'PDF' : 'IMG' ?></span>
                            <strong><?= h($attachment['original_name']) ?></strong>
                            <small><?= number_format(((int)$attachment['byte_size']) / 1024 / 1024, 2) ?> MB</small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="detail-actions">
                <a class="open-thread" href="/">返回公开留言</a>
                <a class="open-thread" href="#commentForm">回复这条</a>
                <a class="open-thread" href="/#composer">补充一条留言</a>
            </div>
        </div>
    </article>

    <section class="comments-panel" id="comments">
        <div class="comments-head">
            <div>
                <p class="overline">Replies</p>
                <h2>评论回复 <span><?= count($comments) ?></span></h2>
            </div>
            <a class="open-thread" href="#commentForm">写回复</a>
        </div>

        <div class="comment-list">
            <?php if (!$comments): ?>
                <div class="empty-comments">还没有评论。可以先补充一个观察、问题或图片说明。</div>
            <?php endif; ?>
            <?php foreach ($comments as $comment): ?>
                <?php $commentAuthor = display_name($comment['author_name'] ?? ''); ?>
                <?php $parentAuthor = !empty($comment['parent_id']) ? display_name($comment['parent_author_name'] ?? '', '原评论已删除') : ''; ?>
                <article class="comment-card" id="comment-<?= (int)$comment['id'] ?>">
                    <div class="avatar small"><?= h(mb_substr($commentAuthor, 0, 1)) ?></div>
                    <div class="comment-body">
                        <div class="post-meta">
                            <strong><?= h($commentAuthor) ?></strong>
                            <span><?= h(date('Y-m-d H:i', strtotime($comment['created_at']))) ?></span>
                            <?php if (!empty($comment['parent_id'])): ?><span>回复 @<?= h($parentAuthor) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($comment['parent_id'])): ?><div class="reply-context">↳ 回复 <?= h($parentAuthor) ?></div><?php endif; ?>
                        <div class="comment-text"><?= render_body((string)$comment['body']) ?></div>
                        <?php $attachmentsForComment = $commentAttachments[(int)$comment['id']] ?? []; ?>
                        <?php if ($attachmentsForComment): ?>
                            <div class="comment-attachments">
                                <?php foreach ($attachmentsForComment as $attachment): ?>
                                    <?php if ($attachment['kind'] === 'image'): ?>
                                        <a class="comment-image" href="/attachment.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener">
                                            <img src="/attachment.php?id=<?= (int)$attachment['id'] ?>" alt="<?= h($attachment['original_name']) ?>" loading="lazy">
                                        </a>
                                    <?php else: ?>
                                        <a class="file-attachment is-pdf compact" href="/attachment.php?id=<?= (int)$attachment['id'] ?>" target="_blank" rel="noopener">
                                            <span>PDF</span>
                                            <strong><?= h($attachment['original_name']) ?></strong>
                                        </a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="comment-actions">
                            <button type="button" class="comment-reply" data-reply-id="<?= (int)$comment['id'] ?>" data-reply-author="<?= h($commentAuthor) ?>">回复</button>
                            <?php if (is_admin()): ?>
                                <form method="post" action="/admin.php" onsubmit="return confirm('确认删除这条评论？');">
                                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="comment" value="<?= (int)$comment['id'] ?>">
                                    <input type="hidden" name="return_to" value="/post.php?id=<?= (int)$id ?>#comments">
                                    <button type="submit">删除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <form action="/comment.php" method="post" enctype="multipart/form-data" id="commentForm" class="comment-form" data-human-form>
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="post_id" value="<?= (int)$id ?>">
            <input type="hidden" name="parent_id" id="commentParentId" value="">
            <input type="hidden" name="client_timezone" value="">
            <input type="hidden" name="browser_language" value="">
            <?php if (!turnstile_enabled()): ?>
                <input type="hidden" name="bot_token" value="<?= h((string)$botToken) ?>">
                <input type="hidden" name="bot_verified" value="0">
            <?php endif; ?>
            <input name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">

            <div class="reply-target" id="replyTarget" hidden>
                <span></span>
                <button type="button" id="cancelReply">取消</button>
            </div>
            <textarea name="body" maxlength="3000" placeholder="写下你的补充、反驳、经历或提醒。也可以只上传图片或 PDF。"></textarea>
            <div class="composer-meta">
                <input name="author_name" maxlength="80" placeholder="名字可选">
                <input name="author_email" maxlength="190" type="email" placeholder="邮箱可选">
            </div>
            <div class="dropzone asset-dropzone comment-asset-dropzone" id="commentAssetDropzone" tabindex="0" role="button" aria-label="上传评论图片或 PDF">
                <input class="file-input" id="commentAssetInput" type="file" name="comment_attachments[]" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.pdf" multiple>
                <span class="drop-icon">+</span>
                <strong>添加图片或 PDF 附件</strong>
                <small id="commentUploadMeta">图片最多 8 张、单张 5MB；PDF 最多 4 个、单个 15MB</small>
            </div>
            <div class="upload-preview comment-upload-preview" id="commentAssetPreview" aria-live="polite"></div>
            <div class="composer-footer comment-footer">
                <?php if (turnstile_enabled()): ?>
                    <div class="turnstile-card">
                        <div class="cf-turnstile" data-sitekey="<?= h(turnstile_site_key()) ?>" data-theme="<?= h(site_theme()) ?>" data-size="flexible" data-callback="onHumanVerified" data-expired-callback="onHumanExpired" data-error-callback="onHumanExpired"></div>
                    </div>
                <?php else: ?>
                    <div class="slider-check" id="botSlider">
                        <div class="slider-track">
                            <div class="slider-fill"></div>
                            <button type="button" class="slider-thumb" aria-label="拖动完成验证">→</button>
                            <span class="slider-status">拖动验证</span>
                        </div>
                    </div>
                <?php endif; ?>
                <button type="submit" id="commentSubmit" data-submit-button data-waiting-text="完成验证后回复" data-ready-text="发布回复" data-pending-text="正在发布回复..." disabled>完成验证后回复</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>
