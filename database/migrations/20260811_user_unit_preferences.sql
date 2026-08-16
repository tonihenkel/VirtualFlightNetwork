ALTER TABLE users
    ADD COLUMN altitude_unit ENUM('ft', 'm') NOT NULL DEFAULT 'ft',
    ADD COLUMN distance_unit ENUM('nm', 'km') NOT NULL DEFAULT 'nm',
    ADD COLUMN speed_unit ENUM('kt', 'kmh') NOT NULL DEFAULT 'kt';
