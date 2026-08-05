ALTER TABLE divisions
    ADD COLUMN join_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
