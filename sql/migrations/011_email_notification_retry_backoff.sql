USE `capstone_fuzzy_eval`;

SET @has_next_attempt_at = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'email_notifications'
    AND column_name = 'next_attempt_at'
);
SET @add_next_attempt_at = IF(
  @has_next_attempt_at = 0,
  'ALTER TABLE `email_notifications` ADD COLUMN `next_attempt_at` DATETIME DEFAULT NULL AFTER `last_error`',
  'DO 0'
);
PREPARE add_next_attempt_at_stmt FROM @add_next_attempt_at;
EXECUTE add_next_attempt_at_stmt;
DEALLOCATE PREPARE add_next_attempt_at_stmt;

SET @has_last_attempt_at = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'email_notifications'
    AND column_name = 'last_attempt_at'
);
SET @add_last_attempt_at = IF(
  @has_last_attempt_at = 0,
  'ALTER TABLE `email_notifications` ADD COLUMN `last_attempt_at` DATETIME DEFAULT NULL AFTER `last_error`',
  'DO 0'
);
PREPARE add_last_attempt_at_stmt FROM @add_last_attempt_at;
EXECUTE add_last_attempt_at_stmt;
DEALLOCATE PREPARE add_last_attempt_at_stmt;

SET @has_retry_index = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'email_notifications'
    AND index_name = 'idx_email_notifications_retry'
);
SET @add_retry_index = IF(
  @has_retry_index = 0,
  'ALTER TABLE `email_notifications` ADD KEY `idx_email_notifications_retry` (`delivery_status`,`next_attempt_at`,`id`)',
  'DO 0'
);
PREPARE add_retry_index_stmt FROM @add_retry_index;
EXECUTE add_retry_index_stmt;
DEALLOCATE PREPARE add_retry_index_stmt;
