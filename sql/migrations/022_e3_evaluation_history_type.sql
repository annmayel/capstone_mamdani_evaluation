-- Add the final-hearing history type without changing existing records.
ALTER TABLE `evaluation_history`
  MODIFY COLUMN `evaluation_type` ENUM('project','paper','overall','e3') NOT NULL;
