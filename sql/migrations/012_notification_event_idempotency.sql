USE `capstone_fuzzy_eval`;

SET @has_event_key = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'notifications'
    AND column_name = 'event_key'
);
SET @add_event_key = IF(
  @has_event_key = 0,
  'ALTER TABLE `notifications` ADD COLUMN `event_key` CHAR(64) DEFAULT NULL AFTER `link`',
  'DO 0'
);
PREPARE add_event_key_stmt FROM @add_event_key;
EXECUTE add_event_key_stmt;
DEALLOCATE PREPARE add_event_key_stmt;

SET @has_event_index = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'notifications'
    AND index_name = 'uq_notifications_event'
);
SET @add_event_index = IF(
  @has_event_index = 0,
  'ALTER TABLE `notifications` ADD UNIQUE KEY `uq_notifications_event` (`event_key`)',
  'DO 0'
);
PREPARE add_event_index_stmt FROM @add_event_index;
EXECUTE add_event_index_stmt;
DEALLOCATE PREPARE add_event_index_stmt;
