CREATE TABLE IF NOT EXISTS chat_spam_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_token VARCHAR(128) NOT NULL,
    message_hash CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_chat_spam_user_created (user_id, created_at),
    KEY idx_chat_spam_repeat (user_id, message_hash, created_at),
    KEY idx_chat_spam_cleanup (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
