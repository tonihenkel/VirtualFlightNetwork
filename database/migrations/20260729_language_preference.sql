ALTER TABLE users
    ADD COLUMN IF NOT EXISTS preferred_language VARCHAR(10) NULL DEFAULT NULL
    AFTER country_code;
