ALTER TABLE users
    ADD COLUMN ban_expires_at DATETIME NULL AFTER ban_reason,
    ADD COLUMN banned_at DATETIME NULL AFTER ban_expires_at,
    ADD COLUMN banned_by_user_id INT NULL AFTER banned_at;

