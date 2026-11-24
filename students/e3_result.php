<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/e3.php';
require_once __DIR__ . '/../php/student_auth.php';
$user = require_verified_student($pdo);
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE student_id = ? LIMIT 1');
$projectStmt->execute([(int) $user['id']]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$final = null;
$consolidated = null;
if ($project) {
  $projectId = (int) $project['id'];
  $finalStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? AND published_at IS NOT NULL LIMIT 1');
  $finalStmt->execute([$projectId]);
  $final = $finalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($final) {
    $consolidated = e3_consolidated($pdo, $projectId);
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
      <?php $verdict = e3_verdict((float) $final['final_score']); ?>
      <header class="main-header"><div class="main-header-text"><h1>CEIT-UR-E3 Final Hearing Evaluation</h1><p><?=esc($project['title'])?></p></div></header>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 1</span><h2>GRADING RUBRIC — 100 POINTS</h2></div>
        <table class="data-table"><thead><tr><th>Item</th><th>Criterion</th><th>Maximum</th><th>Final Consolidated Score</th></tr></thead><tbody>
        <?php foreach (e3_criteria() as $code => $criterion): ?>
          <tr><td><?=esc($code)?></td><td><?=esc($criterion['label'])?></td><td><?=esc($criterion['maximum'])?></td><td><?= $consolidated['criteria'][$code] === null ? '0.00' : number_format($consolidated['criteria'][$code], 2) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <p><strong>Total score:</strong> <?=number_format((float) $final['final_score'], 2)?> / 100</p>
      </section>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 2</span><h2>FINAL SCORE AND VERDICT</h2></div>
        <p><strong>Final Calculated Score:</strong> <?=number_format((float) $final['final_score'], 2)?> / 100</p>
        <p><strong>Official E3 verdict:</strong> <?=esc($verdict['label'])?></p>
        <p><strong>Exact score range:</strong> <?=esc($verdict['range'])?></p>
        <p><strong>What the verdict means:</strong> <?=esc($verdict['description'])?></p>
        <p><strong>Required action or revision requirements:</strong> <?=esc($verdict['action'])?></p>
        <?php if (!empty($final['has_departure'])): ?><p><strong>Authorized departure recorded:</strong> <?=esc($final['departure_authority'])?> — <?=esc($final['departure_reason'])?></p><?php endif; ?>
      </section>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">PART 3</span><h2>REVISIONS, COMMENTS AND RECOMMENDATIONS</h2></div>
        <p><?=nl2br(esc(trim((string) ($final['revisions'] ?: $final['chairperson_comments'] ?: 'No revisions or recommendations.'))))?></p>
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
