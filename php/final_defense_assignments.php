<?php

/**
 * Final Hearing assignments are intentionally separate from project-level
 * evaluator assignments. A project assignment authorizes proposal work, while
 * this mapping identifies the exact final submission the Chairperson assigned.
 */
function final_defense_assignment_schema_available(PDO $pdo): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    try {
        $available = (bool) $pdo->query("SHOW TABLES LIKE 'final_defense_evaluator_assignments'")->fetchColumn();
    } catch (Throwable $exception) {
        error_log('[final defense assignment] Schema check failed: ' . $exception->getMessage());
        $available = false;
    }

    return $available;
}

function final_defense_evaluator_is_assigned(PDO $pdo, int $projectId, int $submissionId, int $evaluatorId): bool
{
    if (!final_defense_assignment_schema_available($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM final_defense_evaluator_assignments
         WHERE project_id = ? AND final_submission_id = ? AND evaluator_id = ?
         LIMIT 1'
    );
    $stmt->execute([$projectId, $submissionId, $evaluatorId]);
    return (bool) $stmt->fetchColumn();
}

function final_defense_assigned_evaluator_count(PDO $pdo, int $projectId, int $submissionId): int
{
    if (!final_defense_assignment_schema_available($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT evaluator_id) FROM final_defense_evaluator_assignments
         WHERE project_id = ? AND final_submission_id = ?'
    );
    $stmt->execute([$projectId, $submissionId]);
    return (int) $stmt->fetchColumn();
}
