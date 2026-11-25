<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/submissions.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['evaluator', 'dean'], true)) { http_response_code(403); exit('Forbidden'); }
$evaluatorId = (int) $_SESSION['user']['id'];
$submissionId = (int) ($_GET['id'] ?? 0);
$expectedStage = (string) ($_GET['stage'] ?? '');
if ($expectedStage !== '' && !in_array($expectedStage, ['proposal', 'final'], true)) {
    http_response_code(400);
    exit('Invalid submission stage.');
}
$query = $_SESSION['user']['role'] === 'dean'
    ? 'SELECT s.* FROM student_submissions s WHERE s.id = ?'
    : 'SELECT s.* FROM student_submissions s INNER JOIN evaluator_assignments a ON a.project_id = s.project_id WHERE s.id = ? AND a.evaluator_id = ?';
if ($expectedStage !== '') {
    $query .= ' AND s.stage = ?';
}
$query .= ' LIMIT 1';
$stmt = $pdo->prepare($query);
$params = $_SESSION['user']['role'] === 'dean' ? [$submissionId] : [$submissionId, $evaluatorId];
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
