ALTER TABLE pilot_positions
    ADD COLUMN on_ground TINYINT(1) NOT NULL DEFAULT 0
    AFTER vertical_speed;

CREATE INDEX idx_pilot_positions_multiplayer
    ON pilot_positions (last_update, user_id);

