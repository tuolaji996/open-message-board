<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}

require_csrf();

if (trim((string)($_POST['website'] ?? '')) !== '') {
    flash('提交未通过，请重新发布。', 'err');
    redirect('/');
}

if (!require_human_verification()) {
    flash('请先完成人类验证再发布。', 'err');
    redirect('/');
}

$title = clean_text($_POST['title'] ?? '', 180);
$body = clean_body($_POST['body'] ?? '');
$authorName = clean_text($_POST['author_name'] ?? '', 80);
$authorEmail = clean_text($_POST['author_email'] ?? '', 190);
$hashtags = clean_text($_POST['hashtags'] ?? '', 240);
$seoKeywords = clean_text($_POST['seo_keywords'] ?? '', 500);

if ($title === '' && $body === '') {
    flash('标题和内容至少填写一个。', 'err');
    redirect('/');
}

if ($authorEmail !== '' && !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
    flash('邮箱格式不正确。', 'err');
    redirect('/');
}

$authorName = public_author_name($authorName);

$verifyToken = $authorEmail !== '' ? bin2hex(random_bytes(32)) : null;
$deleteToken = bin2hex(random_bytes(32));
$verifyHash = $verifyToken ? hash('sha256', $verifyToken) : null;
$deleteHash = hash('sha256', $deleteToken);

$pdo = db();
$pdo->beginTransaction();
try {
    $mixedAttachments = !empty($_FILES['attachments']) ? split_uploads_by_kind($_FILES['attachments']) : null;
    $stmt = $pdo->prepare('INSERT INTO posts (title, body, author_name, author_email, seo_keywords, delete_token_hash, verify_token_hash, verify_token_expires_at, created_ip, user_agent, client_timezone, browser_language) VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY), ?, ?, ?, ?)');
    $stmt->execute([
        $title ?: null,
        $body ?: null,
        $authorName ?: null,
        $authorEmail ?: null,
        $seoKeywords ?: null,
        $deleteHash,
        $verifyHash,
        client_ip_binary(),
        request_user_agent(),
        request_client_timezone() ?: null,
        request_browser_language() ?: null,
    ]);
    $postId = (int)$pdo->lastInsertId();
    upsert_tags($postId, extract_tags($body, $hashtags));
    if (!empty($_FILES['images'])) {
        save_images($postId, $_FILES['images']);
    }
    if (!empty($_FILES['pdfs'])) {
        save_attachments($postId, null, $_FILES['pdfs'], 'pdf');
    }
    if ($mixedAttachments) {
        if ($mixedAttachments['has_images']) {
            save_images($postId, $mixedAttachments['images']);
        }
        if ($mixedAttachments['has_pdfs']) {
            save_attachments($postId, null, $mixedAttachments['pdfs'], 'pdf');
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Submit failed: ' . $e->getMessage());
    @file_put_contents(dirname(__DIR__) . '/private/app.log', '[' . date('c') . '] Submit failed: ' . $e::class . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    flash($e instanceof RuntimeException ? $e->getMessage() : '提交失败，请稍后再试。', 'err');
    redirect('/');
}

if ($authorEmail !== '' && $verifyToken !== null) {
    email_link_notice($authorEmail, $verifyToken, $deleteToken, $postId);
    flash('留言已发布。验证邮件已发送；验证邮箱后，你可以使用邮件里的链接自行删除。');
} else {
    flash('留言已发布。未提供邮箱的留言只能由管理员删除。');
}

redirect('/post.php?id=' . $postId);
