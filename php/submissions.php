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

/**
 * Directory for private submission files. Railway should set
 * SUBMISSION_STORAGE_PATH to a directory within its mounted Volume (for
 * example /data/submissions). The repository-relative default preserves local
 * development installations.
 */
function submission_storage_path(): string
{
    $configured = getenv('SUBMISSION_STORAGE_PATH');
    if ($configured === false || trim((string) $configured) === '') {
        $configured = $_SERVER['SUBMISSION_STORAGE_PATH'] ?? $_ENV['SUBMISSION_STORAGE_PATH'] ?? '';
    }

    $configured = trim((string) $configured);
    if ($configured !== '') {
        $normalized = rtrim($configured, "\\/ ");
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions';
}

/**
 * Build a private PDF path without accepting directory traversal from a
 * database value. Callers must still enforce their own user authorization.
 */
function submission_pdf_path(string $storedFilename): ?string
{
    if ($storedFilename === '' || basename($storedFilename) !== $storedFilename) {
        return null;
    }

    return rtrim(submission_storage_path(), "\\/") . '/' . $storedFilename;
}

function submission_file_is_pdf(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    try {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        return $mime === 'application/pdf';
    } catch (Throwable $exception) {
        error_log('[submission storage] Unable to inspect PDF MIME type: ' . $exception->getMessage());
        return false;
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

/**
 * Return the newest uploaded-PDF record for one project stage. The database
 * record is authoritative for workflow availability: the Student Portal uses
 * the same project/stage query. File serving remains responsible for resolving
 * the storage path when an evaluator opens the PDF.
 */
function latest_stored_pdf_stage_submission(PDO $pdo, int $projectId, string $stage): ?array
{
    if (!in_array($stage, ['proposal', 'final'], true)) {
        throw new InvalidArgumentException('Unknown submission stage.');
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM student_submissions
         WHERE project_id = ? AND stage = ?
           AND stored_filename IS NOT NULL AND stored_filename <> ''
         ORDER BY id DESC"
    );
    $stmt->execute([$projectId, $stage]);
    while ($submission = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $filename = (string) ($submission['stored_filename'] ?? '');
        if ($filename === '' || basename($filename) !== $filename) {
            continue;
        }
        if (str_ends_with(strtolower($filename), '.pdf')) {
            return $submission;
        }
    }

    return null;
}

function proposal_allows_final_submission(array $project, ?array $proposalSubmission): bool
{
    if (empty($project['proposal_confirmed_at']) || empty($project['proposal_official_verdict'])) {
        return false;
    }

    $hasRequiredRevisions = trim((string) ($project['proposal_required_revisions'] ?? '')) !== '';
    if ($project['proposal_official_verdict'] === 'PASSED/APPROVED (No Revision)' && !$hasRequiredRevisions) {
        return true;
    }

    return in_array($project['proposal_official_verdict'], ['PASSED/APPROVED (No Revision)', 'PASSED/APPROVED (Minor Revision)', 'PASSED/APPROVED (Major Revision)'], true)
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
