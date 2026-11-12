<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$membershipMap = get_fuzzy_membership_map();
$verdictMemberships = $membershipMap['ProjectQuality'];
$chartData = [];
foreach ($verdictMemberships as $label => $range) {
    $chartData[] = ['label' => $label, 'minimum' => $range['minimum'], 'maximum' => $range['maximum']];
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fixed Membership Functions</title>
<link rel="stylesheet" href="../assets/style.css?v=20261112">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="icon" type="image/png" href="../favicon.png?v=20261016"></head>
<body class="dean-module-page dean-membership-page">
  <h2>Official Verdict Membership Functions</h2>
  <p>Project Quality, Paper Quality, and Overall now use the same five CEIT-T07B verdict categories. Membership is 1 for the verdict assigned to the rounded score and 0 for the other verdicts. These exact score bands do not overlap.</p>
  <table border="0" cellpadding="8">
    <thead><tr><th>Official Verdict</th><th>Rounded Score Range</th><th>Representative Score</th><th>Applied To</th></tr></thead>
    <tbody>
      <?php foreach ($verdictMemberships as $label => $range): ?>
        <tr>
          <td><?=esc($label)?></td>
          <td><?=esc($range['minimum'] . '–' . $range['maximum'])?></td>
          <td><?=esc($range['representative_score'])?></td>
          <td>Project Quality, Paper Quality, Overall</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <h3>Membership by Rounded Official Score</h3>
  <canvas id="mfChart" width="900" height="300" aria-label="Official CEIT-T07B verdict membership by rounded score"></canvas>
  <p class="membership-chart-explanation">This graph shows which official verdict applies to each rounded score from 0 to 100. Scores from 0–49 are FAILED, 50–59 are REDEFENSE, 60–79 are PASSED/APPROVED (Major Revision), 80–94 are PASSED/APPROVED (Minor Revision), and 95–100 are PASSED/APPROVED (No Revision). For each score, only one verdict has a membership degree of 1; all other verdicts have a degree of 0. The lines change at the score-range boundaries, and the categories do not overlap. The official final verdict is determined from the rounded final average using these same score ranges.</p>
  <script>
    const membershipData = <?=json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
    const labels = Array.from({length: 101}, (_, score) => score);
    const datasets = membershipData.map((membership, index) => {
      const degree = (score) => score >= membership.minimum && score <= membership.maximum ? 1 : 0;
      return {
        label: membership.label,
        data: labels.map(degree),
        borderColor: `hsl(${(index * 47) % 360} 55% 42%)`,
        borderWidth: 2,
        pointRadius: 0,
        stepped: true,
        fill: false
      };
    });
    new Chart(document.getElementById('mfChart'), {
      type: 'line',
      data: {labels, datasets},
      options: {
        responsive: true,
        scales: {
          x: {title: {display: true, text: 'Rounded score'}},
          y: {min: 0, max: 1, title: {display: true, text: 'Membership degree'}}
        }
      }
    });
  </script>
</body></html>
