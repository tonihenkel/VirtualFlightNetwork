CREATE TABLE IF NOT EXISTS chat_filter_words (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    word VARCHAR(60) NOT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_filter_word (word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT IGNORE INTO chat_filter_words (word) VALUES
('arschloch'), ('bastard'), ('bitch'), ('cunt'), ('drecksau'),
('fick'), ('ficken'), ('fuck'), ('fucker'), ('hurensohn'),
('idiot'), ('motherfucker'), ('nigger'), ('nigga'), ('schlampe'),
('scheiße'), ('scheisse'), ('shit'), ('spast'), ('wichser');
