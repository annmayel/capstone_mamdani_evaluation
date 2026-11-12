-- Store project source/resource links separately from paper/PDF submissions.
ALTER TABLE `projects`
  ADD COLUMN `google_drive_url` VARCHAR(1000) DEFAULT NULL AFTER `description`,
  ADD COLUMN `github_url` VARCHAR(1000) DEFAULT NULL AFTER `google_drive_url`;
