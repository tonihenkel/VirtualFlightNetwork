ALTER TABLE chat_messages
    MODIFY COLUMN message_type
        ENUM('pilot','staff','system','award','landing')
        NOT NULL DEFAULT 'pilot';

UPDATE chat_messages
SET message_type = 'staff'
WHERE message_type = ''
  AND sender_callsign LIKE 'STAFF:%';
