CREATE TABLE `auth_otp_challenges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `session_binding_hash` CHAR(64) NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `send_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `last_sent_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `consumed_at` DATETIME DEFAULT NULL,
  `invalidated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_auth_otp_user_created` (`user_id`, `created_at`),
  KEY `idx_auth_otp_session_binding` (`session_binding_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;