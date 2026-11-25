<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/e3.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/student_auth.php';
$user = require_approved_student($pdo);
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE student_id = ? LIMIT 1');
$projectStmt->execute([(int) $user['id']]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$final = null;
$consolidated = null;
$proposalResult = null;
$finalSubmission = null;
if ($project) {
  $projectId = (int) $project['id'];
  $proposalSubmission = latest_stage_submission($pdo, $projectId, 'proposal');
  $finalSubmission = latest_stage_submission($pdo, $projectId, 'final');
  if ($proposalSubmission && !empty($project['proposal_confirmed_at']) && (int) ($project['proposal_verdict_submission_id'] ?? 0) === (int) $proposalSubmission['id'] && proposal_evaluation_schema_available($pdo)) {
    try {
      $panel = proposal_consolidated($pdo, $projectId, (int) $proposalSubmission['id']);
      if ($panel['calculated_score'] !== null) {
        $proposalResult = ['criteria' => proposal_criteria(), 'averages' => $panel['criteria'], 'total' => $panel['calculated_score'], 'percentage' => $panel['calculated_score'], 'recommendation' => $panel['recommendation'], 'evaluations' => []];
      }
    } catch (Throwable $exception) {
      error_log('[student E3 result] Published proposal result could not be loaded: ' . $exception->getMessage());
    }
  }
  $finalStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? AND published_at IS NOT NULL ORDER BY id DESC LIMIT 1');
  $finalStmt->execute([$projectId]);
  $final = $finalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($final && ($finalSubmission
    ? (int) ($final['final_submission_id'] ?? 0) !== (int) $finalSubmission['id']
    : !empty($final['final_submission_id']))) {
    $final = null;
  }
  if ($final) {
    $consolidated = e3_consolidated($pdo, $projectId, !empty($final['final_submission_id']) ? (int) $final['final_submission_id'] : null);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Published CEIT-UR-E3 Verdict</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261124">
</head>
<body>
<main class="main-content" style="margin-left:0;width:100%">
  <div class="evaluation-content">
    <a class="btn ghost evaluation-back" href="student_dashboard.php?view=overview">← Back to Student Dashboard</a>
    <?php if (!$final): ?>
      <section class="evaluation-card"><h1>E3 Verdict Pending</h1><p>No approved and published E3 verdict is available yet.</p></section>
    <?php else: ?>
      <?php $recommendedVerdict = e3_stage_verdict_from_score((float) $final['calculated_score']); ?>
      <header class="main-header"><div class="main-header-text"><h1>Capstone Evaluation Results</h1><p><?=esc($project['title'])?></p></div></header>
      <?php if ($finalSubmission): ?><section class="evaluation-card"><div class="evaluation-card-heading"><span class="evaluation-step">FINAL PAPER</span><h2>Submitted Final Paper</h2></div><p><strong>File:</strong> <?=esc($finalSubmission['original_filename'])?></p><p><strong>Submitted:</strong> <?=esc($finalSubmission['created_at'])?></p><p><strong>Status:</strong> <?=esc(stage_submission_status_label($finalSubmission))?></p><a class="btn ghost" href="view_submission.php?id=<?=esc($finalSubmission['id'])?>" target="_blank" rel="noopener">View submitted Final Paper PDF</a></section><?php endif; ?>
      <?php if ($proposalResult): ?>
        <section class="evaluation-card">
          <div class="evaluation-card-heading"><span class="evaluation-step">PROPOSAL</span><h2>Proposal Evaluation Results</h2></div>
          <p><strong>Total score:</strong> <?=number_format($proposalResult['total'], 2)?> / 100 · <?=number_format($proposalResult['percentage'], 2)?>%</p>
          <p><strong>Recommended verdict:</strong> <?=esc($proposalResult['recommendation']['label'])?></p>
          <p><strong>Official verdict:</strong> <?=!empty($project['proposal_confirmed_at']) ? esc($project['proposal_official_verdict']) : 'Pending Chairperson confirmation'?></p>
          <?php if (!empty($project['proposal_required_revisions'])): ?><p><strong>Required revisions:</strong><br><?=nl2br(esc($project['proposal_required_revisions']))?></p><?php endif; ?>
          <?php if (!empty($project['proposal_chairperson_comments'])): ?><p><strong>IT Chairperson comments:</strong><br><?=nl2br(esc($project['proposal_chairperson_comments']))?></p><?php endif; ?>
        </section>
      <?php endif; ?>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 1</span><h2>GRADING RUBRIC — 100 POINTS</h2></div>
        <table class="data-table"><thead><tr><th>Item</th><th>Criterion</th><th>Maximum</th><th>Final Consolidated Score</th></tr></thead><tbody>
        <?php foreach (e3_criteria() as $code => $criterion): ?>
          <tr><td><?=esc($code)?></td><td><?=esc($criterion['label'])?></td><td><?=esc($criterion['maximum'])?></td><td><?= $consolidated['criteria'][$code] === null ? '0.00' : number_format($consolidated['criteria'][$code], 2) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><strong>Total score:</strong> <?=number_format((float) $final['final_score'], 2)?> / 100</p>
        <p><strong>Percentage:</strong> <?=number_format((float) $final['final_score'], 2)?>%</p>
      </section>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 2</span><h2>FINAL SCORE AND VERDICT</h2></div>
        <p><strong>Final Calculated Score:</strong> <?=number_format((float) $final['final_score'], 2)?> / 100</p>
        <p><strong>Mamdani fuzzy output:</strong> <?=number_format((float) $recommendedVerdict['fuzzy']['defuzz'], 2)?> / 100</p>
        <p><strong>Recommended verdict:</strong> <?=esc($recommendedVerdict['label'])?></p>
        <p><strong>Official final-hearing verdict:</strong> <?=esc($final['verdict_label'])?></p>
        <p><strong>Chairperson confirmation:</strong> Confirmed <?=esc(date('M d, Y g:i A', strtotime($final['published_at'])))?></p>
        <p><strong>What the recommended verdict means:</strong> <?=esc($recommendedVerdict['description'])?></p>
        <?php if (!empty($final['has_departure'])): ?><p><strong>Authorized departure recorded:</strong> <?=esc($final['departure_authority'])?> — <?=esc($final['departure_reason'])?></p><?php endif; ?>
      </section>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 3</span><h2>REVISIONS, COMMENTS AND RECOMMENDATIONS</h2></div>
        <p><?=nl2br(esc(trim((string) ($final['revisions'] ?: $final['chairperson_comments'] ?: 'No revisions or recommendations.'))))?></p>
        <?php foreach (($consolidated['evaluations'] ?? []) as $evaluation): ?>
          <?php if (trim((string) ($evaluation['remarks'] ?? '')) !== ''): ?><p><strong>Evaluator feedback:</strong><br><?=nl2br(esc($evaluation['remarks']))?></p><?php endif; ?>
          <?php if (trim((string) ($evaluation['revisions'] ?? '')) !== ''): ?><p><strong>Evaluator revisions:</strong><br><?=nl2br(esc($evaluation['revisions']))?></p><?php endif; ?>
        <?php endforeach; ?>
      </section>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 4</span><h2>PANEL MEMBER'S SIGNATURE</h2></div>
        <p><strong><?=esc($final['signature_name'] ?: 'Authorized Chairperson')?></strong></p>
        <p><?=esc($final['signature_title'] ?: 'IT Chairperson')?></p>
        <p>Published: <?=esc($final['published_at'])?></p>
      </section>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
