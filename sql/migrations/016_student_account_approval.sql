DELIMITER //
DROP PROCEDURE IF EXISTS `migrate_student_account_approval`//
CREATE PROCEDURE `migrate_student_account_approval`()
BEGIN
  DECLARE has_student_approved_at INT DEFAULT 0;

  SELECT COUNT(*) INTO has_student_approved_at
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'student_approved_at';

  IF has_student_approved_at = 0 THEN
    ALTER TABLE `users`
      ADD COLUMN `student_approved_at` DATETIME DEFAULT NULL AFTER `email_verified_at`;
  END IF;
END//
CALL `migrate_student_account_approval`()//
DROP PROCEDURE `migrate_student_account_approval`//
DELIMITER ;
