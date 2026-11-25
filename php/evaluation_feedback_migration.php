<?php

function evaluation_feedback_schema_state(PDO $pdo): array
{
    $tableExists = $pdo->query(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'evaluation_feedback'"
    )->fetchColumn() > 0;
    $columnRows = $pdo->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'evaluation_feedback'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $indexRows = $pdo->query(
        "SELECT INDEX_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'evaluation_feedback'"
    )->fetchAll(PDO::FETCH_COLUMN);

    return [
        'table_exists' => $tableExists,
        'columns' => array_fill_keys($columnRows, true),
        'indexes' => array_fill_keys($indexRows, true),
    ];
}

function evaluation_feedback_schema_is_ready(array $state): bool
{
    foreach ([
        'id',
        'project_id',
        'submission_id',
        'evaluator_id',
        'evaluation_type',
        'stage',
        'evaluation_id',
        'score',
        'strengths',
        'weaknesses',
        'recommendations',
        'required_revisions',
        'remarks',
        'created_at',
        'updated_at',
    ] as $column) {
        if (!isset($state['columns'][$column])) {
            return false;
        }
    }

    foreach ([
        'idx_evaluation_feedback_project',
        'idx_evaluation_feedback_evaluator',
        'idx_evaluation_feedback_created',
        'idx_evaluation_feedback_submission',
        'idx_evaluation_feedback_evaluation',
    ] as $index) {
        if (!isset($state['indexes'][$index])) {
            return false;
        }
    }

    return true;
}

function ensure_evaluation_feedback_schema(PDO $pdo): void
{
    stage_submission_set_migration_operation('inspect_evaluation_feedback_schema');
    $state = evaluation_feedback_schema_state($pdo);
    if (!$state['table_exists']) {
        stage_submission_set_migration_operation('create_evaluation_feedback_table');
        $migrationPath = __DIR__ . '/../sql/migrations/020_evaluation_feedback_recovery.sql';
        $migrationSql = file_get_contents($migrationPath);
        if ($migrationSql === false) {
            throw new RuntimeException('Could not read the evaluation feedback recovery migration.');
        }
        $pdo->exec($migrationSql);
        $state = evaluation_feedback_schema_state($pdo);
    } elseif (!isset($state['columns']['id'])) {
        throw new RuntimeException('Existing evaluation_feedback table is missing its id column.');
    }

    $requiredBaseColumns = [
        'id',
        'project_id',
        'evaluator_id',
        'evaluation_type',
        'strengths',
        'weaknesses',
        'recommendations',
        'required_revisions',
        'remarks',
        'created_at',
    ];
    foreach ($requiredBaseColumns as $column) {
        if (!isset($state['columns'][$column])) {
            throw new RuntimeException('Existing evaluation_feedback table is missing required column ' . $column . '.');
        }
    }

    foreach ([
        'submission_id' => 'INT NULL DEFAULT NULL',
        'stage' => "ENUM('proposal','final') NULL DEFAULT NULL",
        'evaluation_id' => 'INT NULL DEFAULT NULL',
        'score' => 'DECIMAL(6,2) NULL DEFAULT NULL',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ] as $column => $definition) {
        if (!isset($state['columns'][$column])) {
            stage_submission_set_migration_operation('add_evaluation_feedback_' . $column);
            $pdo->exec("ALTER TABLE `evaluation_feedback` ADD COLUMN `$column` $definition");
            $state['columns'][$column] = true;
        }
    }

    foreach ([
        'idx_evaluation_feedback_project' => 'project_id',
        'idx_evaluation_feedback_evaluator' => 'evaluator_id',
        'idx_evaluation_feedback_created' => 'created_at',
        'idx_evaluation_feedback_submission' => 'submission_id',
        'idx_evaluation_feedback_evaluation' => 'evaluation_id',
    ] as $index => $column) {
        if (!isset($state['indexes'][$index])) {
            stage_submission_set_migration_operation('add_evaluation_feedback_' . $index);
            $pdo->exec("ALTER TABLE `evaluation_feedback` ADD KEY `$index` (`$column`)");
            $state['indexes'][$index] = true;
        }
    }

    stage_submission_set_migration_operation('verify_evaluation_feedback_schema');
    if (!evaluation_feedback_schema_is_ready(evaluation_feedback_schema_state($pdo))) {
        throw new RuntimeException('The evaluation_feedback schema is still incomplete after migration.');
    }
}
