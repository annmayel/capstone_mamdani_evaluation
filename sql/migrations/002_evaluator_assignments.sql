-- Repair for databases imported before evaluator assignment support.
USE `capstone_fuzzy_eval`;

CREATE TABLE IF NOT EXISTS `evaluator_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `assigned_by` INT NOT NULL,
  `status` ENUM('pending','assigned','in_evaluation','project_evaluated','paper_evaluated','fuzzy_processed','completed') DEFAULT 'assigned',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_assignment` (`project_id`,`evaluator_id`)
);
