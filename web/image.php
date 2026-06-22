<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM images WHERE id = ?');
$stmt->execute([$id]);
$image = $stmt->fetch();
if (!$image) {
    http_response_code(404);
    exit;
}

$path = rtrim((string)config_value('uploads_dir'), '/') . '/' . $image['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $image['mime_type']);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
readfile($path);
