<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/e3.php';
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
$proposalDraft = false;
$proposalPanelComplete = false;
$finalReady = false;
$finalSubmitted = false;
$finalDraft = false;
$finalPanelComplete = false;
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
        $proposalEvaluationStatus = (string) ($evaluationStmt->fetchColumn() ?: '');
        $proposalSubmitted = $proposalEvaluationStatus === 'submitted';
        $proposalDraft = $proposalEvaluationStatus === 'draft';
        $proposalPanel = proposal_panel_completion($pdo, $projectId, (int) $proposalSubmission['id']);
        $proposalPanelComplete = $proposalPanel['ready'];
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
        $finalPanel = e3_consolidated($pdo, $projectId, (int) $finalSubmission['id']);
        $assignedCountStmt = $pdo->prepare('SELECT COUNT(DISTINCT evaluator_id) FROM evaluator_assignments WHERE project_id=?');
        $assignedCountStmt->execute([$projectId]);
        $assignedCount = (int) $assignedCountStmt->fetchColumn();
        $finalPanelComplete = $finalPanel['evaluator_count'] > 0 && $finalPanel['evaluator_count'] >= $assignedCount;
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
$proposalStatus = $proposalSubmitted ? 'Submitted' : ($proposalDraft ? 'Draft' : ($proposalReady ? 'Assigned' : 'Not Available'));
$proposalAction = $proposalSubmitted ? 'View Submitted Evaluation' : ($proposalDraft ? 'Continue Draft' : ($proposalReady ? 'Start Proposal Evaluation' : 'Proposal PDF Required'));
$proposalPanelStatus = $proposalPanelComplete ? 'Panel Completed' : ($proposalSubmitted ? 'Pending Panel Evaluation' : ($proposalDraft ? 'Draft saved' : 'Awaiting your evaluation'));
$finalStatus = $finalSubmitted ? 'Submitted' : ($finalDraft ? 'Draft' : ($finalReady ? 'Assigned' : 'Not Available'));
$finalActionLabel = $finalSubmitted ? 'View Submitted Evaluation' : ($finalDraft ? 'Continue Draft' : ($finalReady ? 'Start Final Hearing Evaluation' : $finalLockReason));
$finalPanelStatus = $finalPanelComplete ? 'Panel Completed' : ($finalSubmitted ? 'Pending Panel Evaluation' : ($finalDraft ? 'Draft saved' : $finalLockReason));
$proposalPoints = array_sum(array_map(static fn(array $criterion): int => (int) $criterion['maximum'], proposal_criteria()));
$finalPoints = array_sum(array_map(static fn(array $criterion): int => (int) $criterion['maximum'], e3_criteria()));
$defenseDate = trim((string) ($project['defense_date'] ?? ''));
$defenseDateLabel = $defenseDate !== '' && strtotime($defenseDate) !== false ? date('M j, Y', strtotime($defenseDate)) : 'Not scheduled';
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
  <link rel="stylesheet" href="evaluate_selection.css?v=20261126">
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
    <a class="selection-back" href="projects.php"><span aria-hidden="true">&larr;</span> Evaluation Workspace</a>
    <header class="main-header selection-header"><div class="main-header-text"><span class="page-eyebrow">Evaluation workspace</span><h1><span class="selection-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 8 4-8 4-8-4 8-4Z"/><path d="m4 12 8 4 8-4"/><path d="m4 17 8 4 8-4"/></svg></span>Select Evaluation Type</h1><p>Choose the appropriate evaluation workflow for this capstone project.</p></div></header>
    <?php if (($_GET['evaluation_error'] ?? '') === 'proposal_not_submitted'): ?><div class="e3-alert error" role="alert">A valid submitted Proposal PDF is required before proposal scoring can begin.</div><?php endif; ?>
    <?php if (($_GET['evaluation_error'] ?? '') === 'final_not_submitted'): ?><div class="e3-alert error" role="alert">A valid Final Paper and the approved-proposal prerequisites are required for Final Hearing.</div><?php endif; ?>
    <section class="selection-project-banner" aria-label="Project information">
      <div class="selection-project-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg></div>
      <div class="selection-project-copy"><h2><?=esc((string) $project['title'])?></h2><div class="selection-project-meta"><span><b>Group</b> <?=esc((string) ($project['group_name'] ?? 'Not set'))?></span><span><b>Adviser</b> <?=esc((string) ($project['adviser'] ?? 'Not assigned'))?></span><span><b>Defense date</b> <?=esc($defenseDateLabel)?></span></div></div>
    </section>
    <section class="selection-grid" aria-label="Evaluation type">
      <?php if ($proposalReady): ?><a class="selection-card selection-card-proposal" href="evaluate_project.php?project_id=<?=$projectId?>&amp;proposal_submission_id=<?=esc($proposalSubmission['id'])?>"><?php else: ?><article class="selection-card selection-card-proposal is-locked" aria-disabled="true"><?php endif; ?>
        <div class="selection-card-top"><div class="selection-card-icon proposal-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2-2V8l-6-6Z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg></div><div class="selection-card-heading"><h2>Proposal Evaluation</h2><p>CEIT-T07A</p></div><span class="selection-status status-<?=esc(strtolower(str_replace(' ', '-', $proposalStatus)))?>"><?=esc($proposalStatus)?></span></div>
        <p class="selection-description"><?=$proposalSubmitted ? 'View your submitted proposal score sheet, feedback, and official CEIT-T07A rubric.' : 'Evaluate the proposal manuscript and proposed project using the official CEIT-T07A score sheet.'?></p>
        <dl class="selection-details"><div><dt>Rubric</dt><dd>CEIT-T07A Proposal Evaluation</dd></div><div><dt>Total points</dt><dd><?=esc((string) $proposalPoints)?> points</dd></div><div><dt>Panel status</dt><dd><?=esc($proposalPanelStatus)?></dd></div></dl>
        <span class="selection-action"><?=$proposalReady ? '<span aria-hidden="true">&#128065;</span>' : '<span aria-hidden="true">&#128274;</span>'?> <?=esc($proposalAction)?></span>
      <?php if ($proposalReady): ?></a><?php else: ?></article><?php endif; ?>
      <?php if ($finalReady): ?><a class="selection-card selection-card-final" href="<?= $finalSubmitted ? 'view_e3_evaluation.php' : 'evaluate_e3.php' ?>?project_id=<?=$projectId?>&amp;final_submission_id=<?=esc($finalSubmission['id'])?>"><?php else: ?><article class="selection-card selection-card-final is-locked" aria-disabled="true"><?php endif; ?>
        <div class="selection-card-top"><div class="selection-card-icon hearing-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 19h16M6 16V8h12v8M9 8V5h6v3M9 12h.01M12 12h.01M15 12h.01"/></svg></div><div class="selection-card-heading"><h2>Final Hearing Evaluation</h2><p>CEIT-UR-E3</p></div><span class="selection-status status-<?=esc(strtolower(str_replace(' ', '-', $finalStatus)))?>"><?=esc($finalStatus)?></span></div>
        <p class="selection-description">Evaluate the capstone project using the official CEIT-UR-E3 final hearing rubric.</p>
        <dl class="selection-details"><div><dt>Rubric</dt><dd>CEIT-UR-E3 Final Hearing</dd></div><?php if ($finalPoints === 100): ?><div><dt>Total points</dt><dd>100 points</dd></div><?php endif; ?><div><dt>Status</dt><dd><?=esc($finalPanelStatus)?></dd></div></dl>
        <span class="selection-action"><?=$finalReady ? '<span aria-hidden="true">&#128065;</span>' : '<span aria-hidden="true">&#128274;</span>'?> <?=esc($finalActionLabel)?></span>
      <?php if ($finalReady): ?></a><?php else: ?></article><?php endif; ?>
    </section>
  </main>
</div>
<script src="evaluator-content.js?v=20261124" defer></script>
</body>
</html>
