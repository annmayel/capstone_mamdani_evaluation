<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || !in_array($_SESSION['user']['role'], ['evaluator', 'dean'], true)) { http_response_code(403); exit('Forbidden'); }
$evaluatorId = (int) $_SESSION['user']['id'];
$submissionId = (int) ($_GET['id'] ?? 0);
$query = $_SESSION['user']['role'] === 'dean'
    ? 'SELECT s.* FROM student_submissions s WHERE s.id = ? LIMIT 1'
    : 'SELECT s.* FROM student_submissions s INNER JOIN evaluator_assignments a ON a.project_id = s.project_id WHERE s.id = ? AND a.evaluator_id = ? LIMIT 1';
$stmt = $pdo->prepare($query);
$stmt->execute($_SESSION['user']['role'] === 'dean' ? [$submissionId] : [$submissionId, $evaluatorId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$submission) { http_response_code(404); exit('Document not found or you are not authorized to view it.'); }
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $submission['stored_filename'];
if (!is_file($path)) { http_response_code(404); exit('Document unavailable'); }
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $submission['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
