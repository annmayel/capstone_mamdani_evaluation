<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/student_auth.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/e3.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/proposal_revisions.php';

$user = require_approved_student($pdo);
$studentId = (int) $user['id'];
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE student_id = ? LIMIT 1');
$projectStmt->execute([$studentId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$submissions = [];
$proposalSubmission = null;
$finalSubmission = null;
$tableReady = true;
$proposalEvaluations = [];
$finalEvaluations = [];
$finalVerdict = null;
$finalConsolidated = null;
$proposalPanelTotal = null;
$proposalPercentage = null;
$proposalRecommendation = null;
$proposalPanel = null;
$proposalEvaluatorNames = [];
$revisionCycle = null;
$revisionSummary = null;
$majorRevisionRequired = false;
$revisionUploadState = 'not_required';
$revisionSchemaReady = false;
$revisionNumbersBySubmission = [];
$proposalCriteria = proposal_criteria();
$formatDate = static fn($date) => $date ? date('M d, Y g:i A', strtotime($date)) : 'Not set';
$storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR;

if ($project) {
    try {
        $submissionsStmt = $pdo->prepare('SELECT * FROM student_submissions WHERE student_id = ? ORDER BY id DESC');
        $submissionsStmt->execute([$studentId]);
        $submissions = $submissionsStmt->fetchAll(PDO::FETCH_ASSOC);
        $proposalSubmission = latest_stage_submission($pdo, (int) $project['id'], 'proposal');
        $finalSubmission = latest_stage_submission($pdo, (int) $project['id'], 'final');
    } catch (PDOException $exception) {
        $tableReady = false;
        error_log('[student submissions] Stage submission records are unavailable: ' . $exception->getMessage());
    }

    if ($proposalSubmission && proposal_evaluation_schema_available($pdo)) {
        try {
            $proposalPanel = proposal_panel_completion($pdo, (int) $project['id'], (int) $proposalSubmission['id']);
            if ($proposalPanel['ready']) {
                $consolidated = $proposalPanel['consolidated'];
                $proposalEvaluations = $consolidated['evaluations'];
                $proposalPanelTotal = $consolidated['calculated_score'];
                $proposalPercentage = $consolidated['calculated_score'];
                $proposalRecommendation = $consolidated['recommendation'];
                $proposalEvaluatorNames = proposal_evaluator_names($pdo, $proposalEvaluations);
            }
        } catch (Throwable $exception) {
            error_log('[student proposal] Panel proposal result could not be loaded: ' . $exception->getMessage());
        }
    }
    $revisionSchemaReady = proposal_revision_schema_available($pdo);
    if ($revisionSchemaReady) {
        $revisionCycle = proposal_revision_cycle($pdo, (int) $project['id']);
        if ($revisionCycle) $revisionSummary = proposal_revision_review_summary($pdo, (int) $revisionCycle['id']);
        $revisionHistoryStmt = $pdo->prepare('SELECT revision_submission_id, revision_number FROM proposal_revision_cycles WHERE project_id=?');
        $revisionHistoryStmt->execute([(int) $project['id']]);
        foreach ($revisionHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $revisionRow) $revisionNumbersBySubmission[(int) $revisionRow['revision_submission_id']] = (int) $revisionRow['revision_number'];
    }
    if ($revisionCycle && $proposalRecommendation === null && proposal_evaluation_schema_available($pdo)) {
        $originalPanel = proposal_panel_completion($pdo, (int) $project['id'], (int) $revisionCycle['original_submission_id']);
        if ($originalPanel['ready']) {
            $proposalPanel = $originalPanel;
            $consolidated = $originalPanel['consolidated'];
            $proposalEvaluations = $consolidated['evaluations'];
            $proposalPanelTotal = $consolidated['calculated_score'];
            $proposalPercentage = $consolidated['calculated_score'];
            $proposalRecommendation = $consolidated['recommendation'];
            $proposalEvaluatorNames = proposal_evaluator_names($pdo, $proposalEvaluations);
        }
    }
    $originalMajorRevision = (bool) ($proposalRecommendation && proposal_is_major_revision($proposalRecommendation));
    $majorRevisionRequired = $originalMajorRevision
        || ($revisionCycle && in_array($revisionCycle['status'], ['pending_panel_review', 'returned'], true));
    $revisionUploadState = proposal_revision_upload_state(
        $originalMajorRevision,
        $revisionSchemaReady,
        $revisionCycle,
        $revisionSummary
    );

    $finalVerdictStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? AND published_at IS NOT NULL ORDER BY id DESC LIMIT 1');
    $finalVerdictStmt->execute([(int) $project['id']]);
    $candidateFinalVerdict = $finalVerdictStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($candidateFinalVerdict && ($finalSubmission ? !empty($candidateFinalVerdict['final_submission_id']) : empty($candidateFinalVerdict['final_submission_id']))) {
        $finalVerdict = $candidateFinalVerdict;
    }
    $finalResultSubmissionId = $finalVerdict ? (int) ($finalVerdict['final_submission_id'] ?? 0) : (int) ($finalSubmission['id'] ?? 0);
    if ($finalVerdict && $finalResultSubmissionId > 0) {
        $finalEvalStmt = $pdo->prepare('SELECT * FROM e3_evaluations WHERE project_id = ? AND final_submission_id = ? AND status = ? ORDER BY id');
        $finalEvalStmt->execute([(int) $project['id'], $finalResultSubmissionId, 'submitted']);
        $finalEvaluations = $finalEvalStmt->fetchAll(PDO::FETCH_ASSOC);
        $finalConsolidated = e3_consolidated($pdo, (int) $project['id'], $finalResultSubmissionId);
    } elseif ($finalVerdict) {
        $finalConsolidated = e3_consolidated($pdo, (int) $project['id']);
        $finalEvaluations = $finalConsolidated['evaluations'];
    }
}

$latestProposalStatus = stage_submission_status_label($proposalSubmission);
$latestFinalStatus = stage_submission_status_label($finalSubmission);
$finalUnlocked = $project && proposal_allows_final_submission($project, $proposalSubmission);
$proposalUploadAllowed = $majorRevisionRequired
    ? $revisionUploadState === 'allowed'
    : (!$proposalSubmission || empty($proposalSubmission['stored_filename'])
        || in_array($proposalSubmission['status'], ['revision_required', 'revision_requested'], true));
$finalUploadAllowed = $finalUnlocked && (!$finalSubmission || in_array($finalSubmission['status'], ['revision_required', 'revision_requested'], true));
$displayName = $user['full_name'] ?? 'Student';
$initial = strtoupper(substr($displayName, 0, 1));
$uploadMessages = [
    'proposal' => 'Proposal Paper submitted successfully.',
    'final' => 'Final Paper submitted successfully.',
    'invalid_pdf' => 'Only valid PDF files up to 10 MB are accepted.',
    'storage_failed' => 'The paper could not be stored. Please contact the administrator.',
    'save_failed' => 'The paper could not be recorded. Please contact the administrator.',
    'no_project' => 'A project record is required before submitting a paper.',
    'invalid_request' => 'The submission request could not be verified.',
    'final_locked' => 'The Final Paper stays locked until the IT Chairperson approves the proposal and accepts any required revisions.',
    'submission_locked' => 'This paper cannot be replaced while it is under evaluation or awaiting an official decision.',
    'revision_schema_required' => 'Proposal revision setup is not installed yet. Ask an administrator to apply migration 023 before submitting a revision.',
    'revision_pending' => 'Your revised proposal is still being reviewed by the original panel. Another revision cannot be submitted yet.',
    'revision_accepted' => 'This proposal revision was accepted. Continue with the Final Paper stage when it becomes available.',
    'missing_submission' => 'Choose a PDF paper to submit.',
    'missing_project_title' => 'Enter the project title so the Chairperson can identify the project.',
    'invalid_drive_link' => 'Use a valid Google Drive link.',
    'invalid_github_link' => 'Use a valid GitHub link.',
    'invalid_other_link' => 'Use a valid HTTP or HTTPS project link.',
    'invalid_link' => 'Use a valid Google Drive or GitHub link.',
];
$messageKey = isset($_GET['uploaded']) ? (string) $_GET['uploaded'] : (string) ($_GET['error'] ?? '');
$mainStatus = !$project ? 'Project Not Registered' : ($proposalSubmission ? $latestProposalStatus : 'Not Submitted');
$mainStatusClass = in_array($mainStatus, ['Approved', 'Accepted'], true) ? 'success' : (in_array($mainStatus, ['Revision Required', 'Under Evaluation'], true) || !$finalUnlocked ? 'warning' : 'info');
$panelStatusLabel = $proposalPercentage === null
    ? 'Pending Panel Evaluation'
    : 'Completed';
$revisionProgressStep = match ($revisionUploadState) {
    'pending' => 3,
    'accepted', 'returned' => 4,
    'allowed' => $revisionCycle ? 2 : 1,
    default => 1,
};
$revisionProgressLabel = match ($revisionUploadState) {
    'pending' => 'Pending Panel Re-evaluation',
    'accepted' => 'Revision Accepted',
    'returned' => 'Revision Returned',
    'allowed' => $revisionCycle ? 'Submit Revised Proposal' : 'Major Revision Required',
    'schema_required' => 'Revision Setup Required',
    default => 'Major Revision Required',
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Project Submission - Capstone Evaluation System - Student Portal</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261126">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="student-portal">
<div class="student-shell">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <main class="student-main">
    <header class="student-topbar">
      <div><span class="eyebrow">CAPSTONE EVALUATION SYSTEM</span><strong>Student Portal</strong></div>
      <div class="student-account"><span class="student-avatar"><?=esc($initial)?></span><span><strong><?=esc($displayName)?></strong><small>Student</small></span></div>
    </header>
    <div class="student-content">
      <div class="proposal-page">
        <div class="student-page-heading">
          <div><h1>Project Submission</h1><p>Submit each paper on your registered capstone project and track its official review.</p></div>
          <span class="status-pill <?=$mainStatusClass?>">● <?=esc($mainStatus)?></span>
        </div>

        <?php if ($messageKey && isset($uploadMessages[$messageKey])): ?>
          <div class="student-upload-message <?=isset($_GET['uploaded']) ? 'success' : 'error'?>" role="status"><?=esc($uploadMessages[$messageKey])?></div>
        <?php endif; ?>

        <?php if ($project && $proposalPercentage !== null): ?>
          <section class="proposal-result-dashboard" aria-labelledby="proposal-results-heading">
            <div class="proposal-result-heading">
              <div><span class="eyebrow">PROPOSAL EVALUATION RESULTS</span><h2 id="proposal-results-heading">Proposal Evaluation Results</h2><p>Panel evaluation and revision review for your capstone proposal.</p></div>
              <span class="proposal-result-score"><?=number_format((float) $proposalPanelTotal, 2)?> <small>/ 100</small></span>
            </div>
            <div class="proposal-result-metrics">
              <div><small>Consolidated Score</small><strong><?=number_format((float) $proposalPanelTotal, 2)?> / 100</strong></div>
              <div class="is-blue"><small>Fuzzy Linguistic Rating</small><strong><?=esc($proposalRecommendation['fuzzy']['rating'])?></strong></div>
              <div><small>Defuzzified Output</small><strong><?=number_format((float) $proposalRecommendation['fuzzy']['defuzz'], 2)?> / 100</strong></div>
              <div class="is-green"><small>Panel Recommendation</small><strong><?=esc($proposalRecommendation['label'])?></strong></div>
              <div><small>Panel Evaluation Status</small><strong><?=esc($panelStatusLabel)?></strong><span><?=esc((string) ($proposalPanel['submitted_count'] ?? 0))?> of <?=esc((string) ($proposalPanel['assigned_count'] ?? 0))?> evaluators submitted</span></div>
            </div>

            <?php if ($majorRevisionRequired || $revisionCycle): ?>
              <div class="major-revision-notice" role="status"><span aria-hidden="true"><?= $revisionUploadState === 'accepted' ? '✓' : '!' ?></span><div><h3><?= $revisionUploadState === 'accepted' ? 'Revision Accepted' : 'Major Revision Required' ?></h3><p><?= $revisionUploadState === 'accepted' ? 'The original panel accepted your revised proposal. Final Paper submission remains locked until the IT Chairperson formally accepts the revision and opens the next stage.' : 'Address the original panel\'s comments, recommendations, and required revisions. Your revised proposal will return to the same panel for re-evaluation.' ?></p></div></div>
              <ol class="revision-progress" aria-label="Revision workflow progress">
                <?php $steps = [1 => ['Major Revision Required', 'Review panel feedback'], 2 => ['Submit Revised Proposal', 'Upload your revised document'], 3 => ['Pending Panel Re-evaluation', 'Original panel is reviewing'], 4 => ['Revision Accepted or Returned', 'Panel decision recorded']]; ?>
                <?php foreach ($steps as $number => [$title, $detail]): ?><li class="<?=$number < $revisionProgressStep ? 'is-complete' : ($number === $revisionProgressStep ? 'is-current' : '')?>"><span><?= $number < $revisionProgressStep ? '✓' : $number ?></span><div><strong><?=esc($title)?></strong><small><?=esc($detail)?></small></div></li><?php endforeach; ?>
              </ol>
              <p class="revision-current-state"><strong>Current status:</strong> <?=esc($revisionProgressLabel)?><?php if ($revisionSummary): ?> · <?=esc((string) $revisionSummary['accepted_count'])?> accepted, <?=esc((string) $revisionSummary['pending_count'])?> pending, <?=esc((string) $revisionSummary['returned_count'])?> returned<?php endif; ?></p>

              <div class="revision-workspace">
                <section class="revision-upload-card" aria-labelledby="revision-upload-heading">
                  <div class="revision-card-heading"><span aria-hidden="true">⇧</span><div><h3 id="revision-upload-heading">Submit Revised Proposal</h3><p>Upload a PDF that addresses the panel comments and required revisions.</p></div></div>
                  <?php if ($proposalUploadAllowed): ?>
                    <form class="revision-submission-form" method="post" action="submit_proposal.php" enctype="multipart/form-data">
                      <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
                      <input type="hidden" name="stage" value="proposal">
                      <input type="hidden" name="project_title" value="<?=esc($project['title'])?>">
                      <label class="revision-file-dropzone" for="revision-file" data-dropzone>
                        <span class="revision-file-icon" aria-hidden="true">⇧</span><strong>Drag and drop your revised proposal here</strong><small>or choose a PDF from your device</small>
                        <input id="revision-file" name="proposal" type="file" accept="application/pdf" required data-revision-file>
                        <em data-revision-file-name>No file selected</em><i>PDF only · Maximum 10 MB</i>
                      </label>
                      <div class="revision-resource-fields"><strong>Updated Project Resource Links</strong><p>Update the links if revised files or source materials changed.</p>
                        <label for="revision-drive">Google Drive</label><input id="revision-drive" name="google_drive_url" type="url" value="<?=esc($project['google_drive_url'] ?? '')?>" placeholder="https://drive.google.com/...">
                        <label for="revision-github">GitHub</label><input id="revision-github" name="github_url" type="url" value="<?=esc($project['github_url'] ?? '')?>" placeholder="https://github.com/...">
                        <label for="revision-other">Other resource link</label><input id="revision-other" name="other_project_url" type="url" maxlength="1000" value="<?=esc($project['other_project_url'] ?? '')?>" placeholder="https://example.com/...">
                      </div>
                      <button class="btn primary revision-submit" type="submit">Submit Revised Proposal</button>
                    </form>
                  <?php else: ?>
                    <div class="revision-upload-state"><strong><?=esc($revisionProgressLabel)?></strong><p><?=esc(match ($revisionUploadState) {
                      'pending' => 'The original assigned panel is reviewing the current revision. A new upload becomes available only after the panel completes its decision.',
                      'accepted' => 'The panel accepted the revision. No further proposal upload is needed; proceed to the Final Paper stage when it is unlocked.',
                      'schema_required' => 'Revision setup is pending. An administrator must apply migration 023 before a revised proposal can be recorded.',
                      default => 'A revised proposal cannot be submitted at this time.',
                    })?></p></div>
                  <?php endif; ?>
                </section>
                <aside class="revision-information-card"><span aria-hidden="true">i</span><div><h3>Important</h3><p>Your revised proposal will be sent to your original assigned panel evaluators for re-evaluation. You will be notified once all evaluators have submitted their review.</p></div></aside>
              </div>
            <?php endif; ?>

            <details class="panel-feedback-disclosure" <?= $majorRevisionRequired ? 'open' : '' ?>><summary><span aria-hidden="true">☷</span> View Panel Comments &amp; Required Revisions</summary><div class="panel-feedback-list">
              <?php foreach ($proposalEvaluations as $evaluation): $evaluatorName = $proposalEvaluatorNames[(int) $evaluation['evaluator_id']] ?? 'Panel evaluator'; ?>
                <article class="panel-feedback-card"><header><div><span class="panel-avatar" aria-hidden="true">●</span><strong><?=esc($evaluatorName)?></strong><small>Panel evaluator</small></div><b><?=number_format((float) $evaluation['total_score'], 2)?> / 100</b></header>
                  <?php if (trim((string) ($evaluation['overall_comments'] ?? '')) !== ''): ?><p><strong>Comments:</strong> <?=nl2br(esc($evaluation['overall_comments']))?></p><?php endif; ?>
                  <?php if (trim((string) ($evaluation['recommendations'] ?? '')) !== ''): ?><p><strong>Recommendations:</strong> <?=nl2br(esc($evaluation['recommendations']))?></p><?php endif; ?>
                  <?php if (trim((string) ($evaluation['required_revisions'] ?? '')) !== ''): ?><p class="required-revisions"><strong>Required revisions:</strong> <?=nl2br(esc($evaluation['required_revisions']))?></p><?php endif; ?>
                  <?php foreach ($proposalCriteria as $code => $criterion): $comment = trim((string) ($evaluation[$criterion['comment_key']] ?? '')); ?><?php if ($comment !== ''): ?><p class="criterion-comment"><strong><?=esc($code)?> · <?=esc($criterion['label'])?>:</strong> <?=nl2br(esc($comment))?></p><?php endif; ?><?php endforeach; ?>
                </article>
              <?php endforeach; ?>
            </div></details>
          </section>
        <?php endif; ?>

        <section class="portal-panel">
          <div class="proposal-context"><div><span class="eyebrow">REGISTERED PROJECT</span><strong><?=esc($project['title'] ?? 'No project registered yet')?></strong></div><span class="proposal-context-mark" aria-hidden="true">01</span></div>
          <?php if (!$project): ?>
            <div class="empty-state"><strong>Your papers will be available after your project is registered.</strong><br>The Chairperson or adviser must first link a capstone project to this student account.</div>
          <?php elseif (!$tableReady): ?>
            <div class="empty-state">Submission storage is not configured. Run the existing submission migrations and <code>sql/migrations/019_stage_submission_workflow.sql</code>.</div>
          <?php else: ?>
            <div class="panel-heading">
              <div><span class="eyebrow">STAGE 1</span><h3>Proposal Paper Submission</h3></div>
              <span class="status-pill <?=in_array($latestProposalStatus, ['Approved', 'Accepted'], true) ? 'success' : (in_array($latestProposalStatus, ['Revision Required', 'Under Evaluation'], true) ? 'warning' : ($proposalSubmission ? 'info' : 'muted'))?>">● <?=esc($latestProposalStatus)?></span>
            </div>
            <?php if ($proposalSubmission): ?>
              <div class="current-submission">
                <div><strong><?=esc($proposalSubmission['original_filename'])?></strong><small>Proposal Paper · <?=esc($formatDate($proposalSubmission['created_at']))?> · <?=esc($latestProposalStatus)?></small></div>
                <?php if (!empty($proposalSubmission['stored_filename'])): ?><a class="btn ghost" href="view_submission.php?id=<?=esc($proposalSubmission['id'])?>" target="_blank" rel="noopener">View PDF</a><?php elseif (!empty($proposalSubmission['submission_url'])): ?><a class="btn ghost" href="<?=esc($proposalSubmission['submission_url'])?>" target="_blank" rel="noopener">Open Link</a><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($proposalUploadAllowed && !$majorRevisionRequired): ?>
              <form class="submission-form" method="post" action="submit_proposal.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
                <input type="hidden" name="stage" value="proposal">
                <div class="submission-title-field"><label for="proposal-title">Project title</label><input id="proposal-title" name="project_title" type="text" value="<?=esc($project['title'])?>" required></div>
                <div class="proposal-upload"><label for="proposal-file"><?=$majorRevisionRequired ? 'Revised Proposal PDF' : 'Proposal Paper PDF'?></label><div class="proposal-upload-surface"><span class="proposal-upload-icon" aria-hidden="true">↑</span><strong><?=$majorRevisionRequired ? 'Choose your revised proposal PDF' : 'Choose your proposal PDF'?></strong><span>PDF only · Maximum 10 MB</span><input id="proposal-file" name="proposal" type="file" accept="application/pdf" required></div></div>
                <fieldset class="proposal-resources">
                  <legend>Project resources</legend>
                  <div class="proposal-resource-row"><span class="proposal-resource-icon" aria-hidden="true">↗</span><div class="submission-resource-field"><label for="google-drive-url">Google Drive project link</label><input id="google-drive-url" name="google_drive_url" type="url" value="<?=esc($project['google_drive_url'] ?? '')?>" placeholder="https://drive.google.com/..."></div></div>
                  <div class="proposal-resource-row"><span class="proposal-resource-icon proposal-github-icon" aria-hidden="true">⌘</span><div class="submission-resource-field"><label for="github-url">GitHub project link</label><input id="github-url" name="github_url" type="url" value="<?=esc($project['github_url'] ?? '')?>" placeholder="https://github.com/..."></div></div>
                  <div class="proposal-resource-row"><span class="proposal-resource-icon" aria-hidden="true">↗</span><div class="submission-resource-field"><label for="other-project-url">Other project link</label><input id="other-project-url" name="other_project_url" type="url" maxlength="1000" value="<?=esc($project['other_project_url'] ?? '')?>" placeholder="https://example.com/..."></div></div>
                </fieldset>
                <div class="proposal-form-footer"><small><?=$majorRevisionRequired ? 'This revision will be sent to the original assigned panel evaluators for review.' : 'Upload a revised paper only after corrections are requested.'?></small><button class="btn primary" type="submit"><?=$proposalSubmission ? 'Upload Revised Proposal' : 'Submit Proposal Paper'?></button></div>
              </form>
            <?php elseif (!$majorRevisionRequired): ?>
              <p class="submission-help"><?php
                echo esc(match ($revisionUploadState) {
                    'schema_required' => 'Proposal revision setup is pending. An administrator must apply migration 023 before you can submit the required revision.',
                    'pending' => 'Your revised proposal is currently under panel re-evaluation. You can submit another version after the original panel completes this review.',
                    'accepted' => 'Your proposal revision has been accepted. Further proposal revisions are locked; continue with the Final Paper stage when it is available.',
                    default => 'A submitted proposal cannot be replaced while it is under evaluation or awaiting the Chairperson’s official decision.',
                });
              ?></p>
            <?php endif; ?>
            <?php if (!empty($project['proposal_required_revisions']) && !empty($project['proposal_confirmed_at'])): ?>
              <div class="proposal-student-revisions"><strong>Required Proposal Revisions</strong><p><?=nl2br(esc($project['proposal_required_revisions']))?></p></div>
            <?php endif; ?>

        </section>
        <section class="portal-panel">
            <div class="panel-heading" style="margin-top:24px">
              <div><span class="eyebrow">STAGE 2</span><h3>Final Paper Submission</h3></div>
              <span class="status-pill <?=$finalUnlocked ? (in_array($latestFinalStatus, ['Revision Required'], true) ? 'warning' : 'success') : 'muted'?>">● <?=$finalUnlocked ? esc($latestFinalStatus) : 'Locked'?></span>
            </div>
            <?php if ($finalSubmission): ?>
              <div class="current-submission">
                <div><strong><?=esc($finalSubmission['original_filename'])?></strong><small>Final Paper · <?=esc($formatDate($finalSubmission['created_at']))?> · <?=esc($latestFinalStatus)?></small></div>
                <?php if (!empty($finalSubmission['stored_filename'])): ?><a class="btn ghost" href="view_submission.php?id=<?=esc($finalSubmission['id'])?>" target="_blank" rel="noopener">View PDF</a><?php elseif (!empty($finalSubmission['submission_url'])): ?><a class="btn ghost" href="<?=esc($finalSubmission['submission_url'])?>" target="_blank" rel="noopener">Open Link</a><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if ($finalUploadAllowed): ?>
              <form class="submission-form" method="post" action="submit_proposal.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
                <input type="hidden" name="stage" value="final">
                <div class="proposal-upload"><label for="final-file">Final Paper PDF</label><div class="proposal-upload-surface"><span class="proposal-upload-icon" aria-hidden="true">↑</span><strong>Choose your final paper PDF</strong><span>PDF only · Maximum 10 MB</span><input id="final-file" name="proposal" type="file" accept="application/pdf" required></div></div>
                <div class="proposal-form-footer"><small><?=$finalSubmission ? 'Upload a revised final paper after the Chairperson requests corrections.' : 'The final paper uses this same registered project.'?></small><button class="btn primary" type="submit"><?=$finalSubmission ? 'Upload Revised Final Paper' : 'Submit Final Paper'?></button></div>
              </form>
            <?php elseif (!$finalUnlocked): ?>
              <div class="empty-state">Available after the Proposal is officially approved and any required revisions are accepted by the IT Chairperson.</div>
            <?php else: ?>
              <p class="submission-help">A submitted final paper cannot be replaced while it is under evaluation or awaiting the Chairperson’s official decision.</p>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <?php if ($project && $tableReady): ?>
          <?php if ($proposalPercentage === null): ?><section class="portal-panel proposal-student-result">
            <div class="panel-heading"><div><span class="eyebrow">PROPOSAL EVALUATION RESULTS</span><h3>Proposal Evaluation Results</h3></div><strong class="panel-score"><?=$proposalPercentage === null ? 'Pending' : number_format($proposalPercentage, 2) . '%'?></strong></div>
            <?php if ($proposalPercentage === null): ?>
              <div class="empty-state">Pending Panel Evaluation<?php if ($proposalPanel): ?> — <?=esc((string) $proposalPanel['submitted_count'])?> of <?=esc((string) $proposalPanel['assigned_count'])?> assigned evaluators have submitted complete evaluations.<?php else: ?>.<?php endif; ?></div>
            <?php else: ?>
              <div class="detail-grid">
                <div><small>Consolidated score</small><strong><?=number_format($proposalPanelTotal, 2)?> / 100</strong></div>
                <div><small>Fuzzy linguistic rating</small><strong><?=esc($proposalRecommendation['fuzzy']['rating'])?></strong></div>
                <div><small>Defuzzified output</small><strong><?=number_format((float) $proposalRecommendation['fuzzy']['defuzz'], 2)?> / 100</strong></div>
                <div><small>Panel recommendation</small><strong><?=esc($proposalRecommendation['label'])?></strong></div>
              </div>
              <div class="proposal-student-revisions"><strong>Panel evaluation complete</strong><p>All assigned evaluators submitted complete CEIT-T07A evaluation sheets.</p></div>
              <?php foreach ($proposalEvaluations as $evaluation): ?>
                <?php $evaluatorName = $proposalEvaluatorNames[(int) $evaluation['evaluator_id']] ?? 'Panel evaluator'; ?>
                <div class="proposal-student-revisions">
                  <strong><?=esc($evaluatorName)?></strong><p>Panel score: <?=number_format((float) $evaluation['total_score'], 2)?> / 100</p>
                  <?php foreach ($proposalCriteria as $code => $criterion): $comment = trim((string) ($evaluation[$criterion['comment_key']] ?? '')); ?>
                    <?php if ($comment !== ''): ?><p><strong><?=esc($code)?> · <?=esc($criterion['label'])?>:</strong><br><?=nl2br(esc($comment))?></p><?php endif; ?>
                  <?php endforeach; ?>
                  <?php if (trim((string) ($evaluation['overall_comments'] ?? '')) !== ''): ?><p><strong>Overall comments:</strong><br><?=nl2br(esc($evaluation['overall_comments']))?></p><?php endif; ?>
                  <?php if (trim((string) ($evaluation['recommendations'] ?? '')) !== ''): ?><p><strong>Recommendations:</strong><br><?=nl2br(esc($evaluation['recommendations']))?></p><?php endif; ?>
                  <?php if (trim((string) ($evaluation['required_revisions'] ?? '')) !== ''): ?><p><strong>Required revisions / suggestions:</strong><br><?=nl2br(esc($evaluation['required_revisions']))?></p><?php endif; ?>
                </div>
              <?php endforeach; ?>
              <?php if (!empty($project['proposal_confirmed_at']) && !empty($project['proposal_official_verdict'])): ?><div class="proposal-student-revisions"><strong>IT Chairperson record: <?=esc($project['proposal_official_verdict'])?></strong><p>Confirmed <?=esc($formatDate($project['proposal_confirmed_at']))?></p></div><?php endif; ?>
              <?php if (!empty($project['proposal_chairperson_comments'])): ?><div class="proposal-student-revisions"><strong>IT Chairperson comments</strong><p><?=nl2br(esc($project['proposal_chairperson_comments']))?></p></div><?php endif; ?>
              <?php if (!empty($project['proposal_required_revisions'])): ?><div class="proposal-student-revisions"><strong>Authorized revisions</strong><p><?=nl2br(esc($project['proposal_required_revisions']))?></p></div><?php endif; ?>
              <?php if ($majorRevisionRequired): ?><div class="proposal-student-revisions"><strong>Major Revision Workflow</strong><p>1. Major Revision Required · 2. <?=$revisionCycle ? 'Revised Proposal Submitted' : 'Submit Revised Proposal'?> · 3. <?=$revisionCycle && $revisionCycle['status'] === 'pending_panel_review' ? 'Pending Panel Re-evaluation' : 'Panel Re-evaluation'?> · 4. <?=$revisionCycle ? esc(ucwords(str_replace('_', ' ', $revisionCycle['status']))) : 'Revision Accepted or Returned'?></p><?php if ($revisionSummary): ?><p><?=$revisionSummary['accepted_count']?> accepted · <?=$revisionSummary['pending_count']?> pending · <?=$revisionSummary['returned_count']?> returned</p><?php foreach ($revisionSummary['reviews'] as $review): if (($review['decision'] ?? 'pending') !== 'pending'): ?><p><strong><?=esc($review['full_name'])?>:</strong> <?=esc(ucfirst($review['decision']))?><?php if (!empty($review['comments'])): ?><br><?=nl2br(esc($review['comments']))?><?php endif; ?></p><?php endif; endforeach; ?><?php endif; ?></div><?php endif; ?>
            <?php endif; ?>
          </section><?php endif; ?>

          <section class="portal-panel proposal-student-result">
            <div class="panel-heading"><div><span class="eyebrow">FINAL HEARING EVALUATION RESULTS</span><h3>Final Hearing Evaluation Results</h3></div><strong class="panel-score"><?=$finalConsolidated && $finalConsolidated['calculated_score'] !== null ? number_format((float) $finalConsolidated['calculated_score'], 2) . '%' : 'Pending'?></strong></div>
            <?php if (!$finalConsolidated || $finalConsolidated['calculated_score'] === null): ?>
              <div class="empty-state">Final hearing results will appear after a Final Paper is submitted and evaluator scoring is complete.</div>
            <?php else: ?>
              <?php $finalRecommendation = e3_stage_verdict_from_score((float) $finalConsolidated['calculated_score']); ?>
              <div class="detail-grid">
                <div><small>Total score</small><strong><?=number_format((float) $finalConsolidated['calculated_score'], 2)?> / 100</strong></div>
                <div><small>Percentage</small><strong><?=number_format((float) $finalConsolidated['calculated_score'], 2)?>%</strong></div>
                <div><small>Recommended verdict</small><strong><?=esc($finalRecommendation['label'])?></strong></div>
                <div><small>Chairperson confirmation</small><strong><?=$finalVerdict ? 'Confirmed' : 'Pending'?></strong></div>
              </div>
              <?php if ($finalVerdict): ?><div class="proposal-student-revisions"><strong>Official verdict: <?=esc($finalVerdict['verdict_label'])?></strong><p>Confirmed <?=esc($formatDate($finalVerdict['published_at']))?></p></div><?php else: ?><div class="proposal-student-revisions"><strong>Official verdict pending Chairperson confirmation</strong></div><?php endif; ?>
              <?php if ($finalVerdict && !empty($finalVerdict['revisions'])): ?><div class="proposal-student-revisions"><strong>IT Chairperson required revisions</strong><p><?=nl2br(esc($finalVerdict['revisions']))?></p></div><?php endif; ?>
              <?php if ($finalVerdict && !empty($finalVerdict['chairperson_comments'])): ?><div class="proposal-student-revisions"><strong>IT Chairperson comments</strong><p><?=nl2br(esc($finalVerdict['chairperson_comments']))?></p></div><?php endif; ?>
            <?php endif; ?>
          </section>

          <section class="portal-panel">
            <div class="panel-heading"><div><span class="eyebrow">SUBMISSION HISTORY</span><h3>Previous documents</h3></div></div>
            <?php if (!$submissions): ?><div class="empty-state">No papers submitted yet.</div><?php else: ?>
              <div class="submission-history-list">
                <?php foreach ($submissions as $submission): ?>
                  <div class="current-submission">
                    <?php $submissionLabel = $submission['stage'] === 'final' ? 'Final Paper' : (isset($revisionNumbersBySubmission[(int) $submission['id']]) ? 'Revised Proposal (Rev. ' . $revisionNumbersBySubmission[(int) $submission['id']] . ')' : 'Original Proposal'); ?>
                    <div><strong><?=esc($submission['original_filename'])?></strong><small><?=esc($submissionLabel)?> · <?=esc($formatDate($submission['created_at']))?> · <?=esc(stage_submission_status_label($submission))?></small></div>
                    <?php if (!empty($submission['stored_filename'])): ?><a class="btn ghost" href="view_submission.php?id=<?=esc($submission['id'])?>" target="_blank" rel="noopener">View PDF</a><?php elseif (!empty($submission['submission_url'])): ?><a class="btn ghost" href="<?=esc($submission['submission_url'])?>" target="_blank" rel="noopener">Open Link</a><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<script>
(() => {
  const file = document.querySelector('[data-revision-file]');
  const dropzone = document.querySelector('[data-dropzone]');
  const fileName = document.querySelector('[data-revision-file-name]');
  if (!file || !dropzone || !fileName) return;
  const updateName = () => { fileName.textContent = file.files.length ? file.files[0].name : 'No file selected'; };
  file.addEventListener('change', updateName);
  ['dragenter', 'dragover'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); }));
  ['dragleave', 'drop'].forEach((eventName) => dropzone.addEventListener(eventName, (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); }));
  dropzone.addEventListener('drop', (event) => {
    if (!event.dataTransfer.files.length) return;
    file.files = event.dataTransfer.files;
    updateName();
  });
})();
</script>
</body>
</html>
