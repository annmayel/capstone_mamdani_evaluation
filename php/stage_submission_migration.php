<?php

function stage_submission_schema_state(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
             'student_submissions',
             'projects',
             'evaluations_project',
             'e3_evaluations',
             'e3_final_verdicts',
             'stage_verdict_history'
           )"
    )->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($rows as $row) {
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['COLUMN_TYPE'];
    }

    $indexRows = $pdo->query(
        "SELECT TABLE_NAME, INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('student_submissions', 'e3_evaluations')"
    )->fetchAll(PDO::FETCH_ASSOC);
    $indexes = [];
    foreach ($indexRows as $row) {
        $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']] = true;
    }

    return ['columns' => $columns, 'indexes' => $indexes];
}

function stage_submission_schema_is_ready(array $state): bool
{
    $columns = $state['columns'];
    $indexes = $state['indexes'];

    foreach ([
        'student_submissions' => ['stage', 'status'],
        'projects' => ['proposal_verdict_submission_id', 'proposal_revision_accepted_submission_id'],
        'evaluations_project' => ['proposal_submission_id'],
        'e3_evaluations' => ['final_submission_id'],
        'e3_final_verdicts' => ['final_submission_id'],
        'stage_verdict_history' => ['id'],
    ] as $table => $requiredColumns) {
        foreach ($requiredColumns as $column) {
            if (!isset($columns[$table][$column])) {
                return false;
            }
        }
    }

    $statusType = $columns['student_submissions']['status'];
    foreach (["'revision_required'", "'approved'", "'accepted'"] as $status) {
        if (strpos($statusType, $status) === false) {
            return false;
        }
    }
    $stageType = $columns['student_submissions']['stage'];
    if (strpos($stageType, "'proposal'") === false || strpos($stageType, "'final'") === false) {
        return false;
    }

    return isset($indexes['student_submissions']['idx_student_submissions_project_stage'])
        && isset($indexes['e3_evaluations']['uniq_e3_evaluation_submission_evaluator'])
        && !isset($indexes['e3_evaluations']['uniq_e3_evaluation_project_evaluator']);
}

function ensure_stage_submission_schema(PDO $pdo): void
{
    if (stage_submission_schema_is_ready(stage_submission_schema_state($pdo))) {
        return;
    }

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'stage-workflow-' . substr(hash('sha256', $database), 0, 40);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the database schema migration lock.');
    }

    try {
        $state = stage_submission_schema_state($pdo);
        if (stage_submission_schema_is_ready($state)) {
            return;
        }

        foreach (['student_submissions', 'projects', 'evaluations_project', 'e3_evaluations', 'e3_final_verdicts'] as $table) {
            if (!isset($state['columns'][$table])) {
                throw new RuntimeException('A required base table is missing: ' . $table);
            }
        }

        $columns = $state['columns'];
        $indexes = $state['indexes'];

        if (!isset($columns['student_submissions']['stage'])) {
            $pdo->exec(
                "ALTER TABLE `student_submissions`
                 ADD COLUMN `stage` ENUM('proposal','final') NOT NULL DEFAULT 'proposal' AFTER `project_id`"
            );
        }
        $statusType = $columns['student_submissions']['status'] ?? '';
        if (
            strpos($statusType, "'revision_required'") === false
            || strpos($statusType, "'approved'") === false
            || strpos($statusType, "'accepted'") === false
        ) {
            $pdo->exec(
                "ALTER TABLE `student_submissions`
                 MODIFY COLUMN `status` ENUM(
                   'submitted',
                   'under_evaluation',
                   'evaluated',
                   'revision_requested',
                   'revision_required',
                   'approved',
                   'accepted'
                 ) NOT NULL DEFAULT 'submitted'"
            );
        }
        if (!isset($indexes['student_submissions']['idx_student_submissions_project_stage'])) {
            $pdo->exec(
                'ALTER TABLE `student_submissions`
                 ADD KEY `idx_student_submissions_project_stage` (`project_id`,`stage`,`id`)'
            );
        }

        foreach ([
            'proposal_verdict_submission_id' => 'INT NULL DEFAULT NULL',
            'proposal_revision_accepted_submission_id' => 'INT NULL DEFAULT NULL',
        ] as $column => $definition) {
            if (!isset($columns['projects'][$column])) {
                $pdo->exec("ALTER TABLE `projects` ADD COLUMN `$column` $definition");
            }
        }
        if (!isset($columns['evaluations_project']['proposal_submission_id'])) {
            $pdo->exec(
                'ALTER TABLE `evaluations_project`
                 ADD COLUMN `proposal_submission_id` INT NULL DEFAULT NULL'
            );
        }

        $pdo->exec(
            "UPDATE `evaluations_project` e
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
             WHERE e.proposal_status = 'submitted' AND e.proposal_submission_id IS NULL"
        );
        $pdo->exec(
            "UPDATE `projects` p
             SET p.proposal_verdict_submission_id = (
               SELECT latest_submission.id
               FROM `student_submissions` latest_submission
               WHERE latest_submission.project_id = p.id AND latest_submission.stage = 'proposal'
               ORDER BY latest_submission.created_at DESC, latest_submission.id DESC
               LIMIT 1
             )
             WHERE p.proposal_confirmed_at IS NOT NULL AND p.proposal_verdict_submission_id IS NULL"
        );

        if (!isset($columns['e3_evaluations']['final_submission_id'])) {
            $pdo->exec(
                'ALTER TABLE `e3_evaluations`
                 ADD COLUMN `final_submission_id` INT NULL DEFAULT NULL'
            );
        }
        if (isset($indexes['e3_evaluations']['uniq_e3_evaluation_project_evaluator'])) {
            $pdo->exec('ALTER TABLE `e3_evaluations` DROP INDEX `uniq_e3_evaluation_project_evaluator`');
        }
        if (!isset($indexes['e3_evaluations']['uniq_e3_evaluation_submission_evaluator'])) {
            $pdo->exec(
                'ALTER TABLE `e3_evaluations`
                 ADD UNIQUE KEY `uniq_e3_evaluation_submission_evaluator` (`project_id`,`evaluator_id`,`final_submission_id`)'
            );
        }
        if (!isset($columns['e3_final_verdicts']['final_submission_id'])) {
            $pdo->exec(
                'ALTER TABLE `e3_final_verdicts`
                 ADD COLUMN `final_submission_id` INT NULL DEFAULT NULL'
            );
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `stage_verdict_history` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!stage_submission_schema_is_ready(stage_submission_schema_state($pdo))) {
            throw new RuntimeException('The stage submission schema is still incomplete after migration.');
        }
    } finally {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$lockName]);
    }
}
