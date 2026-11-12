-- Student proposal upload support. Run after sql/fuzzy_eval.sql.
CREATE TABLE IF NOT EXISTS `student_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `project_id` INT DEFAULT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `status` ENUM('submitted','under_evaluation','evaluated','revision_requested') NOT NULL DEFAULT 'submitted',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_student_submissions_student` (`student_id`),
  INDEX `idx_student_submissions_project` (`project_id`)
);