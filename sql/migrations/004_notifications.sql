-- Notifications shared by students, administrators, and evaluators.
USE `capstone_fuzzy_eval`;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `event_key` CHAR(64) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_notifications_event` (`event_key`),
  INDEX `idx_notifications_user` (`user_id`, `is_read`, `created_at`)
);
