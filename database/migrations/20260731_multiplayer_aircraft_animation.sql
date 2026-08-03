ALTER TABLE pilot_positions
    ADD COLUMN gear_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER on_ground,
    ADD COLUMN flap_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER gear_ratio,
    ADD COLUMN speedbrake_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER flap_ratio,
    ADD COLUMN thrust_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER speedbrake_ratio,
    ADD COLUMN engine_rpm DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER thrust_ratio,
    ADD COLUMN yoke_pitch_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER engine_rpm,
    ADD COLUMN yoke_roll_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER yoke_pitch_ratio,
    ADD COLUMN yoke_heading_ratio DECIMAL(5,4) NOT NULL DEFAULT 0 AFTER yoke_roll_ratio,
    ADD COLUMN taxi_lights TINYINT(1) NOT NULL DEFAULT 0 AFTER yoke_heading_ratio,
    ADD COLUMN landing_lights TINYINT(1) NOT NULL DEFAULT 0 AFTER taxi_lights,
    ADD COLUMN beacon_lights TINYINT(1) NOT NULL DEFAULT 0 AFTER landing_lights,
    ADD COLUMN strobe_lights TINYINT(1) NOT NULL DEFAULT 0 AFTER beacon_lights,
    ADD COLUMN nav_lights TINYINT(1) NOT NULL DEFAULT 0 AFTER strobe_lights;
