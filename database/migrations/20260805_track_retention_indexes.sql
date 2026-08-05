CREATE INDEX idx_pilot_tracks_session_callsign_id
    ON pilot_tracks (session_token, callsign, id);

CREATE INDEX idx_pilot_positions_last_update
    ON pilot_positions (last_update);
