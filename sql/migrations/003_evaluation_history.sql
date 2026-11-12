-- Evaluation history support for evaluator submissions.
USE `capstone_fuzzy_eval`;

CREATE TABLE IF NOT EXISTS `evaluation_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `evaluation_type` ENUM('project','paper','overall') NOT NULL,
  `project_score` DECIMAL(6,2) DEFAULT 0,
  `paper_score` DECIMAL(6,2) DEFAULT 0,
  `overall_score` DECIMAL(6,2) DEFAULT 0,
  `overall_rating` VARCHAR(60) DEFAULT NULL,
  `feedback_id` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
