<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}

require_csrf();

$postId = (int)($_POST['post_id'] ?? 0);
$redirect = '/post.php?id=' . $postId . '#comments';

if ($postId <= 0 || !load_post($postId)) {
    flash('留言不存在。', 'err');
    redirect('/');
}

if (trim((string)($_POST['website'] ?? '')) !== '') {
    flash('回复未通过，请重新提交。', 'err');
    redirect($redirect);
}

if (!require_human_verification()) {
    flash('请先完成人类验证再回复。', 'err');
    redirect($redirect);
}

$parentId = (int)($_POST['parent_id'] ?? 0);
$body = clean_body($_POST['body'] ?? '');
$mixedAttachments = !empty($_FILES['comment_attachments']) ? split_uploads_by_kind($_FILES['comment_attachments']) : null;
$hasCommentImages = has_uploads($_FILES['comment_images'] ?? null);
$hasCommentPdfs = has_uploads($_FILES['comment_pdfs'] ?? null);
$hasMixedAttachments = $mixedAttachments && ($mixedAttachments['has_images'] || $mixedAttachments['has_pdfs']);
if (mb_strlen($body) > 3000) {
    $body = mb_substr($body, 0, 3000);
}
$authorName = clean_text($_POST['author_name'] ?? '', 80);
$authorEmail = clean_text($_POST['author_email'] ?? '', 190);

if ($body === '' && !$hasCommentImages && !$hasCommentPdfs && !$hasMixedAttachments) {
    flash('回复内容不能为空。', 'err');
    redirect($redirect);
}

if ($authorEmail !== '' && !filter_var($authorEmail, FILTER_VALIDATE_EMAIL)) {
    flash('邮箱格式不正确。', 'err');
    redirect($redirect);
}

$authorName = public_author_name($authorName);

try {
    $commentId = create_comment($postId, $parentId > 0 ? $parentId : null, $body, $authorName, $authorEmail);
    if (!empty($_FILES['comment_images'])) {
        save_attachments($postId, $commentId, $_FILES['comment_images'], 'image');
    }
    if (!empty($_FILES['comment_pdfs'])) {
        save_attachments($postId, $commentId, $_FILES['comment_pdfs'], 'pdf');
    }
    if ($mixedAttachments) {
        if ($mixedAttachments['has_images']) {
            save_attachments($postId, $commentId, $mixedAttachments['images'], 'image');
        }
        if ($mixedAttachments['has_pdfs']) {
            save_attachments($postId, $commentId, $mixedAttachments['pdfs'], 'pdf');
        }
    }
} catch (Throwable $e) {
    error_log('Comment failed: ' . $e->getMessage());
    flash($e instanceof RuntimeException ? $e->getMessage() : '回复失败，请稍后再试。', 'err');
    redirect($redirect);
}

flash('回复已发布。');
redirect('/post.php?id=' . $postId . '#comment-' . $commentId);
