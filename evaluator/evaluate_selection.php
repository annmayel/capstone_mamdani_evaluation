<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
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
$proposalSubmitted = false;
$finalReady = false;
$finalSubmitted = false;
$finalDraft = false;
$finalLockReason = 'Waiting for Final Paper';
try {
    $proposalSubmission = latest_stored_pdf_stage_submission($pdo, $projectId, 'proposal');
    $finalSubmission = latest_stage_submission($pdo, $projectId, 'final');
    $finalPdf = $finalSubmission ? submission_pdf_path((string) ($finalSubmission['stored_filename'] ?? '')) : null;
    $proposalReady = $proposalSubmission
        && (int) ($proposalSubmission['project_id'] ?? 0) === $projectId;
    if ($proposalReady && proposal_evaluation_schema_available($pdo)) {
        $evaluationStmt = $pdo->prepare(
            'SELECT status FROM proposal_evaluations WHERE project_id = ? AND proposal_submission_id = ? AND evaluator_id = ? ORDER BY id DESC LIMIT 1'
        );
        $evaluationStmt->execute([$projectId, (int) $proposalSubmission['id'], $evaluatorId]);
        $proposalSubmitted = ($evaluationStmt->fetchColumn() === 'submitted');
    }
    $finalReady = $finalSubmission
        && (int) ($finalSubmission['project_id'] ?? 0) === $projectId
        && in_array($finalSubmission['status'], ['submitted', 'under_evaluation', 'accepted', 'revision_required'], true)
        && $finalPdf !== null
        && is_file($finalPdf)
        && proposal_allows_final_submission($project, $proposalSubmission);
    if ($finalReady) {
        $finalEvaluationStmt = $pdo->prepare('SELECT status FROM e3_evaluations WHERE project_id=? AND final_submission_id=? AND evaluator_id=? ORDER BY id DESC LIMIT 1');
        $finalEvaluationStmt->execute([$projectId, (int) $finalSubmission['id'], $evaluatorId]);
        $finalEvaluationStatus = (string) ($finalEvaluationStmt->fetchColumn() ?: '');
        $finalSubmitted = $finalEvaluationStatus === 'submitted';
        $finalDraft = $finalEvaluationStatus === 'draft';
    } elseif (!$finalSubmission) {
        $finalLockReason = 'Waiting for Final Paper';
    } elseif (!proposal_allows_final_submission($project, $proposalSubmission)) {
        $finalLockReason = 'Proposal approval required';
    } elseif ($finalPdf === null || !is_file($finalPdf)) {
        $finalLockReason = 'Final PDF unavailable';
    } else {
        $finalLockReason = 'Final Hearing unavailable';
    }
} catch (PDOException $exception) {
    error_log('[evaluation selection] Stage submission records are unavailable: ' . $exception->getMessage());
}
$proposalStatusLabel = stage_submission_status_label($proposalSubmission);
$finalStatusLabel = stage_submission_status_label($finalSubmission);
if ($finalSubmitted) {
    $finalStatusLabel = 'Evaluation Submitted';
} elseif ($finalDraft) {
    $finalStatusLabel = 'Draft Saved';
}
$user = $_SESSION['user'];
$name = trim((string) ($user['full_name'] ?? 'Evaluator'));
$email = trim((string) ($user['email'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Select Evaluation Type</title>
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
    <header class="main-header selection-header"><div class="main-header-text"><span class="page-eyebrow">Evaluation workspace</span><h1>Select Evaluation Type</h1><p>Choose the appropriate evaluation workflow for <?=esc($project['title'])?>.</p></div></header>
    <?php if (($_GET['evaluation_error'] ?? '') === 'proposal_not_submitted'): ?><div class="e3-alert error" role="alert">A valid submitted Proposal PDF is required before proposal scoring can begin.</div><?php endif; ?>
    <?php if (($_GET['evaluation_error'] ?? '') === 'final_not_submitted'): ?><div class="e3-alert error" role="alert">A valid Final Paper and the approved-proposal prerequisites are required for Final Hearing.</div><?php endif; ?>
    <section class="selection-grid" aria-label="Evaluation type">
      <?php if ($proposalReady): ?><a class="selection-card" href="evaluate_project.php?project_id=<?=$projectId?>&amp;proposal_submission_id=<?=esc($proposalSubmission['id'])?>"><?php else: ?><article class="selection-card is-locked" aria-disabled="true"><?php endif; ?>
        <div class="selection-card-icon proposal-icon" aria-hidden="true"><span>▱</span></div>
        <div class="selection-card-copy"><span class="selection-label"><?=$proposalSubmitted ? 'Evaluation Submitted' : 'Proposal stage · ' . $proposalStatusLabel?></span><h2>Proposal Evaluation</h2><p><?=$proposalSubmitted ? 'View your submitted CEIT-T07A score sheet and feedback.' : 'Record the proposal verdict and comments before project development.'?></p></div>
        <span class="selection-action"><?=$proposalReady ? ($proposalSubmitted ? 'View Submitted Evaluation' : 'Start Proposal Evaluation') : 'Proposal PDF Required'?> <b><?=$proposalReady ? '→' : '&#128274;'?></b></span>
      <?php if ($proposalReady): ?></a><?php else: ?></article><?php endif; ?>
      <?php $finalActionLabel = $finalSubmitted ? 'View Submitted Evaluation' : ($finalDraft ? 'Continue Draft' : 'Start Final Hearing Evaluation'); ?>
      <?php if ($finalReady): ?><a class="selection-card" href="<?= $finalSubmitted ? 'view_e3_evaluation.php' : 'evaluate_e3.php' ?>?project_id=<?=$projectId?>&amp;final_submission_id=<?=esc($finalSubmission['id'])?>"><?php else: ?><article class="selection-card is-locked" aria-disabled="true"><?php endif; ?>
        <div class="selection-card-icon hearing-icon" aria-hidden="true"><span>✓</span></div>
        <div class="selection-card-copy"><span class="selection-label">Final hearing · <?=esc($finalStatusLabel)?></span><h2>Final Hearing Evaluation</h2><p>Evaluate the capstone project using the CEIT-UR-E3 final hearing rubric.</p></div>
        <span class="selection-action"><?=esc($finalReady ? $finalActionLabel : $finalLockReason)?> <b><?=$finalReady ? '→' : '&#128274;'?></b></span>
      <?php if ($finalReady): ?></a><?php else: ?></article><?php endif; ?>
    </section>
  </main>
</div>
<script src="evaluator-content.js?v=20261124" defer></script>
</body>
</html>
