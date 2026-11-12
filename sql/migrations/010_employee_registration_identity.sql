USE `capstone_fuzzy_eval`;

-- Read-only preflight: inspect the live users table and existing identity columns.
SHOW COLUMNS FROM `users`;
SELECT `column_name`, `column_type`, `is_nullable`, `column_default`
FROM information_schema.columns
WHERE `table_schema` = DATABASE()
  AND `table_name` = 'users'
  AND `column_name` IN ('student_id_number', 'group_name', 'employee_id_number');

-- The migration guard also checks current employee-ID values before adding the index.
-- This migration is additive and rerunnable; it never alters existing names or identities.
DELIMITER //
DROP PROCEDURE IF EXISTS `migrate_employee_registration_identity`//
CREATE PROCEDURE `migrate_employee_registration_identity`()
BEGIN
  DECLARE has_employee_column INT DEFAULT 0;
  DECLARE has_unique_employee_index INT DEFAULT 0;
  DECLARE duplicate_employee_ids INT DEFAULT 0;

  SELECT COUNT(*) INTO has_employee_column
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'employee_id_number';

  IF has_employee_column = 0 THEN
    ALTER TABLE `users`
      ADD COLUMN `employee_id_number` VARCHAR(50) DEFAULT NULL AFTER `group_name`;
  END IF;

  SELECT COUNT(*) INTO duplicate_employee_ids
  FROM (
    SELECT `employee_id_number`
    FROM `users`
    WHERE `employee_id_number` IS NOT NULL
    GROUP BY `employee_id_number`
    HAVING COUNT(*) > 1
  ) AS duplicate_ids;

  IF duplicate_employee_ids > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Duplicate employee_id_number values exist; resolve them before adding the unique index.';
  END IF;

  SELECT COUNT(*) INTO has_unique_employee_index
  FROM (
    SELECT index_name
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND non_unique = 0
    GROUP BY index_name
    HAVING COUNT(*) = 1 AND MAX(column_name = 'employee_id_number') = 1
  ) AS employee_id_unique_indexes;

  IF has_unique_employee_index = 0 THEN
    ALTER TABLE `users`
      ADD UNIQUE KEY `unique_employee_id_number` (`employee_id_number`);
  END IF;
END//
CALL `migrate_employee_registration_identity`()//
DROP PROCEDURE `migrate_employee_registration_identity`//
DELIMITER ;
