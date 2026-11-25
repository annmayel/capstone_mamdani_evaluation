<?php

function log_workflow_database_error(string $stage, string $operation, Throwable $error, ?string $migration = null): void
{
    $databaseError = $error;
    while ($databaseError && !($databaseError instanceof PDOException)) {
        $databaseError = $databaseError->getPrevious();
    }
    $errorInfo = $databaseError instanceof PDOException ? $databaseError->errorInfo : null;
    $sqlState = (string) ($errorInfo[0] ?? $error->getCode());
    $driverCode = (string) ($errorInfo[1] ?? '');
    $route = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'unknown';
    error_log(sprintf(
        '[WORKFLOW DATABASE ERROR] stage=%s operation=%s migration=%s sqlstate=%s driver_code=%s route=%s message=%s',
        $stage,
        $operation,
        $migration ?? 'none',
        $sqlState,
        $driverCode,
        $route,
        $error->getMessage()
    ));
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
