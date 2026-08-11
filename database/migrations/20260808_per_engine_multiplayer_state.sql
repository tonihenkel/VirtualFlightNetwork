ALTER TABLE pilot_positions
    ADD COLUMN engine_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER engine_rpm,
    ADD COLUMN engine_thrust_ratios JSON NULL AFTER engine_count,
    ADD COLUMN engine_rpms JSON NULL AFTER engine_thrust_ratios;
