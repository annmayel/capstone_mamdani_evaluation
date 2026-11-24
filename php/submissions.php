<?php

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/stage_submission_migration.php';
        ensure_stage_submission_schema($pdo);
    } catch (Throwable $schemaError) {
        error_log('[STAGE SUBMISSION SCHEMA ERROR] ' . $schemaError->getMessage());
        http_response_code(503);
        exit('The paper submission database update could not be completed. Please contact the system administrator.');
    }
}

function latest_stage_submission(PDO $pdo, int $projectId, string $stage): ?array
{
    if (!in_array($stage, ['proposal', 'final'], true)) {
        throw new InvalidArgumentException('Unknown submission stage.');
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM student_submissions
         WHERE project_id = ? AND stage = ?
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$projectId, $stage]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
    return $submission ?: null;
}

function proposal_allows_final_submission(array $project, ?array $proposalSubmission): bool
{
    if (empty($project['proposal_confirmed_at']) || empty($project['proposal_official_verdict'])) {
        return false;
    }

    $hasRequiredRevisions = trim((string) ($project['proposal_required_revisions'] ?? '')) !== '';
    if ($project['proposal_official_verdict'] === 'Approved' && !$hasRequiredRevisions) {
        return true;
    }

    return in_array($project['proposal_official_verdict'], ['Approved', 'Approved with Minor Revision', 'Approved with Major Revision'], true)
        && $proposalSubmission !== null
        && !empty($project['proposal_revision_accepted_submission_id'])
        && (int) $project['proposal_revision_accepted_submission_id'] === (int) $proposalSubmission['id'];
}

function stage_submission_status_label(?array $submission): string
{
    if (!$submission) {
        return 'Not Submitted';
    }

    $labels = [
        'submitted' => 'Submitted',
        'under_evaluation' => 'Under Evaluation',
        'evaluated' => 'Evaluated',
        'revision_requested' => 'Revision Required',
        'revision_required' => 'Revision Required',
        'approved' => 'Approved',
        'accepted' => 'Accepted',
    ];
    return $labels[$submission['status']] ?? ucfirst(str_replace('_', ' ', (string) $submission['status']));
}
