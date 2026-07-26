ALTER TABLE chat_messages
    ADD COLUMN delivery_range_nm DECIMAL(8,2) NULL AFTER sender_longitude;

