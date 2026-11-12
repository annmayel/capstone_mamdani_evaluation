<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'dean') {
    header('Location: ../index.php');
    exit;
}

$submissionId = (int) ($_GET['id'] ?? 0);
if ($submissionId < 1) {
    http_response_code(400);
    exit('Invalid submission.');
}

$stmt = $pdo->prepare('SELECT original_filename FROM student_submissions WHERE id = ? LIMIT 1');
$stmt->execute([$submissionId]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$submission) {
    http_response_code(404);
    exit('Submission not found.');
}

$filename = htmlspecialchars($submission['original_filename'] ?? 'submission.pdf', ENT_QUOTES, 'UTF-8');
$pdfUrl = '../evaluator/view_submission.php?id=' . $submissionId;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>View Submission - <?= $filename ?></title>
  <link rel="stylesheet" href="../assets/style.css">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="dean-document-page">
  <main class="dean-document-shell">
    <header class="dean-document-header">
      <a class="dean-back-link" href="dashboard.php#submissions">&larr; Back to Submissions</a>
      <div>
        <p class="dean-document-kicker">CAPSTONE SUBMISSION</p>
        <h1><?= $filename ?></h1>
      </div>
      <a class="btn primary" href="<?= htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') ?>" download>Download PDF</a>
    </header>
    <section class="dean-document-viewer" aria-label="PDF document viewer">
      <iframe src="<?= htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8') ?>" title="<?= $filename ?>"></iframe>
    </section>
  </main>
</body>
</html>
