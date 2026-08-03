ALTER TABLE users
    ADD COLUMN map_waypoint_labels_mode ENUM('always', 'hover')
    NOT NULL DEFAULT 'always'
    AFTER preferred_language;
