CREATE INDEX idx_pilot_flights_departure_status_completed
    ON pilot_flights (departure_airport, status, completed_at);

CREATE INDEX idx_pilot_flights_arrival_status_completed
    ON pilot_flights (arrival_airport, status, completed_at);
