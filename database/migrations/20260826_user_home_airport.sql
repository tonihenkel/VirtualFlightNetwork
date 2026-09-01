ALTER TABLE users
    ADD COLUMN IF NOT EXISTS home_airport_icao VARCHAR(14) NOT NULL DEFAULT 'ZZZZ' AFTER country_code;

ALTER TABLE users
    MODIFY COLUMN home_airport_icao VARCHAR(14) NOT NULL DEFAULT 'ZZZZ';

UPDATE users
SET home_airport_icao = 'ZZZZ'
WHERE home_airport_icao IS NULL OR TRIM(home_airport_icao) = '';
