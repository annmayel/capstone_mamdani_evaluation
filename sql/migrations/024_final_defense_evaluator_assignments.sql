-- Non-destructive Final Defense assignment scope.
-- Apply only after migration 019, which provides student_submissions.stage.
-- This preserves legacy project-level evaluator assignments for proposal work.
CREATE TABLE IF NOT EXISTS `final_defense_evaluator_assignments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `final_submission_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `assigned_by` INT NOT NULL,
  `status` ENUM('assigned','in_evaluation','completed') NOT NULL DEFAULT 'assigned',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_final_defense_assignment` (`final_submission_id`,`evaluator_id`),
  KEY `idx_final_defense_assignment_project` (`project_id`,`final_submission_id`),
  KEY `idx_final_defense_assignment_evaluator` (`evaluator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
