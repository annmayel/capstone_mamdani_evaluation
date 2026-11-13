-- Schema-only Railway import; row data from the local database was excluded.

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 13, 2026 at 09:54 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";


START TRANSACTION;


SET time_zone = "+00:00";




/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;


/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;


/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;


/*!40101 SET NAMES utf8mb4 */;



--
-- Database: `capstone_fuzzy_eval`
--

-- --------------------------------------------------------

--
-- Table structure for table `auth_otp_challenges`
--

CREATE TABLE `auth_otp_challenges` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_binding_hash` char(64) NOT NULL,
  `code_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempt_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `send_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `last_sent_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `consumed_at` datetime DEFAULT NULL,
  `invalidated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `email_notifications`
--

CREATE TABLE `email_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_key` char(64) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `recipient_user_id` int(11) NOT NULL,
  `recipient_email` varchar(254) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `submission_id` int(11) DEFAULT NULL,
  `evaluation_id` int(11) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `html_body` mediumtext NOT NULL,
  `text_body` mediumtext NOT NULL,
  `delivery_status` enum('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempt_count` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `last_error` varchar(1000) DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `next_attempt_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `evaluations_paper`
--

CREATE TABLE `evaluations_paper` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `chap1` decimal(6,2) DEFAULT NULL,
  `chap2` decimal(6,2) DEFAULT NULL,
  `chap3` decimal(6,2) DEFAULT NULL,
  `chap4` decimal(6,2) DEFAULT NULL,
  `chap5` decimal(6,2) DEFAULT NULL,
  `total_score` decimal(6,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `evaluations_project`
--

CREATE TABLE `evaluations_project` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `functionality` decimal(5,2) DEFAULT NULL,
  `technical_quality` decimal(5,2) DEFAULT NULL,
  `performance` decimal(5,2) DEFAULT NULL,
  `usability` decimal(5,2) DEFAULT NULL,
  `uiux` decimal(5,2) DEFAULT NULL,
  `security` decimal(5,2) DEFAULT NULL,
  `innovation` decimal(5,2) DEFAULT NULL,
  `relevance` decimal(5,2) DEFAULT NULL,
  `reliability` decimal(5,2) DEFAULT NULL,
  `completeness` decimal(5,2) DEFAULT NULL,
  `total_score` decimal(6,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `evaluation_history`
--

CREATE TABLE `evaluation_history` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `evaluation_type` enum('project','paper','overall') NOT NULL,
  `project_score` decimal(6,2) DEFAULT 0.00,
  `paper_score` decimal(6,2) DEFAULT 0.00,
  `overall_score` decimal(6,2) DEFAULT 0.00,
  `overall_rating` varchar(60) DEFAULT NULL,
  `feedback_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `evaluator_assignments`
--

CREATE TABLE `evaluator_assignments` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `evaluator_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `status` enum('pending','assigned','in_evaluation','project_evaluated','paper_evaluated','fuzzy_processed','completed') DEFAULT 'assigned',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `fuzzy_rules`
--

CREATE TABLE `fuzzy_rules` (
  `id` int(11) NOT NULL,
  `rule_text` text DEFAULT NULL,
  `status` enum('enabled','disabled') DEFAULT 'enabled'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `membership_functions`
--

CREATE TABLE `membership_functions` (
  `id` int(11) NOT NULL,
  `variable` varchar(100) DEFAULT NULL,
  `label` varchar(50) DEFAULT NULL,
  `a` decimal(6,2) DEFAULT NULL,
  `b` decimal(6,2) DEFAULT NULL,
  `c` decimal(6,2) DEFAULT NULL,
  `d` decimal(6,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `event_key` char(64) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `code` varchar(40) NOT NULL,
  `title` varchar(255) NOT NULL,
  `group_name` varchar(200) DEFAULT NULL,
  `members` text DEFAULT NULL,
  `adviser` varchar(200) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `academic_year` varchar(30) DEFAULT NULL,
  `defense_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `google_drive_url` varchar(1000) DEFAULT NULL,
  `github_url` varchar(1000) DEFAULT NULL,
  `status` varchar(60) DEFAULT 'Submitted',
  `project_score` decimal(6,2) DEFAULT 0.00,
  `paper_score` decimal(6,2) DEFAULT 0.00,
  `overall_score` decimal(6,2) DEFAULT 0.00,
  `overall_rating` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `student_submissions`
