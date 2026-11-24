USE `capstone_fuzzy_eval`;

ALTER TABLE `evaluations_project`
  ADD COLUMN `proposal_status` ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  ADD COLUMN `proposal_background` DECIMAL(5,2) NULL,
  ADD COLUMN `proposal_literature` DECIMAL(5,2) NULL,
  ADD COLUMN `proposal_methodology` DECIMAL(5,2) NULL,
  ADD COLUMN `proposal_design` DECIMAL(5,2) NULL,
  ADD COLUMN `proposal_presentation` DECIMAL(5,2) NULL,
  ADD COLUMN `proposal_question_answer` DECIMAL(5,2) NULL,
  ADD COLUMN `proposal_background_comment` TEXT NULL,
  ADD COLUMN `proposal_literature_comment` TEXT NULL,
  ADD COLUMN `proposal_methodology_comment` TEXT NULL,
  ADD COLUMN `proposal_design_comment` TEXT NULL,
  ADD COLUMN `proposal_presentation_comment` TEXT NULL,
  ADD COLUMN `proposal_question_answer_comment` TEXT NULL,
  ADD COLUMN `proposal_total_score` DECIMAL(6,2) NULL,
  ADD COLUMN `proposal_evaluator_comments` TEXT NULL;

ALTER TABLE `evaluator_assignments`
  ADD COLUMN `proposal_draft` LONGTEXT NULL;

ALTER TABLE `projects`
  ADD COLUMN `proposal_official_score` DECIMAL(6,2) NULL,
  ADD COLUMN `proposal_official_verdict` VARCHAR(100) NULL,
  ADD COLUMN `proposal_required_revisions` TEXT NULL,
  ADD COLUMN `proposal_chairperson_comments` TEXT NULL,
  ADD COLUMN `proposal_chairperson_id` INT NULL,
  ADD COLUMN `proposal_confirmed_at` DATETIME NULL;
