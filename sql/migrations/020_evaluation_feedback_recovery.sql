-- Recover the evaluation_feedback table when migration 013 was not applied.
-- The connection-selected database is used; no schema name is hard-coded.
CREATE TABLE IF NOT EXISTS `evaluation_feedback` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `submission_id` INT NULL DEFAULT NULL,
  `evaluator_id` INT NOT NULL,
  `evaluation_type` ENUM('project','paper','overall') NOT NULL,
  `stage` ENUM('proposal','final') NULL DEFAULT NULL,
  `evaluation_id` INT NULL DEFAULT NULL,
  `score` DECIMAL(6,2) NULL DEFAULT NULL,
  `strengths` TEXT NULL,
  `weaknesses` TEXT NULL,
  `recommendations` TEXT NULL,
  `required_revisions` TEXT NULL,
  `remarks` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_evaluation_feedback_project` (`project_id`),
  KEY `idx_evaluation_feedback_evaluator` (`evaluator_id`),
  KEY `idx_evaluation_feedback_created` (`created_at`),
  KEY `idx_evaluation_feedback_submission` (`submission_id`),
  KEY `idx_evaluation_feedback_evaluation` (`evaluation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
