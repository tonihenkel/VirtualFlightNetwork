ALTER TABLE pilot_positions
    ADD COLUMN slat_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER nav_lights,
    ADD COLUMN wing_sweep_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER slat_ratio,
    ADD COLUMN thrust_reverser_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER wing_sweep_ratio,
    ADD COLUMN nose_wheel_angle DECIMAL(7,3) NOT NULL DEFAULT 0 AFTER thrust_reverser_ratio,
    ADD COLUMN tire_rotation_rad_sec DECIMAL(9,3) NOT NULL DEFAULT 0 AFTER nose_wheel_angle,
    ADD COLUMN transponder_mode TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER tire_rotation_rad_sec;
