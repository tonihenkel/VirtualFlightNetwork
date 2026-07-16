CREATE TABLE IF NOT EXISTS `user_visited_countries` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `country_code` varchar(10) NOT NULL,
  `first_airport_icao` varchar(10) NOT NULL,
  `first_visited_at` datetime NOT NULL DEFAULT current_timestamp,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_country` (`user_id`, `country_code`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_country_code` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
