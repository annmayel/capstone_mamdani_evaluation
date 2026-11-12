USE `capstone_fuzzy_eval`;

ALTER TABLE `users`
  ADD COLUMN `student_id_number` VARCHAR(50) DEFAULT NULL AFTER `full_name`,
  ADD COLUMN `group_name` VARCHAR(200) DEFAULT NULL AFTER `student_id_number`,
  ADD UNIQUE KEY `unique_student_id_number` (`student_id_number`);
