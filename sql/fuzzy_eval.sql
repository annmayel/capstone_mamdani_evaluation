-- SQL dump for Capstone Fuzzy Eval (XAMPP import)
CREATE DATABASE IF NOT EXISTS `capstone_fuzzy_eval` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `capstone_fuzzy_eval`;

-- users
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `full_name` VARCHAR(200) NOT NULL,
  `student_id_number` VARCHAR(50) DEFAULT NULL,
  `group_name` VARCHAR(200) DEFAULT NULL,
  `employee_id_number` VARCHAR(50) DEFAULT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('dean','evaluator','student') NOT NULL,
  `account_id` VARCHAR(30) NULL,
  `department` VARCHAR(100) DEFAULT 'Information Technology (IT)',
  `can_evaluate` TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `unique_account_id` (`account_id`),
  UNIQUE KEY `unique_student_id_number` (`student_id_number`),
  UNIQUE KEY `unique_employee_id_number` (`employee_id_number`)
);

-- projects
CREATE TABLE `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(40) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `group_name` VARCHAR(200) DEFAULT NULL,
  `members` TEXT,
  `adviser` VARCHAR(200) DEFAULT NULL,
  `student_id` INT DEFAULT NULL,
  `academic_year` VARCHAR(30) DEFAULT NULL,
  `defense_date` DATE DEFAULT NULL,
  `description` TEXT,
  `google_drive_url` VARCHAR(1000) DEFAULT NULL,
  `github_url` VARCHAR(1000) DEFAULT NULL,
  `status` VARCHAR(60) DEFAULT 'Submitted',
  `project_score` DECIMAL(6,2) DEFAULT 0,
  `paper_score` DECIMAL(6,2) DEFAULT 0,
  `overall_score` DECIMAL(6,2) DEFAULT 0,
  `overall_rating` VARCHAR(60) DEFAULT NULL
);

CREATE TABLE `student_submissions` (
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

CREATE TABLE `evaluator_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `assigned_by` INT NOT NULL,
  `status` ENUM('pending','assigned','in_evaluation','project_evaluated','paper_evaluated','fuzzy_processed','completed') DEFAULT 'assigned',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_assignment` (`project_id`,`evaluator_id`)
);

CREATE TABLE `evaluation_feedback` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `evaluation_type` ENUM('project','paper','overall') NOT NULL,
  `strengths` TEXT,
  `weaknesses` TEXT,
  `recommendations` TEXT,
  `required_revisions` TEXT,
  `remarks` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `evaluation_history` (
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

CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `event_key` CHAR(64) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_notifications_event` (`event_key`),
  INDEX `idx_notifications_user` (`user_id`, `is_read`, `created_at`)
);

-- Email outbox and delivery log (see sql/migrations/008_email_notifications.sql)
CREATE TABLE `email_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `event_key` CHAR(64) NOT NULL,
  `event_type` VARCHAR(64) NOT NULL,
  `recipient_user_id` INT NOT NULL,
  `recipient_email` VARCHAR(254) NOT NULL,
  `project_id` INT DEFAULT NULL,
  `submission_id` INT DEFAULT NULL,
  `evaluation_id` INT DEFAULT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `html_body` MEDIUMTEXT NOT NULL,
  `text_body` MEDIUMTEXT NOT NULL,
  `delivery_status` ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempt_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` VARCHAR(1000) DEFAULT NULL,
  `last_attempt_at` DATETIME DEFAULT NULL,
  `next_attempt_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uq_email_notifications_event` (`event_key`),
  KEY `idx_email_notifications_status_created` (`delivery_status`,`created_at`),
  KEY `idx_email_notifications_retry` (`delivery_status`,`next_attempt_at`,`id`),
  KEY `idx_email_notifications_recipient_created` (`recipient_user_id`,`created_at`)
);

-- project evaluations
CREATE TABLE `evaluations_project` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `functionality` DECIMAL(5,2),
  `technical_quality` DECIMAL(5,2),
  `performance` DECIMAL(5,2),
  `usability` DECIMAL(5,2),
  `uiux` DECIMAL(5,2),
  `security` DECIMAL(5,2),
  `innovation` DECIMAL(5,2),
  `relevance` DECIMAL(5,2),
  `reliability` DECIMAL(5,2),
  `completeness` DECIMAL(5,2),
  `total_score` DECIMAL(6,2) DEFAULT 0,
  `remarks` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- paper evaluations
CREATE TABLE `evaluations_paper` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `chap1` DECIMAL(6,2),
  `chap2` DECIMAL(6,2),
  `chap3` DECIMAL(6,2),
  `chap4` DECIMAL(6,2),
  `chap5` DECIMAL(6,2),
  `total_score` DECIMAL(6,2) DEFAULT 0,
  `remarks` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Legacy configuration tables retained for database compatibility.
-- The application engine now uses source-controlled rules and membership functions.
CREATE TABLE `fuzzy_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `rule_text` TEXT,
  `status` ENUM('enabled','disabled') DEFAULT 'enabled'
);

-- Legacy membership function table retained for database compatibility.
CREATE TABLE `membership_functions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `variable` VARCHAR(100),
  `label` VARCHAR(50),
  `a` DECIMAL(6,2),
  `b` DECIMAL(6,2),
  `c` DECIMAL(6,2),
  `d` DECIMAL(6,2)
);

-- sample data
INSERT INTO `users` (full_name,email,password_hash,role,account_id,department,can_evaluate) VALUES
('Dr. Dean Admin','dean@csucc.edu.ph','$2y$10$.4fixxDxT1rAPYoM3JnTmumdDXDVCC.gei3DAkh8zehtfp8fyzBJO', 'dean','ITCHAIR-0001','Information Technology (IT)',1),
('Engr. Eva Lator','evaluator1@csu.edu','$2y$10$vh3.CBHtqA/B9l5uqgWmNuqIw7D0hJNVUpvMe0rHGs8wJ05dabeM2', 'evaluator','EVAL-0001','Information Technology (IT)',1),
('Team CampusConnect','student1@csu.edu','$2y$10$QW2BDQ0WmAmiwwZClcKDy.cdCRPqmrMS/1UEhLXoKWdmfVo24ac8S', 'student','STUD-0001','Information Technology (IT)',0);

-- Sample passwords for the seeded accounts:
-- dean@csucc.edu.ph => dean1234
-- evaluator1@csu.edu => eval1234
-- student1@csu.edu => student1234

INSERT INTO `projects` (code,title,group_name,members,adviser,student_id,academic_year,defense_date,description,status,project_score,paper_score,overall_score,overall_rating) VALUES
('CC-001','CampusConnect: Student Service Management System','Team CampusConnect','Alice;Bob;Charlie','Dr. Adviser',3,'2025-2026','2026-03-15','A web-based student services system.','Evaluated',88,90,88.8,'Excellent');

INSERT INTO `evaluations_project` (project_id,evaluator_id,functionality,technical_quality,performance,usability,uiux,security,innovation,relevance,reliability,completeness,total_score,remarks) VALUES
(1,2,9,8,8,9,8,7,8,9,8,9,88,'Good system overall');

INSERT INTO `evaluations_paper` (project_id,evaluator_id,chap1,chap2,chap3,chap4,chap5,total_score,remarks) VALUES
(1,2,18,17,18,18,19,90,'Well written paper');

INSERT INTO `membership_functions` (variable,label,a,b,c,d) VALUES
('Overall','Poor',0,0,40,55),
('Overall','Fair',50,60,70,75),
('Overall','Good',70,78,85,88),
('Overall','Excellent',85,90,100,100);

-- end
