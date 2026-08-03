ALTER TABLE users
    ADD COLUMN IF NOT EXISTS preferred_language VARCHAR(2) NULL DEFAULT NULL
    AFTER country_code;
