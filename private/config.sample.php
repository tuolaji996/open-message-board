<?php
declare(strict_types=1);

return [
    'site_name' => 'Open Message Board',
    'base_url' => 'https://example.com',
    'timezone' => 'UTC',
    'asset_version' => 'open-9',
    'db' => [
        'dsn' => 'mysql:host=127.0.0.1;dbname=message_board;charset=utf8mb4',
        'user' => 'message_board_user',
        'pass' => 'CHANGE_ME',
    ],
    'admin' => [
        'username' => 'admin',
        'password_hash' => 'CHANGE_ME',
    ],
    'mail' => [
        'from' => 'noreply@example.com',
        'from_name' => 'Open Message Board',
    ],
    'turnstile' => [
        'enabled' => false,
        'site_key' => '',
        'secret_key' => '',
    ],
    'market' => [
        'ticker' => 'NVDA',
        'cache_seconds' => 300,
    ],
    'uploads_dir' => __DIR__ . '/../uploads',
    'max_images' => 8,
    'max_image_bytes' => 5 * 1024 * 1024,
    'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'max_pdfs' => 4,
    'max_pdf_bytes' => 15 * 1024 * 1024,
    'allowed_pdf_mimes' => ['application/pdf'],
    'reserved_names' => ['admin', 'moderator', 'system'],
    'reserved_name_replacement' => 'ReservedName',
    'default_keywords' => [
        'message board',
        'community message board',
        'user posts',
        'comments',
        'hashtags',
        'file attachments',
        'moderation',
        'Cloudflare Turnstile',
    ],
];
