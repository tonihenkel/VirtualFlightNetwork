ALTER TABLE chat_messages
    ADD COLUMN original_message_text VARCHAR(255) NULL AFTER message_text;

ALTER TABLE chat_messages
    ADD COLUMN was_filtered TINYINT(1) NOT NULL DEFAULT 0 AFTER original_message_text;

CREATE INDEX idx_chat_messages_filtered_created
    ON chat_messages (was_filtered, created_at);
