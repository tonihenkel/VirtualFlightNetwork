ALTER TABLE pilot_positions
    ADD COLUMN ai_controls_aircraft TINYINT(1) NOT NULL DEFAULT 0
    AFTER on_ground;
