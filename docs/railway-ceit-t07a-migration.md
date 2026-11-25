# Railway CEIT-T07A proposal migration

Run this procedure only against the Railway MySQL service used by the PHP application. It creates one new table and does not alter, drop, truncate, or copy rows from legacy evaluation tables.

## 1. Back up and inspect first

In the Railway MySQL service, create and wait for a completed manual backup from the **Backups** tab. Do not proceed if the backup fails. If point-in-time recovery is available, confirm it is enabled before a production change.

Open a MySQL shell for the Railway database (for example, with `railway connect <mysql-service-name>`), then run these read-only checks. Do not print or commit connection credentials.

```sql
SELECT DATABASE() AS selected_database;

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_evaluations';

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_evaluations'
ORDER BY ORDINAL_POSITION;

SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_evaluations'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME = 'projects' AND COLUMN_NAME = 'id') OR
    (TABLE_NAME = 'student_submissions' AND COLUMN_NAME = 'id') OR
    (TABLE_NAME = 'users' AND COLUMN_NAME = 'id')
  )
ORDER BY TABLE_NAME;
```

Expected result before migration 021: no `proposal_evaluations` table. If a table already exists, stop and compare its columns and indexes with the expected definition below; `CREATE TABLE IF NOT EXISTS` deliberately does not repair a partially created table.

## 2. Apply migration 021 exactly once when the table is absent

Run the committed `sql/migrations/021_ceit_t07a_proposal_evaluations.sql` file through the connected MySQL shell. Its exact statement is:

```sql
CREATE TABLE IF NOT EXISTS `proposal_evaluations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT NOT NULL,
  `proposal_submission_id` INT NOT NULL,
  `evaluator_id` INT NOT NULL,
  `pfo1_1` DECIMAL(5,2) NULL, `pfo1_2` DECIMAL(5,2) NULL, `pfo1_3` DECIMAL(5,2) NULL,
  `lts2_1` DECIMAL(5,2) NULL, `lts2_2` DECIMAL(5,2) NULL, `lts2_3` DECIMAL(5,2) NULL,
  `msa3_1` DECIMAL(5,2) NULL, `msa3_2` DECIMAL(5,2) NULL, `msa3_3` DECIMAL(5,2) NULL,
  `ppr4_1` DECIMAL(5,2) NULL, `ppr4_2` DECIMAL(5,2) NULL,
  `pfo1_1_comment` TEXT NULL, `pfo1_2_comment` TEXT NULL, `pfo1_3_comment` TEXT NULL,
  `lts2_1_comment` TEXT NULL, `lts2_2_comment` TEXT NULL, `lts2_3_comment` TEXT NULL,
  `msa3_1_comment` TEXT NULL, `msa3_2_comment` TEXT NULL, `msa3_3_comment` TEXT NULL,
  `ppr4_1_comment` TEXT NULL, `ppr4_2_comment` TEXT NULL,
  `total_score` DECIMAL(6,2) NULL,
  `overall_comments` TEXT NULL, `recommendations` TEXT NULL, `required_revisions` TEXT NULL,
  `status` ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
  `submitted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proposal_evaluation_submission_evaluator` (`project_id`,`proposal_submission_id`,`evaluator_id`),
  KEY `idx_proposal_evaluation_submission` (`proposal_submission_id`),
  KEY `idx_proposal_evaluation_evaluator` (`evaluator_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The migration intentionally has no foreign keys. The existing Railway schema uses signed `INT` identifiers and does not enforce foreign keys between these workflow tables. Adding new foreign keys during this repair could fail on existing rows or block normal workflow operations. The unique key prevents duplicate evaluator sheets for the same project submission; the two non-unique indexes support submission and evaluator lookups.

## 3. Check whether migration 022 is needed for E3

The E3 evaluator inserts `evaluation_type = 'e3'` into `evaluation_history`. Run:

```sql
SELECT COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'evaluation_history'
  AND COLUMN_NAME = 'evaluation_type';
```

If the returned enum already includes `e3`, do not run migration 022. If it does not, apply this preserving alteration from `sql/migrations/022_e3_evaluation_history_type.sql`:

```sql
ALTER TABLE `evaluation_history`
  MODIFY COLUMN `evaluation_type` ENUM('project','paper','overall','e3') NOT NULL;
```

## 4. Verify after applying

```sql
SHOW CREATE TABLE `proposal_evaluations`;

SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'proposal_evaluations'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
```

Then redeploy or restart only the PHP application service. Verify, using a test project, that an evaluator can save a draft and submit one proposal sheet, the Chairperson can see the panel average and publish it, students cannot see it before publication, and students see only the consolidated result and authorized revisions afterward. Finally, submit or view an E3 evaluation to confirm the CEIT-UR-E3 workflow remains separate.
