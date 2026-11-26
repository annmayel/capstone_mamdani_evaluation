<?php

require_once __DIR__ . '/proposal_evaluation.php';

function proposal_revision_schema_available(PDO $pdo): bool
{
    try {
        return $pdo->query("SHOW TABLES LIKE 'proposal_revision_cycles'")->fetchColumn() !== false
            && $pdo->query("SHOW TABLES LIKE 'proposal_revision_reviews'")->fetchColumn() !== false;
    } catch (PDOException $exception) {
        error_log('[proposal revisions] Schema check failed: ' . $exception->getMessage());
        return false;
    }
}

function proposal_revision_cycle(PDO $pdo, int $projectId, ?int $revisionSubmissionId = null): ?array
{
    $sql = 'SELECT * FROM proposal_revision_cycles WHERE project_id = ?';
    $params = [$projectId];
    if ($revisionSubmissionId !== null) { $sql .= ' AND revision_submission_id = ?'; $params[] = $revisionSubmissionId; }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function proposal_revision_review_summary(PDO $pdo, int $cycleId): array
{
    $stmt = $pdo->prepare('SELECT r.*, u.full_name FROM proposal_revision_reviews r INNER JOIN users u ON u.id=r.evaluator_id WHERE r.revision_cycle_id=? ORDER BY u.full_name');
    $stmt->execute([$cycleId]); $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pending = count(array_filter($reviews, static fn($row) => $row['decision'] === 'pending'));
    $returned = count(array_filter($reviews, static fn($row) => $row['decision'] === 'returned'));
    return ['reviews'=>$reviews, 'assigned_count'=>count($reviews), 'pending_count'=>$pending, 'returned_count'=>$returned, 'accepted_count'=>count($reviews)-$pending-$returned];
}

function proposal_revision_refresh_status(PDO $pdo, int $cycleId): array
{
    $summary = proposal_revision_review_summary($pdo, $cycleId);
    // The panel decision is not final until every original reviewer has
    // responded. A single return must not reopen the student upload while
    // other panel decisions are still pending.
    $status = $summary['pending_count'] > 0
        ? 'pending_panel_review'
        : ($summary['returned_count'] > 0 ? 'returned' : ($summary['assigned_count'] > 0 ? 'accepted' : 'pending_panel_review'));
    $pdo->prepare('UPDATE proposal_revision_cycles SET status=?, completed_at=? WHERE id=?')->execute([$status, $status === 'pending_panel_review' ? null : date('Y-m-d H:i:s'), $cycleId]);
    return $summary + ['status'=>$status];
}

/**
 * A completed accepted cycle is the authoritative completion event for a
 * revision-required proposal. The original Chairperson verdict remains part
 * of the proposal record; it is not recalculated or replaced here.
 */
function proposal_revision_is_fully_accepted(?array $cycle, ?array $summary): bool
{
    return $cycle !== null
        && ($cycle['status'] ?? '') === 'accepted'
        && $summary !== null
        && (int) ($summary['assigned_count'] ?? 0) > 0
        && (int) ($summary['pending_count'] ?? 0) === 0
        && (int) ($summary['returned_count'] ?? 0) === 0;
}

/**
 * Reconcile revisions accepted under an earlier deployment with the
 * authoritative project pointer. This only ever derives state from the latest
 * completed accepted cycle; it does not modify scores or Chairperson verdicts.
 */
function proposal_revision_sync_accepted_submission(PDO $pdo, int $projectId, ?int $submissionId = null): bool
{
    $cycle = proposal_revision_cycle($pdo, $projectId);
    if (!proposal_revision_is_fully_accepted($cycle, $cycle ? proposal_revision_review_summary($pdo, (int) $cycle['id']) : null)) {
        return false;
    }

    $acceptedSubmissionId = (int) ($cycle['revision_submission_id'] ?? 0);
    if ($acceptedSubmissionId <= 0 || ($submissionId !== null && $acceptedSubmissionId !== $submissionId)) {
        return false;
    }

    $projectUpdate = $pdo->prepare('UPDATE projects SET proposal_revision_accepted_submission_id=? WHERE id=?');
    $projectUpdate->execute([$acceptedSubmissionId, $projectId]);
    $pdo->prepare("UPDATE student_submissions SET status='accepted' WHERE id=? AND project_id=? AND stage='proposal'")
        ->execute([$acceptedSubmissionId, $projectId]);
    return true;
}

function proposal_is_major_revision(array $recommendation): bool
{
    return ($recommendation['label'] ?? '') === 'PASSED/APPROVED (Major Revision)';
}

/**
 * Determine whether a student may create the next proposal-revision version.
 * The panel's original major-revision recommendation starts the workflow; a
 * returned revision is reusable only after every original reviewer finished
 * the current cycle.  Keeping this decision here prevents the portal and
 * submission endpoint from applying different locking rules.
 */
function proposal_revision_upload_state(
    bool $originalPanelRequiresMajorRevision,
    bool $revisionSchemaReady,
    ?array $cycle,
    ?array $summary
): string {
    if (!$originalPanelRequiresMajorRevision && !$cycle) {
        return 'not_required';
    }

    if (!$revisionSchemaReady) {
        return 'schema_required';
    }

    if (!$cycle) {
        return 'allowed';
    }

    if (($cycle['status'] ?? '') === 'accepted') {
        return 'accepted';
    }

    if (($cycle['status'] ?? '') === 'returned'
        && $summary !== null
        && (int) ($summary['pending_count'] ?? 0) === 0) {
        return 'allowed';
    }

    return 'pending';
}
