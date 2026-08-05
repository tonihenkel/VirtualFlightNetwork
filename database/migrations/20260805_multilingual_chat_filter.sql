-- Multilingual and compound-word roots used by the central chat filter.
-- Substring matching also catches compounds such as "hurenschwein".
INSERT IGNORE INTO chat_filter_words (word) VALUES
('hure'), ('hurre'), ('nazi'), ('nazist'),
('тупой'), ('тупая'), ('тупое'), ('тупые'),
('дурак'), ('дура'), ('идиот'), ('идиотка'),
('нацист'), ('нацистка'), ('нацисты');
