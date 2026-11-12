<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/auth.php';
require_dean_access();
$project_id = intval($_GET['project_id'] ?? 1);
$p = $pdo->prepare('SELECT * FROM projects WHERE id = ?'); $p->execute([$project_id]); $project = $p->fetch(PDO::FETCH_ASSOC);
if (!$project) { echo 'Project not found'; exit; }
$panelScores = fuzzy_consolidated_scores($pdo, $project_id);
$projectScore = (float) ($panelScores['project_score'] ?? 0);
$paperScore = (float) ($panelScores['paper_score'] ?? 0);
$projectScoreDisplay = $panelScores['project_score'] === null ? 'Not evaluated' : number_format($projectScore, 2);
$paperScoreDisplay = $panelScores['paper_score'] === null ? 'Not evaluated' : number_format($paperScore, 2);
$fuzzyTrace = null;
$hasFinalResult = $panelScores['final_score'] !== null;
$finalScore = (float) ($panelScores['final_score'] ?? 0);
if ($hasFinalResult) {
    $fuzzyTrace = build_fuzzy_trace($projectScore, $paperScore, $pdo);
    $finalScore = (float) $fuzzyTrace['final_score'];
}
$officialVerdict = $hasFinalResult ? official_verdict_from_score($finalScore) : null;
$finalRating = $officialVerdict['label'] ?? 'Not evaluated';
$finalRatingDescription = $officialVerdict['description'] ?? '';
$finalNextStep = $officialVerdict['next_step'] ?? '';
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Project Report</title>
<link rel="stylesheet" href="../assets/style.css?v=20261112"><link rel="icon" type="image/png" href="../favicon.png?v=20261016"></head><body class="dean-module-page dean-report-page">
  <div class="report-preview">
    <header class="report-header"><div><p class="report-kicker">CAPSTONE FUZZY / OFFICIAL EVALUATION</p><h1><?=esc($project['title'])?></h1><p>Academic evaluation report and fuzzy decision trace.</p></div><div class="report-actions"><button class="btn ghost" id="printBtn">Print</button><button class="btn primary" id="pdfBtn">Download PDF</button></div></header>
    <section class="report-meta"><div><span>Project</span><strong><?=esc($project['title'])?></strong></div><div><span>Group</span><strong><?=esc($project['group_name'] ?: 'Not set')?></strong></div><div><span>Adviser</span><strong><?=esc($project['adviser'] ?: 'Not set')?></strong></div></section>
    <section class="report-score-grid"><article><span>Panel-average Project Evaluation</span><strong><?=esc($projectScoreDisplay)?> <?= $panelScores['project_score'] !== null ? '<small>/ 100</small>' : '' ?></strong></article><article><span>Panel-average Paper Evaluation</span><strong><?=esc($paperScoreDisplay)?> <?= $panelScores['paper_score'] !== null ? '<small>/ 100</small>' : '' ?></strong></article><article class="report-score-final"><span>Official Final Average Score</span><strong><?= $hasFinalResult ? esc(number_format($finalScore, 2)) . ' <small>/ 100</small>' : 'Pending' ?></strong><b><?=esc($finalRating)?></b><?php if ($hasFinalResult): ?><small>Rounded classification score: <?=esc($officialVerdict['rounded_score'])?> / 100</small><small><?=esc($finalRatingDescription)?></small><small><?=esc($finalNextStep)?></small><?php endif; ?></article></section>
    <section class="report-section"><div class="report-section-heading"><span>01</span><div><h2>Evaluation Summary</h2><p>Latest score per evaluator is averaged within each component; the two component averages are then averaged equally.</p></div></div><table class="report-table"><tbody><tr><th>Evaluation component</th><th>Consolidated score</th></tr><tr><td>Project evaluation</td><td><?=esc($projectScoreDisplay)?> / 100 (<?=esc($panelScores['project_evaluator_count'])?> evaluators)</td></tr><tr><td>Paper evaluation</td><td><?=esc($paperScoreDisplay)?> / 100 (<?=esc($panelScores['paper_evaluator_count'])?> evaluators)</td></tr><tr><td>Official final average score</td><td><strong><?= $hasFinalResult ? esc(number_format($finalScore, 2)) . ' / 100' : 'Pending' ?></strong></td></tr><tr><td>Rounded classification score</td><td><strong><?=esc($officialVerdict['rounded_score'] ?? 'Pending')?> / 100</strong></td></tr><tr><td>Official CEIT-T07B verdict</td><td><strong><?=esc($finalRating)?></strong><?php if ($hasFinalResult): ?><br><?=esc($finalRatingDescription)?><br><?=esc($finalNextStep)?><?php endif; ?></td></tr></tbody></table><p>The unweighted arithmetic mean of Project Quality and Paper Quality is used because CEIT-T07B scoring weights for these application components are not specified here. Confirm equal component weighting with the institution.</p></section>
    <?php if ($fuzzyTrace): ?>
      <section class="report-section"><div class="report-section-heading"><span>02</span><div><h2>Verdict Rule Analysis</h2><p>The representative-score analysis explains the matching rule; it does not set the official verdict.</p></div></div><div class="trace-summary"><div><span>Official average score</span><strong><?=esc(number_format($fuzzyTrace['final_score'], 2))?> / 100</strong></div><div><span>Rounded classification score</span><strong><?=esc($fuzzyTrace['rounded_score'])?> / 100</strong></div><div><span>Representative-score analysis output</span><strong><?=esc($fuzzyTrace['defuzz'])?> / 100</strong></div><div><span>Official CEIT-T07B verdict</span><strong><?=esc($finalRating)?></strong><small><?=esc($finalNextStep)?></small></div></div></section>
      <section class="report-section"><div class="report-section-heading"><span>03</span><div><h2>Membership Values</h2><p>Input memberships used during fuzzification.</p></div></div><div class="membership-grid"><?php foreach ($fuzzyTrace['inputMemberships'] ?? [] as $var => $membership): ?><article><h3><?=esc($var)?></h3><?php foreach ($membership as $label => $value): ?><div class="membership-row"><span><?=esc($label)?></span><div><i style="width:<?=esc(round((float) $value * 100, 1))?>%"></i></div><strong><?=esc(number_format((float) $value, 3))?></strong></div><?php endforeach; ?></article><?php endforeach; ?></div></section>
      <?php if (!empty($fuzzyTrace['firedRules'])): ?>
        <section class="report-section"><div class="report-section-heading"><span>04</span><div><h2>Applied Fuzzy Rules</h2><p>Rules activated by the evaluation inputs.</p></div></div><div class="report-rules"><?php foreach ($fuzzyTrace['firedRules'] as $rule): ?><div><span>Rule #<?=esc($rule['id'])?></span><p><?=esc($rule['rule_text'])?></p><strong><?=esc(number_format((float)($rule['strength'] ?? 0), 3))?></strong></div><?php endforeach; ?></div></section>
      <?php endif; ?>
    <?php endif; ?>
    <section class="report-section report-description"><div class="report-section-heading"><span>05</span><div><h2>Project Summary</h2><p><?=nl2br(esc($project['description'] ?: 'No project description was provided.'))?></p></div></div></section>
  </div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
document.getElementById('printBtn').addEventListener('click',()=>window.print());
document.getElementById('pdfBtn')?.addEventListener('click',async()=>{
  const projectId = <?=json_encode($project_id)?>;
  try {
    const res = await fetch('download_report.php?project_id=' + projectId);
    if (res.ok && res.headers.get('Content-Type') && res.headers.get('Content-Type').includes('application/pdf')) {
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'report.pdf';
      document.body.appendChild(a);
      a.click();
      a.remove();
      return;
    }
  } catch (e) {
    // client fallback below
  }

  const { jsPDF } = window.jspdf;
  const doc = new jsPDF();
  const title = document.querySelector('h1').innerText;
  doc.setFontSize(14); doc.text(title, 14, 20);
  const lines = document.querySelector('.report-preview').innerText.split('\n');
  doc.setFontSize(11);
  let y = 30;
  lines.forEach((line) => {
    if (y > 270) { doc.addPage(); y = 20; }
    doc.text(line, 14, y);
    y += 6;
  });
  doc.save((title || 'report') + '.pdf');
});
</script>
</body></html>
