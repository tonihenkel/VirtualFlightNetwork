CREATE INDEX idx_airports_ident
    ON airports (ident);

CREATE INDEX idx_airports_icao_code
    ON airports (icao_code);

CREATE INDEX idx_airports_gps_code
    ON airports (gps_code);
