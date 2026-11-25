<?php

function proposal_scoring_schema_state(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('evaluations_project', 'evaluator_assignments', 'projects')"
    )->fetchAll(PDO::FETCH_ASSOC);

    $columns = [];
    foreach ($rows as $row) {
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row['COLUMN_TYPE'];
    }

    return ['columns' => $columns];
}

function proposal_scoring_schema_is_ready(array $state): bool
{
    $requiredColumns = [
        'evaluations_project' => [
            'proposal_status',
            'proposal_background',
            'proposal_literature',
            'proposal_methodology',
            'proposal_design',
            'proposal_presentation',
            'proposal_question_answer',
            'proposal_background_comment',
            'proposal_literature_comment',
            'proposal_methodology_comment',
            'proposal_design_comment',
            'proposal_presentation_comment',
            'proposal_question_answer_comment',
            'proposal_total_score',
            'proposal_evaluator_comments',
        ],
        'evaluator_assignments' => ['proposal_draft'],
        'projects' => [
            'proposal_official_score',
            'proposal_official_verdict',
            'proposal_required_revisions',
            'proposal_chairperson_comments',
            'proposal_chairperson_id',
            'proposal_confirmed_at',
        ],
    ];

    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (!isset($state['columns'][$table][$column])) {
                return false;
            }
        }
    }

    $proposalStatusType = $state['columns']['evaluations_project']['proposal_status'];
    return strpos($proposalStatusType, "'draft'") !== false
        && strpos($proposalStatusType, "'submitted'") !== false;
}

function ensure_proposal_scoring_schema(PDO $pdo): void
{
    if (proposal_scoring_schema_is_ready(proposal_scoring_schema_state($pdo))) {
        return;
    }

    if (!function_exists('stage_submission_set_migration_operation')) {
        throw new RuntimeException('Load the stage submission migration helpers before repairing proposal scoring schema.');
    }

    stage_submission_set_migration_operation('resolve_proposal_scoring_database');
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'proposal-scoring-' . substr(hash('sha256', $database), 0, 40);
    stage_submission_set_migration_operation('acquire_proposal_scoring_migration_lock');
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $lockStmt->execute([$lockName]);
    if ((int) $lockStmt->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire the proposal scoring schema migration lock.');
    }

    try {
        $state = proposal_scoring_schema_state($pdo);
        if (proposal_scoring_schema_is_ready($state)) {
            return;
        }

        foreach (['evaluations_project', 'evaluator_assignments', 'projects'] as $table) {
            if (!isset($state['columns'][$table])) {
                throw new RuntimeException('A required base table is missing: ' . $table);
            }
        }

        $proposalStatusType = $state['columns']['evaluations_project']['proposal_status'] ?? null;
        if ($proposalStatusType !== null && (
            strpos($proposalStatusType, "'draft'") === false
            || strpos($proposalStatusType, "'submitted'") === false
        )) {
            throw new RuntimeException('Existing evaluations_project.proposal_status does not support draft and submitted; inspect it before migration 018 recovery.');
        }

        $definitions = [
            'evaluations_project' => [
                'proposal_status' => "ENUM('draft','submitted') NOT NULL DEFAULT 'draft'",
                'proposal_background' => 'DECIMAL(5,2) NULL',
                'proposal_literature' => 'DECIMAL(5,2) NULL',
                'proposal_methodology' => 'DECIMAL(5,2) NULL',
                'proposal_design' => 'DECIMAL(5,2) NULL',
                'proposal_presentation' => 'DECIMAL(5,2) NULL',
                'proposal_question_answer' => 'DECIMAL(5,2) NULL',
                'proposal_background_comment' => 'TEXT NULL',
                'proposal_literature_comment' => 'TEXT NULL',
                'proposal_methodology_comment' => 'TEXT NULL',
                'proposal_design_comment' => 'TEXT NULL',
                'proposal_presentation_comment' => 'TEXT NULL',
                'proposal_question_answer_comment' => 'TEXT NULL',
                'proposal_total_score' => 'DECIMAL(6,2) NULL',
                'proposal_evaluator_comments' => 'TEXT NULL',
            ],
            'evaluator_assignments' => [
                'proposal_draft' => 'LONGTEXT NULL',
            ],
            'projects' => [
                'proposal_official_score' => 'DECIMAL(6,2) NULL',
                'proposal_official_verdict' => 'VARCHAR(100) NULL',
                'proposal_required_revisions' => 'TEXT NULL',
                'proposal_chairperson_comments' => 'TEXT NULL',
                'proposal_chairperson_id' => 'INT NULL',
                'proposal_confirmed_at' => 'DATETIME NULL',
            ],
        ];

        foreach ($definitions as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (isset($state['columns'][$table][$column])) {
                    continue;
                }

                stage_submission_set_migration_operation('add_proposal_scoring_' . $table . '_' . $column);
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                $state['columns'][$table][$column] = $definition;
            }
        }

        stage_submission_set_migration_operation('verify_proposal_scoring_schema');
        if (!proposal_scoring_schema_is_ready(proposal_scoring_schema_state($pdo))) {
            throw new RuntimeException('The proposal scoring schema is still incomplete after migration 018 recovery.');
        }
    } finally {
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $releaseStmt->execute([$lockName]);
    }
}
