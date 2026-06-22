<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT a.*
    FROM attachments a
    INNER JOIN posts p ON p.id = a.post_id
    LEFT JOIN comments c ON c.id = a.comment_id
    WHERE a.id = ?
      AND p.status = 'published'
      AND (a.comment_id IS NULL OR c.status = 'published')");
$stmt->execute([$id]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    exit;
}

$path = rtrim((string)config_value('uploads_dir'), '/') . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$name = preg_replace('/[\r\n"]+/', '', (string)$file['original_name']);
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode($name ?: 'attachment') . '"');
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);
