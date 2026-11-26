-- Proposal major-revision cycles. Run after migrations 019 and 021.
-- This only adds revision metadata; student submissions and original scores stay intact.
CREATE TABLE IF NOT EXISTS `proposal_revision_cycles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `original_submission_id` INT NOT NULL,
  `revision_submission_id` INT NOT NULL,
  `revision_number` INT NOT NULL,
  `status` ENUM('pending_panel_review','accepted','returned') NOT NULL DEFAULT 'pending_panel_review',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proposal_revision_submission` (`revision_submission_id`),
  UNIQUE KEY `uniq_proposal_revision_project_number` (`project_id`,`revision_number`),
  KEY `idx_proposal_revision_project_status` (`project_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `proposal_revision_reviews` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `revision_cycle_id` INT UNSIGNED NOT NULL,
  `evaluator_id` INT NOT NULL,
  `decision` ENUM('pending','accepted','returned') NOT NULL DEFAULT 'pending',
  `comments` TEXT NULL,
  `submitted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proposal_revision_cycle_evaluator` (`revision_cycle_id`,`evaluator_id`),
  KEY `idx_proposal_revision_review_evaluator` (`evaluator_id`,`decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
