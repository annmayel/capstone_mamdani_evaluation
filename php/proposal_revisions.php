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
    $status = $summary['returned_count'] > 0 ? 'returned' : ($summary['pending_count'] === 0 && $summary['assigned_count'] > 0 ? 'accepted' : 'pending_panel_review');
    $pdo->prepare('UPDATE proposal_revision_cycles SET status=?, completed_at=? WHERE id=?')->execute([$status, $status === 'pending_panel_review' ? null : date('Y-m-d H:i:s'), $cycleId]);
    return $summary + ['status'=>$status];
}

function proposal_is_major_revision(array $recommendation): bool
{
    return ($recommendation['label'] ?? '') === 'PASSED/APPROVED (Major Revision)';
}
