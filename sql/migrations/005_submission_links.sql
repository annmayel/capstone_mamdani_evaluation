-- Allow students to submit an approved external project link alongside PDF uploads.
ALTER TABLE `student_submissions`
  ADD COLUMN `submission_url` VARCHAR(1000) DEFAULT NULL AFTER `stored_filename`;
