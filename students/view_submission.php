<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/student_auth.php';
$user = require_approved_student($pdo);
$studentId = (int) $user['id'];
$submissionId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM student_submissions WHERE id = ? AND student_id = ? LIMIT 1');
$stmt->execute([$submissionId, $studentId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$submission) {
    http_response_code(404);
    exit('Submission not found');
}

$path = submission_pdf_path((string) ($submission['stored_filename'] ?? ''));
if ($path === null || !submission_file_is_pdf($path)) {
    error_log('[student submission viewer] Authorized submission file is unavailable: submission_id=' . $submissionId . ' storage=' . submission_storage_path());
    http_response_code(404);
    exit('Document unavailable');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $submission['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
