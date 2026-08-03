ALTER TABLE user_sessions
    ADD COLUMN is_spectator TINYINT(1) NOT NULL DEFAULT 0
    AFTER is_invisible;

CREATE INDEX idx_user_sessions_spectator
    ON user_sessions (is_active, is_spectator);
