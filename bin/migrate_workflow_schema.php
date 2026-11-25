<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/stage_submission_migration.php';
require_once __DIR__ . '/../php/proposal_scoring_migration.php';
require_once __DIR__ . '/../php/evaluation_feedback_migration.php';

$migration = '018/019/020';

try {
    ensure_proposal_scoring_schema($pdo);
    ensure_stage_submission_schema($pdo);
    ensure_evaluation_feedback_schema($pdo);
    fwrite(STDOUT, "Workflow schema migrations completed.\n");
} catch (Throwable $error) {
    $operation = (string) ($GLOBALS['stage_submission_migration_operation'] ?? 'unknown');
    log_workflow_database_error('workflow_schema_migration', $operation, $error, $migration);
    fwrite(STDERR, "Workflow schema migration failed; see the PHP error log for details.\n");
    exit(1);
}
