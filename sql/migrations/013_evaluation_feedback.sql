USE `capstone_fuzzy_eval`;

CREATE TABLE IF NOT EXISTS `evaluation_feedback` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `evaluation_type` ENUM('project','paper','overall') NOT NULL,
  `strengths` TEXT,
  `weaknesses` TEXT,
  `recommendations` TEXT,
  `required_revisions` TEXT,
  `remarks` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_evaluation_feedback_project` (`project_id`),
  KEY `idx_evaluation_feedback_evaluator` (`evaluator_id`),
  KEY `idx_evaluation_feedback_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
