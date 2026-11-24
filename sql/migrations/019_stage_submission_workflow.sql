USE `capstone_fuzzy_eval`;

ALTER TABLE `student_submissions`
  ADD COLUMN `stage` ENUM('proposal','final') NOT NULL DEFAULT 'proposal' AFTER `project_id`,
  MODIFY COLUMN `status` ENUM(
    'submitted',
    'under_evaluation',
    'evaluated',
    'revision_requested',
    'revision_required',
    'approved',
    'accepted'
  ) NOT NULL DEFAULT 'submitted',
  ADD KEY `idx_student_submissions_project_stage` (`project_id`,`stage`,`id`);

ALTER TABLE `projects`
  ADD COLUMN `proposal_verdict_submission_id` INT NULL DEFAULT NULL,
  ADD COLUMN `proposal_revision_accepted_submission_id` INT NULL DEFAULT NULL;

ALTER TABLE `evaluations_project`
  ADD COLUMN `proposal_submission_id` INT NULL DEFAULT NULL;

UPDATE `evaluations_project` e
INNER JOIN `student_submissions` s ON s.id = (
  SELECT latest_submission.id
  FROM `student_submissions` latest_submission
  WHERE latest_submission.project_id = e.project_id
    AND latest_submission.stage = 'proposal'
    AND latest_submission.created_at <= e.created_at
  ORDER BY latest_submission.created_at DESC, latest_submission.id DESC
  LIMIT 1
)
SET e.proposal_submission_id = s.id
WHERE e.proposal_status = 'submitted' AND e.proposal_submission_id IS NULL;

UPDATE `projects` p
SET p.proposal_verdict_submission_id = (
  SELECT latest_submission.id
  FROM `student_submissions` latest_submission
  WHERE latest_submission.project_id = p.id AND latest_submission.stage = 'proposal'
  ORDER BY latest_submission.created_at DESC, latest_submission.id DESC
  LIMIT 1
)
WHERE p.proposal_confirmed_at IS NOT NULL AND p.proposal_verdict_submission_id IS NULL;

ALTER TABLE `e3_evaluations`
  DROP INDEX `uniq_e3_evaluation_project_evaluator`,
  ADD COLUMN `final_submission_id` INT NULL DEFAULT NULL,
  ADD UNIQUE KEY `uniq_e3_evaluation_submission_evaluator` (`project_id`,`evaluator_id`,`final_submission_id`);

ALTER TABLE `e3_final_verdicts`
  ADD COLUMN `final_submission_id` INT NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `stage_verdict_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `stage` ENUM('proposal','final') NOT NULL,
  `submission_id` INT NULL DEFAULT NULL,
  `chairperson_id` INT NOT NULL,
  `score` DECIMAL(8,2) NOT NULL,
  `verdict_label` VARCHAR(100) NOT NULL,
  `required_revisions` TEXT NULL,
  `chairperson_comments` TEXT NULL,
  `confirmed_at` DATETIME NOT NULL,
  `archived_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stage_verdict_history_project` (`project_id`,`stage`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
