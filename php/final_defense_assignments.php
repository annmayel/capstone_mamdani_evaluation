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
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute(['final_defense_evaluator_assignments']);
            $available = (bool) $stmt->fetchColumn();
            return $available;
        }
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

/**
 * Count only the submitted E3 sheets that belong to the exact Final Defense
 * assignment panel. This deliberately excludes project-level proposal
 * assignments and any stale E3 row not backed by a final assignment.
 */
function final_defense_panel_progress(PDO $pdo, int $projectId, int $submissionId): array
{
    $assignedCount = final_defense_assigned_evaluator_count($pdo, $projectId, $submissionId);
    if ($assignedCount === 0) {
        return ['assigned_count' => 0, 'submitted_count' => 0, 'complete' => false];
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT e.evaluator_id)
         FROM e3_evaluations e
         INNER JOIN final_defense_evaluator_assignments a
           ON a.project_id = e.project_id
          AND a.final_submission_id = e.final_submission_id
          AND a.evaluator_id = e.evaluator_id
         WHERE e.project_id = ? AND e.final_submission_id = ? AND e.status = ?'
    );
    $stmt->execute([$projectId, $submissionId, 'submitted']);
    $submittedCount = (int) $stmt->fetchColumn();

    return [
        'assigned_count' => $assignedCount,
        'submitted_count' => $submittedCount,
        'complete' => $submittedCount === $assignedCount,
    ];
}
