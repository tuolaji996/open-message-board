<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$postId = (int)($_GET['post'] ?? 0);
$token = (string)($_GET['token'] ?? '');
if ($postId <= 0 || $token === '') {
    flash('验证链接无效。', 'err');
    redirect('/');
}

$hash = hash('sha256', $token);
$stmt = db()->prepare("UPDATE posts SET email_verified_at = NOW(), verify_token_hash = NULL WHERE id = ? AND verify_token_hash = ? AND verify_token_expires_at >= NOW() AND status = 'published'");
$stmt->execute([$postId, $hash]);

if ($stmt->rowCount() > 0) {
    flash('邮箱验证成功。以后可以使用邮件中的删除链接自行删除该留言。');
} else {
    flash('验证链接无效、已过期或已经使用过。', 'err');
}

redirect('/post.php?id=' . $postId);
