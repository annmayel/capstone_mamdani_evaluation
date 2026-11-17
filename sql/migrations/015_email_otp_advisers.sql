CREATE TABLE IF NOT EXISTS `auth_otp_challenges` (
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

DELIMITER //
DROP PROCEDURE IF EXISTS `migrate_email_otp_advisers`//
CREATE PROCEDURE `migrate_email_otp_advisers`()
BEGIN
  DECLARE has_verified_at INT DEFAULT 0;
  DECLARE has_otp_purpose INT DEFAULT 0;
  DECLARE has_adviser_user_id INT DEFAULT 0;
  DECLARE has_adviser_project_index INT DEFAULT 0;

  SELECT COUNT(*) INTO has_verified_at
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'email_verified_at';

  IF has_verified_at = 0 THEN
    ALTER TABLE `users`
      ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `email`;
    UPDATE `users`
      SET `email_verified_at` = UTC_TIMESTAMP()
      WHERE `role` = 'student';
  END IF;

  ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('dean','evaluator','student','adviser') NOT NULL;

  SELECT COUNT(*) INTO has_otp_purpose
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'auth_otp_challenges'
    AND column_name = 'purpose';

  IF has_otp_purpose = 0 THEN
    ALTER TABLE `auth_otp_challenges`
      ADD COLUMN `purpose` VARCHAR(32) NOT NULL DEFAULT 'login' AFTER `user_id`;
  END IF;

  SELECT COUNT(*) INTO has_adviser_user_id
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'projects'
    AND column_name = 'adviser_user_id';

  IF has_adviser_user_id = 0 THEN
    ALTER TABLE `projects`
      ADD COLUMN `adviser_user_id` INT(11) DEFAULT NULL AFTER `adviser`;
  END IF;

  SELECT COUNT(*) INTO has_adviser_project_index
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'projects'
    AND index_name = 'idx_projects_adviser_user';

  IF has_adviser_project_index = 0 THEN
    ALTER TABLE `projects`
      ADD KEY `idx_projects_adviser_user` (`adviser_user_id`);
  END IF;
END//
CALL `migrate_email_otp_advisers`()//
DROP PROCEDURE `migrate_email_otp_advisers`//
DELIMITER ;

UPDATE `projects` p
JOIN `users` adviser
  ON adviser.`role` = 'adviser'
  AND TRIM(adviser.`full_name`) = TRIM(p.`adviser`)
JOIN (
  SELECT TRIM(`full_name`) AS `full_name_key`
  FROM `users`
  WHERE `role` = 'adviser'
  GROUP BY TRIM(`full_name`)
  HAVING COUNT(*) = 1
) unique_adviser
  ON unique_adviser.`full_name_key` = TRIM(adviser.`full_name`)
SET p.`adviser_user_id` = adviser.`id`
WHERE p.`adviser_user_id` IS NULL
  AND p.`adviser` IS NOT NULL
  AND TRIM(p.`adviser`) <> '';
