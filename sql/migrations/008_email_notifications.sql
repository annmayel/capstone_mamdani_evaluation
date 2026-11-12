-- Reliable, idempotent email outbox and delivery log.
USE `capstone_fuzzy_eval`;

CREATE TABLE IF NOT EXISTS `email_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `event_key` CHAR(64) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `recipient_user_id` INT NOT NULL,
  `recipient_email` VARCHAR(254) NOT NULL,
  `project_id` INT DEFAULT NULL,
  `submission_id` INT DEFAULT NULL,
  `evaluation_id` INT DEFAULT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `html_body` MEDIUMTEXT NOT NULL,
  `text_body` MEDIUMTEXT NOT NULL,
  `delivery_status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(1000) DEFAULT NULL,
  `last_attempt_at` DATETIME DEFAULT NULL,
  `next_attempt_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_email_notifications_event` (`event_key`),
  KEY `idx_email_notifications_status_created` (`delivery_status`,`created_at`),
  KEY `idx_email_notifications_retry` (`delivery_status`,`next_attempt_at`,`id`),
  KEY `idx_email_notifications_recipient_created` (`recipient_user_id`,`created_at`)
);
