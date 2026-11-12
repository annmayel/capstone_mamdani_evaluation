<?php
require_once __DIR__ . '/../config.php';
session_start();

if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    http_response_code(403);
    exit('Forbidden');
}

$studentId = (int) $_SESSION['user']['id'];
$submissionId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM student_submissions WHERE id = ? AND student_id = ? LIMIT 1');
$stmt->execute([$submissionId, $studentId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$submission) {
    http_response_code(404);
    exit('Submission not found');
}

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $submission['stored_filename'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Document unavailable');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $submission['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);