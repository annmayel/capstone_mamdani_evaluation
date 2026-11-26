<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/proposal_revisions.php';
require_once __DIR__ . '/../php/final_defense_assignments.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['evaluator', 'dean'], true)) { http_response_code(403); exit('Forbidden'); }
$evaluatorId = (int) $_SESSION['user']['id'];
$submissionId = (int) ($_GET['id'] ?? 0);
$expectedStage = (string) ($_GET['stage'] ?? '');
if ($expectedStage !== '' && !in_array($expectedStage, ['proposal', 'final'], true)) {
    http_response_code(400);
    exit('Invalid submission stage.');
}
$revisionSchemaReady = proposal_revision_schema_available($pdo);
$finalAssignmentReady = final_defense_assignment_schema_available($pdo);
if ($_SESSION['user']['role'] === 'dean') {
    $query = 'SELECT s.* FROM student_submissions s WHERE s.id = ?';
    $params = [$submissionId];
} elseif ($finalAssignmentReady) {
    $proposalAuthorization = 'EXISTS (SELECT 1 FROM evaluator_assignments a WHERE a.project_id=s.project_id AND a.evaluator_id=?)';
    if ($revisionSchemaReady) {
        $proposalAuthorization .= ' OR EXISTS (SELECT 1 FROM proposal_revision_cycles c INNER JOIN proposal_revision_reviews r ON r.revision_cycle_id=c.id WHERE c.revision_submission_id=s.id AND r.evaluator_id=?)';
    }
    $query = "SELECT s.* FROM student_submissions s WHERE s.id = ? AND ((s.stage = 'final' AND EXISTS (SELECT 1 FROM final_defense_evaluator_assignments f WHERE f.project_id=s.project_id AND f.final_submission_id=s.id AND f.evaluator_id=?)) OR (s.stage = 'proposal' AND ({$proposalAuthorization})))";
    $params = $revisionSchemaReady ? [$submissionId, $evaluatorId, $evaluatorId, $evaluatorId] : [$submissionId, $evaluatorId, $evaluatorId];
} elseif ($revisionSchemaReady) {
    $query = "SELECT s.* FROM student_submissions s WHERE s.id = ? AND (EXISTS (SELECT 1 FROM evaluator_assignments a WHERE a.project_id=s.project_id AND a.evaluator_id=?) OR EXISTS (SELECT 1 FROM proposal_revision_cycles c INNER JOIN proposal_revision_reviews r ON r.revision_cycle_id=c.id WHERE c.revision_submission_id=s.id AND r.evaluator_id=?))";
    $params = [$submissionId, $evaluatorId, $evaluatorId];
} else {
    $query = 'SELECT s.* FROM student_submissions s INNER JOIN evaluator_assignments a ON a.project_id = s.project_id WHERE s.id = ? AND a.evaluator_id = ?';
    $params = [$submissionId, $evaluatorId];
}
if ($expectedStage !== '') {
    $query .= ' AND s.stage = ?';
}
$query .= ' LIMIT 1';
$stmt = $pdo->prepare($query);
if ($expectedStage !== '') {
    $params[] = $expectedStage;
}
$stmt->execute($params);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$submission) { http_response_code(404); exit('Document not found or you are not authorized to view it.'); }
$path = submission_pdf_path((string) ($submission['stored_filename'] ?? ''));
if ($path === null || !submission_file_is_pdf($path)) {
    error_log('[submission viewer] Authorized submission file is unavailable: submission_id=' . $submissionId . ' storage=' . submission_storage_path());
    http_response_code(404);
    exit('Document unavailable');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $submission['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
