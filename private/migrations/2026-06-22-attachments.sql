CREATE TABLE IF NOT EXISTS attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id INT UNSIGNED NOT NULL,
    comment_id INT UNSIGNED NULL,
    kind ENUM('image','pdf') NOT NULL,
    stored_name VARCHAR(190) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attachments_post (post_id),
    KEY idx_attachments_comment (comment_id),
    CONSTRAINT fk_attachments_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
