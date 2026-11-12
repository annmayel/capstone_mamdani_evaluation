<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') { header('Location: ../index.php'); exit; }

$user = $_SESSION['user'];
$uid = (int) $user['id'];
$stmt = $pdo->prepare('SELECT * FROM projects WHERE student_id = ? LIMIT 1');
$stmt->execute([$uid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$submissions = [];
$tableReady = true;
try {
    $stmt = $pdo->prepare('SELECT * FROM student_submissions WHERE student_id = ? ORDER BY id DESC');
    $stmt->execute([$uid]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    $tableReady = false;
}
$latestSubmission = $submissions[0] ?? null;
$uploadMessages = ['uploaded' => 'Project, paper, and resources submitted successfully.', 'invalid_pdf' => 'Only valid PDF files up to 10 MB are accepted.', 'upload_failed' => 'The proposal upload failed. Please try again.', 'storage_failed' => 'The proposal could not be saved. Please contact the administrator.', 'save_failed' => 'The proposal could not be recorded. Please contact the administrator.', 'no_project' => 'A project record is required before submitting a proposal.', 'invalid_request' => 'The submission request could not be verified.', 'missing_submission' => 'Choose a PDF file or provide at least one project resource link.', 'missing_project_title' => 'Enter the project title so the Dean can identify the project.', 'invalid_drive_link' => 'Use a valid Google Drive link.', 'invalid_github_link' => 'Use a valid GitHub link.', 'invalid_link' => 'Use a valid Google Drive or GitHub link.'];
$messageKey = isset($_GET['uploaded']) ? 'uploaded' : ($_GET['error'] ?? '');
$displayName = $user['full_name'] ?? 'Student';
$initial = strtoupper(substr($displayName, 0, 1));
$formatDate = static fn($date) => $date ? date('M d, Y g:i A', strtotime($date)) : 'Not set';
$status = $project['status'] ?? ($latestSubmission['status'] ?? 'No proposal submitted');
$statusClass = str_contains(strtolower($status), 'evaluat') ? 'success' : (str_contains(strtolower($status), 'revision') ? 'warning' : 'info');
$assignedEvaluators = [];
if ($project) {
    $assignmentStmt = $pdo->prepare('SELECT u.full_name FROM evaluator_assignments a INNER JOIN users u ON u.id = a.evaluator_id WHERE a.project_id = ? ORDER BY u.full_name');
    $assignmentStmt->execute([(int) $project['id']]);
    $assignedEvaluators = $assignmentStmt->fetchAll(PDO::FETCH_COLUMN);
}
$submissionStatus = $latestSubmission['status'] ?? null;
$waitingForAssignment = $submissionStatus === 'submitted' && !$assignedEvaluators;
$evaluatorAssignedAwaitingEvaluation = $submissionStatus === 'submitted' && !empty($assignedEvaluators);
$displayStatus = $latestSubmission
    ? ($waitingForAssignment ? 'Waiting for evaluator assignment' : ($evaluatorAssignedAwaitingEvaluation ? 'Evaluator assigned - awaiting evaluation' : ucfirst(str_replace('_', ' ', $submissionStatus))))
    : 'Not submitted';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Project Submission - BSIT Capstone Portal</title>
  <link rel="stylesheet" href="/Fuzzy_Score/assets/style.css?v=20261011">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="student-portal">
<div class="student-shell">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <main class="student-main">
    <header class="student-topbar">
      <div><span class="eyebrow">BSIT CAPSTONE PROJECT</span><strong>Student Portal</strong></div>
      <div class="student-account"><span class="student-avatar"><?=esc($initial)?></span><span><strong><?=esc($displayName)?></strong><small>Student</small></span></div>
    </header>
    <div class="student-content">
      <div class="proposal-page">
        <div class="student-page-heading">
          <div>
            <h1>Project Submission</h1>
            <p>Upload your PDF, then wait while the Chairperson assigns an evaluator.</p>
          </div>
          <span class="status-pill <?=strtolower($displayStatus) === 'evaluated' ? 'success' : (str_contains(strtolower($displayStatus), 'revision') || str_contains(strtolower($displayStatus), 'waiting') ? 'warning' : (strtolower($displayStatus) === 'not submitted' || str_contains(strtolower($displayStatus), 'no proposal') ? 'muted' : 'info'))?>">● <?=esc($displayStatus)?></span>
        </div>

        <?php if ($messageKey && isset($uploadMessages[$messageKey])): ?>
          <div class="student-upload-message <?=$messageKey === 'uploaded' ? 'success' : 'error'?>"><?=esc($uploadMessages[$messageKey])?></div>
        <?php endif; ?>

        <section class="portal-panel submission-panel">
          <div class="proposal-context">
            <div>
              <span class="eyebrow">REGISTERED PROJECT</span>
              <strong><?=esc($project['title'] ?? 'No project registered yet')?></strong>
            </div>
            <span class="proposal-context-mark" aria-hidden="true">01</span>
          </div>

          <ol class="proposal-progress" aria-label="Submission progress">
            <li class="<?=in_array($submissionStatus, ['under_evaluation', 'evaluated', 'revision_requested'], true) || $evaluatorAssignedAwaitingEvaluation ? 'is-complete' : ''?> <?=$waitingForAssignment ? 'is-current' : ''?>" <?=$waitingForAssignment ? 'aria-current="step"' : ''?>>
              <span class="proposal-step-marker">1</span><span class="proposal-step-copy"><strong>Submitted</strong><small>Proposal received</small></span>
            </li>
            <li class="<?=$submissionStatus === 'evaluated' ? 'is-complete' : ''?> <?=in_array($submissionStatus, ['under_evaluation', 'revision_requested'], true) || $evaluatorAssignedAwaitingEvaluation ? 'is-current' : ''?>" <?=$submissionStatus === 'under_evaluation' || $submissionStatus === 'revision_requested' || $evaluatorAssignedAwaitingEvaluation ? 'aria-current="step"' : ''?>>
              <span class="proposal-step-marker">2</span><span class="proposal-step-copy"><strong>Evaluation</strong><small>Evaluation stage</small></span>
            </li>
            <li class="<?=$submissionStatus === 'evaluated' ? 'is-complete is-current' : ''?>" <?=$submissionStatus === 'evaluated' ? 'aria-current="step"' : ''?>>
              <span class="proposal-step-marker">3</span><span class="proposal-step-copy"><strong>Completed</strong><small>Evaluation complete</small></span>
            </li>
          </ol>

          <div class="panel-heading">
            <div><span class="eyebrow">CAPSTONE DOCUMENT</span><h3>Submit your project</h3></div>
            <span class="status-pill <?=$waitingForAssignment ? 'warning' : ($latestSubmission && $submissionStatus === 'evaluated' ? 'success' : ($latestSubmission && $submissionStatus === 'revision_requested' ? 'warning' : ($latestSubmission ? 'info' : 'warning')))?>">● <?=esc($latestSubmission ? $displayStatus : 'Not submitted')?></span>
          </div>
          <p class="submission-help">Upload your final proposal or paper as a PDF. The Chairperson will review your submission and assign an evaluator.</p>

          <?php if (!$project): ?>
            <div class="empty-state"><strong>Your Submit Project button will appear after your project is registered.</strong><br>The Chairperson or adviser must first link a capstone project to this student account.</div>
          <?php elseif (!$tableReady): ?>
            <div class="empty-state">Submission storage is not configured yet. Run <code>sql/migrations/001_student_submissions.sql</code>, <code>005_submission_links.sql</code>, and <code>006_project_resource_links.sql</code> first.</div>
          <?php else: ?>
            <form class="submission-form" method="post" action="submit_proposal.php" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
              <div class="submission-title-field">
                <label for="project-title">Project title</label>
                <input id="project-title" name="project_title" type="text" value="<?=esc($project['title'] ?? '')?>" placeholder="e.g. StepSense" required>
              </div>
              <div class="proposal-upload">
                <label for="proposal-file">Choose paper/proposal PDF</label>
                <div class="proposal-upload-surface">
                  <span class="proposal-upload-icon" aria-hidden="true">↑</span>
                  <strong>Choose your proposal PDF</strong>
                  <span>Click to browse files · PDF only</span>
                  <input id="proposal-file" name="proposal" type="file" accept="application/pdf">
                </div>
              </div>
              <fieldset class="proposal-resources">
                <legend>Project resources</legend>
                <div class="proposal-resource-row">
                  <span class="proposal-resource-icon" aria-hidden="true">↗</span>
                  <div class="submission-resource-field">
                    <label for="google-drive-url">Google Drive project link</label>
                    <input id="google-drive-url" name="google_drive_url" type="url" value="<?=esc($project['google_drive_url'] ?? '')?>" placeholder="https://drive.google.com/..." autocomplete="url">
                  </div>
                </div>
                <div class="proposal-resource-row">
                  <span class="proposal-resource-icon proposal-github-icon" aria-hidden="true">⌘</span>
                  <div class="submission-resource-field">
                    <label for="github-url">GitHub project link</label>
                    <input id="github-url" name="github_url" type="url" value="<?=esc($project['github_url'] ?? '')?>" placeholder="https://github.com/..." autocomplete="url">
                  </div>
                </div>
              </fieldset>
              <div class="proposal-form-footer">
                <small>Use the short project title here. PDF is for papers/documents; Google Drive and GitHub are project resources.</small>
                <button class="btn primary" type="submit">Submit Project</button>
              </div>
            </form>
            <?php if ($latestSubmission): ?>
              <?php $latestUrl = $latestSubmission['submission_url'] ?? ''; ?>
              <div class="current-submission">
                <div><strong><?=esc($latestUrl ?: $latestSubmission['original_filename'])?></strong><small>Submitted <?=esc($formatDate($latestSubmission['created_at']))?> · <?=esc($displayStatus)?></small></div>
                <?php if ($latestUrl): ?><a class="btn ghost" href="<?=esc($latestUrl)?>" target="_blank" rel="noopener">Open Link</a><?php else: ?><a class="btn ghost" href="view_submission.php?id=<?=esc($latestSubmission['id'])?>" target="_blank" rel="noopener">View PDF</a><?php endif; ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section class="portal-panel">
          <div class="panel-heading"><div><span class="eyebrow">PROJECT INFORMATION</span><h3>Registered Proposal</h3></div></div>
          <div class="detail-grid">
            <div><small>Project title</small><strong><?=esc($project['title'] ?? 'Not assigned')?></strong></div>
            <div><small>Group members</small><strong><?=esc($project['members'] ?? 'Not set')?></strong></div>
            <div><small>Adviser</small><strong><?=esc($project['adviser'] ?? 'Not assigned')?></strong></div>
            <div><small>Defense date</small><strong><?=esc($project['defense_date'] ?? 'Not set')?></strong></div>
          </div>
          <div class="project-resources">
            <small>PROJECT RESOURCES</small>
            <div>
              <?php if (!empty($project['google_drive_url'])): ?><a href="<?=esc($project['google_drive_url'])?>" target="_blank" rel="noopener">Google Drive</a><?php endif; ?>
              <?php if (!empty($project['github_url'])): ?><a href="<?=esc($project['github_url'])?>" target="_blank" rel="noopener">GitHub</a><?php endif; ?>
              <?php if (empty($project['google_drive_url']) && empty($project['github_url'])): ?><span>No project links submitted yet.</span><?php endif; ?>
            </div>
          </div>
        </section>

        <section class="portal-panel">
          <div class="panel-heading"><div><span class="eyebrow">SUBMISSION HISTORY</span><h3>Previous documents</h3></div></div>
          <?php if (!$submissions): ?>
            <div class="empty-state">No proposal submitted yet.</div>
          <?php else: ?>
            <div class="submission-history-list">
              <?php foreach ($submissions as $submission): ?>
                <?php $submissionUrl = $submission['submission_url'] ?? ''; ?>
                <div class="current-submission">
                  <div><strong><?=esc($submissionUrl ?: $submission['original_filename'])?></strong><small><?=esc($formatDate($submission['created_at']))?> · <?=esc(ucfirst(str_replace('_', ' ', $submission['status'])))?></small></div>
                  <?php if ($submissionUrl): ?><a class="btn ghost" href="<?=esc($submissionUrl)?>" target="_blank" rel="noopener">Open Link</a><?php else: ?><a class="btn ghost" href="view_submission.php?id=<?=esc($submission['id'])?>" target="_blank" rel="noopener">View PDF</a><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
</div>
</body>
</html>