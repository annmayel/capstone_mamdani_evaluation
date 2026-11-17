-- Store an optional additional project resource link on the project record.
ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `other_project_url` VARCHAR(1000) DEFAULT NULL AFTER `github_url`;
