<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/student_auth.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/e3.php';
require_once __DIR__ . '/../php/submissions.php';

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
$proposalUploadAllowed = !$proposalSubmission || empty($proposalSubmission['stored_filename'])
    || in_array($proposalSubmission['status'], ['revision_required', 'revision_requested'], true);
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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Project Submission - Capstone Evaluation System - Student Portal</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261125">
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
            <?php if ($proposalUploadAllowed): ?>
              <form class="submission-form" method="post" action="submit_proposal.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
                <input type="hidden" name="stage" value="proposal">
                <div class="submission-title-field"><label for="proposal-title">Project title</label><input id="proposal-title" name="project_title" type="text" value="<?=esc($project['title'])?>" required></div>
                <div class="proposal-upload"><label for="proposal-file">Proposal Paper PDF</label><div class="proposal-upload-surface"><span class="proposal-upload-icon" aria-hidden="true">↑</span><strong>Choose your proposal PDF</strong><span>PDF only · Maximum 10 MB</span><input id="proposal-file" name="proposal" type="file" accept="application/pdf" required></div></div>
                <fieldset class="proposal-resources">
                  <legend>Project resources</legend>
                  <div class="proposal-resource-row"><span class="proposal-resource-icon" aria-hidden="true">↗</span><div class="submission-resource-field"><label for="google-drive-url">Google Drive project link</label><input id="google-drive-url" name="google_drive_url" type="url" value="<?=esc($project['google_drive_url'] ?? '')?>" placeholder="https://drive.google.com/..."></div></div>
                  <div class="proposal-resource-row"><span class="proposal-resource-icon proposal-github-icon" aria-hidden="true">⌘</span><div class="submission-resource-field"><label for="github-url">GitHub project link</label><input id="github-url" name="github_url" type="url" value="<?=esc($project['github_url'] ?? '')?>" placeholder="https://github.com/..."></div></div>
                  <div class="proposal-resource-row"><span class="proposal-resource-icon" aria-hidden="true">↗</span><div class="submission-resource-field"><label for="other-project-url">Other project link</label><input id="other-project-url" name="other_project_url" type="url" maxlength="1000" value="<?=esc($project['other_project_url'] ?? '')?>" placeholder="https://example.com/..."></div></div>
                </fieldset>
                <div class="proposal-form-footer"><small>Upload a revised paper only after the Chairperson requests corrections.</small><button class="btn primary" type="submit"><?=$proposalSubmission ? 'Upload Revised Proposal' : 'Submit Proposal Paper'?></button></div>
              </form>
            <?php else: ?>
              <p class="submission-help">A submitted proposal cannot be replaced while it is under evaluation or awaiting the Chairperson’s official decision.</p>
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
          <section class="portal-panel proposal-student-result">
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
            <?php endif; ?>
          </section>

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
                    <div><strong><?=esc($submission['original_filename'])?></strong><small><?=esc(ucfirst($submission['stage'] === 'final' ? 'Final Paper' : 'Proposal Paper'))?> · <?=esc($formatDate($submission['created_at']))?> · <?=esc(stage_submission_status_label($submission))?></small></div>
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
</body>
</html>
