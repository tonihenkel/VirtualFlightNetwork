ALTER TABLE pilot_positions
    ADD COLUMN ai_destination_icao VARCHAR(8) NOT NULL DEFAULT ''
    AFTER ai_controls_aircraft;
