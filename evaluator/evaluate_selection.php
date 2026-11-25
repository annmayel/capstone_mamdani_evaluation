<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/submissions.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$projectId = (int) ($_GET['project_id'] ?? 0);
$evaluatorId = (int) $_SESSION['user']['id'];
$stmt = $pdo->prepare('SELECT p.*, a.status AS assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id = p.id WHERE p.id = ? AND a.evaluator_id = ? LIMIT 1');
$stmt->execute([$projectId, $evaluatorId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php?evaluation_error=not_assigned'); exit; }
$proposalSubmission = null;
$finalSubmission = null;
$proposalReady = false;
$finalReady = false;
try {
    $proposalSubmission = latest_stage_submission($pdo, $projectId, 'proposal');
    $finalSubmission = latest_stage_submission($pdo, $projectId, 'final');
    $storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR;
    $proposalReady = $proposalSubmission
        && (int) ($proposalSubmission['project_id'] ?? 0) === $projectId
        && (int) ($proposalSubmission['student_id'] ?? 0) === (int) ($project['student_id'] ?? 0)
        && !empty($proposalSubmission['stored_filename'])
        && is_file($storage . $proposalSubmission['stored_filename']);
    $finalReady = $finalSubmission
        && (int) ($finalSubmission['project_id'] ?? 0) === $projectId
        && (int) ($finalSubmission['student_id'] ?? 0) === (int) ($project['student_id'] ?? 0)
        && in_array($finalSubmission['status'], ['submitted', 'under_evaluation'], true)
        && !empty($finalSubmission['stored_filename'])
        && is_file($storage . $finalSubmission['stored_filename'])
        && proposal_allows_final_submission($project, $proposalSubmission);
} catch (PDOException $exception) {
    error_log('[evaluation selection] Stage submission records are unavailable: ' . $exception->getMessage());
}
$proposalStatusLabel = stage_submission_status_label($proposalSubmission);
$finalStatusLabel = stage_submission_status_label($finalSubmission);
$user = $_SESSION['user'];
$name = trim((string) ($user['full_name'] ?? 'Evaluator'));
$email = trim((string) ($user['email'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Final Hearing Access</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261124">
  <link rel="stylesheet" href="evaluator-content.css?v=20261124">
  <link rel="stylesheet" href="evaluator-typography.css?v=20261124">
  <link rel="stylesheet" href="evaluate_selection.css?v=20261124">
</head>
<body class="evaluator-content-page">
<div class="dashboard-layout evaluator-layout">
  <aside class="sidebar evaluator-sidebar" id="evaluator-sidebar">
    <div class="sidebar-header"><img class="logo-icon" src="../images_bgnd/CSUCC%20LOGO.png" alt=""><div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div></div>
    <ul class="sidebar-nav"><li class="nav-section-label">Workspace</li><li><a href="dashboard.php"><span class="nav-icon">▦</span><span class="nav-label">Dashboard</span></a></li><li class="nav-section-label">Evaluation</li><li><a class="active" href="projects.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5A2.5 2.5 0 0 1 6 6h4l2 2h6.5A2.5 2.5 0 0 1 21 10.5v7A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5v-9zM3.5 9.5h17"/></svg></span><span class="nav-label">Evaluation Workspace</span></a></li><li><a href="evaluation_history.php"><span class="nav-icon">◷</span><span class="nav-label">Evaluation History</span></a></li><li class="nav-section-label">Account</li><li><a href="profile.php"><span class="nav-icon">◎</span><span class="nav-label">Profile</span></a></li><li><a href="../logout.php"><span class="nav-icon">↪</span><span class="nav-label">Logout</span></a></li></ul>
    <div class="sidebar-user evaluator-sidebar-user"><div class="sidebar-user-avatar"><?=esc(strtoupper(substr($name ?: 'E',0,1)))?></div><div class="sidebar-user-info"><h5><?=esc($name)?></h5><p><?=esc($email)?></p></div></div>
  </aside>
  <button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
  <main class="main-content selection-main">
    <div class="evaluator-topbar"><button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="evaluator-topbar-end"><div class="account-avatar"><?=esc(strtoupper(substr($name ?: 'E',0,1)))?></div><span><?=esc($name)?></span></div></div>
    <a class="selection-back" href="projects.php">← Back to Assigned Projects</a>
    <header class="main-header selection-header"><div class="main-header-text"><span class="page-eyebrow">Evaluation workspace</span><h1>Final Hearing Access</h1><p>Proposal evaluation opens directly from the Evaluation Workspace. Final Hearing remains separate for <?=esc($project['title'])?>.</p></div></header>
    <section class="selection-grid" aria-label="Evaluation type">
      <a class="selection-card" href="evaluate_project.php?project_id=<?=$projectId?>">
        <div class="selection-card-icon proposal-icon" aria-hidden="true"><span>▱</span></div>
        <div class="selection-card-copy"><span class="selection-label">Proposal stage · <?=esc($proposalStatusLabel)?></span><h2>Proposal Evaluation</h2><p>Record the proposal verdict and comments before project development.</p></div>
        <span class="selection-action">Evaluate Submission <b>→</b></span>
      </a>
      <?php if ($finalReady): ?><a class="selection-card" href="evaluate_e3.php?project_id=<?=$projectId?>"><?php else: ?><article class="selection-card is-locked" aria-disabled="true"><?php endif; ?>
        <div class="selection-card-icon hearing-icon" aria-hidden="true"><span>✓</span></div>
        <div class="selection-card-copy"><span class="selection-label">Final hearing · <?=esc($finalStatusLabel)?></span><h2>Final Hearing Evaluation</h2><p>Evaluate the capstone project using the CEIT-UR-E3 final hearing rubric.</p></div>
        <span class="selection-action"><?=$finalReady ? 'Start Final Hearing Evaluation' : 'Waiting for Final Paper'?> <b><?=$finalReady ? '→' : '&#128274;'?></b></span>
      <?php if ($finalReady): ?></a><?php else: ?></article><?php endif; ?>
    </section>
  </main>
</div>
<script src="evaluator-content.js?v=20261124" defer></script>
</body>
</html>