--

CREATE TABLE `student_submissions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `submission_url` varchar(1000) DEFAULT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) UNSIGNED NOT NULL,
  `status` enum('submitted','under_evaluation','evaluated','revision_requested') NOT NULL DEFAULT 'submitted',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `student_id_number` varchar(50) DEFAULT NULL,
  `group_name` varchar(200) DEFAULT NULL,
  `employee_id_number` varchar(50) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('dean','evaluator','student') NOT NULL,
  `account_id` varchar(30) DEFAULT NULL,
  `school_id` varchar(50) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `can_evaluate` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



--
-- Indexes for dumped tables
--

--
-- Indexes for table `auth_otp_challenges`
--
ALTER TABLE `auth_otp_challenges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auth_otp_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_auth_otp_session_binding` (`session_binding_hash`);



--
-- Indexes for table `email_notifications`
--
ALTER TABLE `email_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email_notifications_event` (`event_key`),
  ADD KEY `idx_email_notifications_status_created` (`delivery_status`,`created_at`),
  ADD KEY `idx_email_notifications_recipient_created` (`recipient_user_id`,`created_at`),
  ADD KEY `idx_email_notifications_retry` (`delivery_status`,`next_attempt_at`,`id`);



--
-- Indexes for table `evaluations_paper`
--
ALTER TABLE `evaluations_paper`
  ADD PRIMARY KEY (`id`);



--
-- Indexes for table `evaluations_project`
--
ALTER TABLE `evaluations_project`
  ADD PRIMARY KEY (`id`);



--
-- Indexes for table `evaluation_history`
--
ALTER TABLE `evaluation_history`
  ADD PRIMARY KEY (`id`);



--
-- Indexes for table `evaluator_assignments`
--
ALTER TABLE `evaluator_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_assignment` (`project_id`,`evaluator_id`);



--
-- Indexes for table `fuzzy_rules`
--
ALTER TABLE `fuzzy_rules`
  ADD PRIMARY KEY (`id`);



--
-- Indexes for table `membership_functions`
--
ALTER TABLE `membership_functions`
  ADD PRIMARY KEY (`id`);



--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notifications_event` (`event_key`),
  ADD KEY `idx_notifications_user` (`user_id`,`is_read`,`created_at`);



--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`);



--
-- Indexes for table `student_submissions`
--
ALTER TABLE `student_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_student_submissions_student` (`student_id`),
  ADD KEY `idx_student_submissions_project` (`project_id`);



--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_users_account_id` (`account_id`),
  ADD UNIQUE KEY `unique_student_id_number` (`student_id_number`),
  ADD UNIQUE KEY `unique_employee_id_number` (`employee_id_number`);



--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `auth_otp_challenges`
--
ALTER TABLE `auth_otp_challenges`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;



--
-- AUTO_INCREMENT for table `email_notifications`
--
ALTER TABLE `email_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;



--
-- AUTO_INCREMENT for table `evaluations_paper`
--
ALTER TABLE `evaluations_paper`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;



--
-- AUTO_INCREMENT for table `evaluations_project`
--
ALTER TABLE `evaluations_project`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;



--
-- AUTO_INCREMENT for table `evaluation_history`
--
ALTER TABLE `evaluation_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;



--
-- AUTO_INCREMENT for table `evaluator_assignments`
--
ALTER TABLE `evaluator_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;



--
-- AUTO_INCREMENT for table `fuzzy_rules`
--
ALTER TABLE `fuzzy_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;



--
-- AUTO_INCREMENT for table `membership_functions`
--
ALTER TABLE `membership_functions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;



--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;



--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;



--
-- AUTO_INCREMENT for table `student_submissions`
--
ALTER TABLE `student_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;



--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;


COMMIT;



/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;


/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;


/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
