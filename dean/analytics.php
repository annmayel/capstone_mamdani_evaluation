<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$filters = [
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
    'evaluator' => (int) ($_GET['evaluator'] ?? 0),
    'type' => in_array($_GET['type'] ?? 'all', ['all', 'project', 'paper'], true) ? (string) ($_GET['type'] ?? 'all') : 'all',
    'rating' => trim((string) ($_GET['rating'] ?? '')),
];
$isValidDate = static function (string $date): bool {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
};
if (!$isValidDate($filters['from'])) $filters['from'] = '';
if (!$isValidDate($filters['to'])) $filters['to'] = '';

$evaluators = $pdo->query("SELECT id, full_name FROM users WHERE role = 'evaluator' OR (role = 'dean' AND can_evaluate = 1) ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$evaluationQuery = "SELECT 'project' AS evaluation_type, e.project_id, e.evaluator_id, e.total_score, e.created_at, p.code, p.title, u.full_name AS evaluator_name
    FROM evaluations_project e INNER JOIN projects p ON p.id = e.project_id INNER JOIN users u ON u.id = e.evaluator_id
    UNION ALL
    SELECT 'paper' AS evaluation_type, e.project_id, e.evaluator_id, e.total_score, e.created_at, p.code, p.title, u.full_name AS evaluator_name
    FROM evaluations_paper e INNER JOIN projects p ON p.id = e.project_id INNER JOIN users u ON u.id = e.evaluator_id";
$evaluationRows = $pdo->query($evaluationQuery)->fetchAll(PDO::FETCH_ASSOC);
$projectRatings = [];
$projectIds = array_unique(array_map(static fn($row) => (int) $row['project_id'], $evaluationRows));
foreach ($projectIds as $projectId) {
    $panelScores = fuzzy_consolidated_scores($pdo, $projectId);
    if ($panelScores['final_score'] !== null) {
        $projectRatings[$projectId] = official_verdict_from_score($panelScores['final_score'])['label'];
    }
}
$filteredRows = array_values(array_filter($evaluationRows, static function ($row) use ($filters, $projectRatings): bool {
    $createdAt = substr((string) $row['created_at'], 0, 10);
    if ($filters['from'] !== '' && $createdAt < $filters['from']) return false;
    if ($filters['to'] !== '' && $createdAt > $filters['to']) return false;
    if ($filters['evaluator'] > 0 && (int) $row['evaluator_id'] !== $filters['evaluator']) return false;
    if ($filters['type'] !== 'all' && $row['evaluation_type'] !== $filters['type']) return false;
    if ($filters['rating'] !== '' && ($projectRatings[(int) $row['project_id']] ?? '') !== $filters['rating']) return false;
    return true;
}));

$evaluatorStats = [];
$projectScoreValues = [];
$paperScoreValues = [];
$filteredProjectIds = [];
foreach ($filteredRows as $row) {
    $evaluatorId = (int) $row['evaluator_id'];
    if (!isset($evaluatorStats[$evaluatorId])) {
        $evaluatorStats[$evaluatorId] = ['full_name' => $row['evaluator_name'], 'project_evals' => 0, 'paper_evals' => 0];
    }
    $evaluatorStats[$evaluatorId][$row['evaluation_type'] . '_evals']++;
    $filteredProjectIds[(int) $row['project_id']] = true;
    if ($row['total_score'] !== null) {
        if ($row['evaluation_type'] === 'project') $projectScoreValues[] = (float) $row['total_score'];
        else $paperScoreValues[] = (float) $row['total_score'];
    }
}
$evals = array_values($evaluatorStats);
$dist = [];
foreach (array_keys($filteredProjectIds) as $projectId) {
    $rating = $projectRatings[$projectId] ?? null;
    if ($rating !== null) $dist[$rating] = ($dist[$rating] ?? 0) + 1;
}
$dist = array_map(static fn($rating, $count) => ['rating' => $rating, 'cnt' => $count], array_keys($dist), array_values($dist));
$totalEvaluations = count($filteredRows);
$avgProject = $projectScoreValues ? round(array_sum($projectScoreValues) / count($projectScoreValues), 2) : 0;
$avgPaper = $paperScoreValues ? round(array_sum($paperScoreValues) / count($paperScoreValues), 2) : 0;
$totalRated = array_sum(array_map(static fn($row) => (int) $row['cnt'], $dist));
$ratingOptions = array_values(array_unique(array_values($projectRatings)));
sort($ratingOptions);

?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Analytics</title>
<link rel="stylesheet" href="../assets/style.css?v=20261112">
<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head><body class="dean-module-page dean-analytics-page">
    <h2>Analytics & Reports</h2><p>Monitor evaluator activity, evaluation scores, and official CEIT-T07B verdicts.</p>
    <form class="analytics-filter-toolbar" method="get"><input type="hidden" name="embedded" value="1"><label>From<input type="date" name="from" value="<?=esc($filters['from'])?>"></label><label>To<input type="date" name="to" value="<?=esc($filters['to'])?>"></label><label>Evaluator<select name="evaluator"><option value="0">All evaluators</option><?php foreach ($evaluators as $evaluator): ?><option value="<?=esc($evaluator['id'])?>" <?=$filters['evaluator'] === (int) $evaluator['id'] ? 'selected' : ''?>><?=esc($evaluator['full_name'])?></option><?php endforeach; ?></select></label><label>Evaluation type<select name="type"><option value="all" <?=$filters['type'] === 'all' ? 'selected' : ''?>>Project and paper</option><option value="project" <?=$filters['type'] === 'project' ? 'selected' : ''?>>Project evaluation</option><option value="paper" <?=$filters['type'] === 'paper' ? 'selected' : ''?>>Paper evaluation</option></select></label><label>Final rating<select name="rating"><option value="">All ratings</option><?php foreach ($ratingOptions as $rating): ?><option value="<?=esc($rating)?>" <?=$filters['rating'] === $rating ? 'selected' : ''?>><?=esc($rating)?></option><?php endforeach; ?></select></label><button class="btn primary" type="submit">Apply filters</button><a class="btn ghost" href="analytics.php?embedded=1">Reset</a></form>
    <p class="analytics-filter-note">Project category is not stored separately in the current project data. Evaluation type is filterable instead.</p>
    <div class="analytics-summary"><article><span>Total Evaluations</span><strong><?=esc($totalEvaluations)?></strong><small>Records matching current filters</small></article><article><span>Average Project Score</span><strong><?=esc($avgProject)?><small>/ 100</small></strong><small>Filtered project evaluations</small></article><article><span>Average Paper Score</span><strong><?=esc($avgPaper)?><small>/ 100</small></strong><small>Filtered paper evaluations</small></article><article><span>Rated Projects</span><strong><?=esc($totalRated)?></strong><small>Matching projects with a final rating</small></article></div>
    <div class="analytics-grid"><section class="analytics-card"><h3>Evaluations by Evaluator</h3><p>Compare submitted project and paper evaluation records.</p><?php if (!$evals): ?><div class="dean-empty-state">No evaluations match these filters.</div><?php else: ?><div class="analytics-chart"><canvas id="evalByEval"></canvas></div><?php endif; ?></section><section class="analytics-card"><h3>Final Rating Distribution</h3><p>Official verdicts for matching projects.</p><?php if (!$dist): ?><div class="dean-empty-state">No rated projects match these filters.</div><?php else: ?><div class="analytics-chart analytics-chart-donut"><canvas id="ratingDist"></canvas></div><?php endif; ?></section></div>
  <script>
    const evals = <?=json_encode($evals, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
  const labels = evals.map(e=>e.full_name);
    if (document.getElementById('evalByEval')) new Chart(document.getElementById('evalByEval'),{type:'bar',data:{labels,datasets:[{label:'Project evaluations',data:evals.map(e=>Number(e.project_evals)),backgroundColor:'rgba(23,107,120,.82)',borderRadius:4},{label:'Paper evaluations',data:evals.map(e=>Number(e.paper_evals)),backgroundColor:'rgba(185,139,101,.82)',borderRadius:4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#edf1f3'}}}}});

    const dist = <?=json_encode($dist, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
  const rlabels = dist.map(d=>d.rating);
  const rdata = dist.map(d=>d.cnt);
    if (document.getElementById('ratingDist')) new Chart(document.getElementById('ratingDist'),{type:'doughnut',data:{labels:rlabels,datasets:[{data:rdata,backgroundColor:['#176b78','#b98b65','#59747c','#b9c9ce','#8d6650'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{position:'bottom'}}}});
  </script>
</body></html>
