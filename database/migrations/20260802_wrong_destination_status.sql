ALTER TABLE pilot_flights
    MODIFY status ENUM('active','completed','wrong_destination','aborted')
        NOT NULL DEFAULT 'active',
    ADD COLUMN landed_airport VARCHAR(10) NULL AFTER arrival_airport,
    ADD COLUMN destination_distance_nm DECIMAL(10,2) NULL AFTER landed_airport;
