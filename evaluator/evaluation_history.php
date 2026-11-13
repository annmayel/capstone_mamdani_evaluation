<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$evaluatorId = (int) $_SESSION['user']['id'];
$stmt = $pdo->prepare("SELECT p.title, p.group_name, e.total_score AS score, 'Project' AS evaluation_type, e.created_at FROM evaluations_project e INNER JOIN projects p ON p.id = e.project_id WHERE e.evaluator_id = ? UNION ALL SELECT p.title, p.group_name, e.total_score AS score, 'Paper' AS evaluation_type, e.created_at FROM evaluations_paper e INNER JOIN projects p ON p.id = e.project_id WHERE e.evaluator_id = ? ORDER BY created_at DESC");
$stmt->execute([$evaluatorId, $evaluatorId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
$evaluatorUser = $_SESSION['user'] ?? [];
$evaluatorFullName = trim((string)($evaluatorUser['full_name'] ?? 'Evaluator'));
$evaluatorEmail = trim((string)($evaluatorUser['email'] ?? ''));
$displayName = $evaluatorFullName ?: 'Evaluator';
$displayEmail = $evaluatorEmail ?: 'No email available';
$projectHistoryCount = 0;
$paperHistoryCount = 0;
$scoreTotal = 0.0;
foreach ($history as $item) {
  if (($item['evaluation_type'] ?? '') === 'Project') $projectHistoryCount++;
  if (($item['evaluation_type'] ?? '') === 'Paper') $paperHistoryCount++;
  $scoreTotal += (float)($item['score'] ?? 0);
}
$averageScore = $history ? $scoreTotal / count($history) : null;

function evaluator_content_sidebar(string $active, string $displayName, string $displayEmail): void { ?>
  <aside class="sidebar evaluator-sidebar" id="evaluator-sidebar" aria-label="Evaluator navigation">
    <div class="sidebar-header"><div class="logo-icon" aria-hidden="true"></div><div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div></div>
    <ul class="sidebar-nav">
      <li class="nav-section-label">Workspace</li><li><a href="dashboard.php"><span class="nav-icon" aria-hidden="true">▦</span><span class="nav-label">Dashboard</span></a></li>
      <li class="nav-section-label">Evaluation</li>
      <li><a href="projects.php" class="<?=$active === 'projects' ? 'active' : ''?>" <?=$active === 'projects' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">▰</span><span class="nav-label">Evaluate Projects</span></a></li>
      <li><a href="papers.php" class="<?=$active === 'papers' ? 'active' : ''?>" <?=$active === 'papers' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">▤</span><span class="nav-label">Evaluate Papers</span></a></li>
      <li><a href="evaluation_history.php" class="<?=$active === 'history' ? 'active' : ''?>" <?=$active === 'history' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">◷</span><span class="nav-label">Evaluation History</span></a></li>
      <li class="nav-section-label">Account</li><li><a href="profile.php" class="<?=$active === 'profile' ? 'active' : ''?>" <?=$active === 'profile' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">◎</span><span class="nav-label">Profile</span></a></li>
      <li><a href="../logout.php"><span class="nav-icon" aria-hidden="true">↪</span><span class="nav-label">Logout</span></a></li>
    </ul>
    <div class="sidebar-user evaluator-sidebar-user"><div class="sidebar-user-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div><div class="sidebar-user-info"><h5><?=esc($displayName)?></h5><p><?=esc($displayEmail)?></p></div></div>
  </aside>
<?php } ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluation History</title>
  <link rel="stylesheet" href="../assets/style.css?v=20260918">
  <link rel="stylesheet" href="evaluator-content.css?v=20261106">
  <link rel="stylesheet" href="evaluator-typography.css?v=20261112">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="evaluator-content-page">
  <div class="dashboard-layout evaluator-layout">
    <?php evaluator_content_sidebar('history', $displayName, $displayEmail); ?>
    <button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
    <main class="main-content">
      <div class="evaluator-topbar">
        <button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu" aria-controls="evaluator-sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="evaluator-topbar-end"><div class="account-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div><span><?=esc($displayName)?></span></div>
      </div>
      <header class="main-header">
        <div class="main-header-text"><span class="page-eyebrow">Your evaluation record</span><h1>Evaluation History</h1><p>A record of evaluations submitted by you.</p></div>
      </header>
      <section class="metric-grid" aria-label="Evaluation summary">
        <article class="metric-card"><div class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 1 4 17.5zM4 17.5A2.5 2.5 0 0 1 6.5 15H20M8 7h8M8 10h8"/></svg></div><div class="metric-copy"><span>Total evaluations</span><strong><?=count($history)?></strong></div></article>
        <article class="metric-card"><div class="metric-symbol warm" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v17H6.5A2.5 2.5 0 0 1 4 17.5zM8 8h8M8 12h6"/></svg></div><div class="metric-copy"><span>Project evaluations</span><strong><?=$projectHistoryCount?></strong></div></article>
        <article class="metric-card"><div class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M7 3.5h8l4 4V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19V5a1.5 1.5 0 0 1 1.5-1.5zM15 3.5V8h4M8 12h8M8 15h6"/></svg></div><div class="metric-copy"><span>Paper evaluations</span><strong><?=$paperHistoryCount?></strong></div></article>
        <article class="metric-card"><div class="metric-symbol warm" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/></svg></div><div class="metric-copy"><span>Average score</span><strong><?=$averageScore === null ? '—' : esc(number_format($averageScore, 2))?></strong></div></article>
      </section>
      <section class="work-panel" aria-labelledby="history-list-title">
        <div class="work-panel-head"><div><h2 id="history-list-title">Submitted evaluations</h2><p>Filter your submitted project and paper scores.</p></div></div>
        <div class="filters history-filters" aria-label="Evaluation history filters">
          <div class="filter-field filter-search"><label for="history-search">Search records</label><input id="history-search" type="search" placeholder="Project or group" data-filter="search"></div>
          <div class="filter-field"><label for="history-type">Evaluation type</label><select id="history-type" data-filter="type" data-populate="type"><option value="">All types</option></select></div>
          <div class="filter-field"><label for="history-score-min">Score at least</label><input id="history-score-min" type="number" min="0" max="100" step="0.01" placeholder="0" data-filter="score-min"></div>
          <div class="filter-field"><label for="history-score-max">Score at most</label><input id="history-score-max" type="number" min="0" max="100" step="0.01" placeholder="100" data-filter="score-max"></div>
          <div class="filter-field"><label for="history-date-from">Date from</label><input id="history-date-from" type="date" data-filter="date-from"></div>
          <div class="filter-field"><label for="history-date-to">Date to</label><input id="history-date-to" type="date" data-filter="date-to"></div>
          <div class="filter-field"><label for="history-status">Status</label><select id="history-status" data-filter="status"><option value="">All statuses</option><option value="completed">Completed</option></select></div>
          <div class="filter-field"><label for="history-sort">Sort by</label><select id="history-sort" data-filter="sort"><option value="">Newest first</option><option value="date-oldest">Oldest first</option><option value="score-high">Score: high to low</option><option value="score-low">Score: low to high</option><option value="title-asc">Project: A to Z</option></select></div>
          <button class="filter-reset" type="button" data-reset-filters>Reset filters</button>
        </div>
        <?php if (!$history): ?>
          <div class="empty-state" data-empty-state><div class="empty-state-mark" aria-hidden="true">◷</div><h3>No completed evaluations yet</h3><p>Your submitted evaluation records will appear here.</p></div>
        <?php else: ?>
          <div class="table-scroll">
            <table class="data-table" data-evaluator-list aria-describedby="history-range">
              <thead><tr><th scope="col">Project</th><th scope="col">Group</th><th scope="col">Type</th><th scope="col">Score</th><th scope="col">Date submitted</th><th scope="col">Status</th></tr></thead>
              <tbody>
                <?php foreach ($history as $item):
                  $title = (string)($item['title'] ?? '');
                  $group = (string)($item['group_name'] ?? '');
                  $type = (string)($item['evaluation_type'] ?? '');
                  $score = (float)($item['score'] ?? 0);
                  $createdAt = (string)($item['created_at'] ?? '');
                  $createdDate = $createdAt !== '' ? date('Y-m-d', strtotime($createdAt)) : '';
                ?>
                  <tr data-record data-title="<?=esc($title)?>" data-search="<?=esc($title . ' ' . $group)?>" data-group="<?=esc($group)?>" data-type="<?=esc(strtolower($type))?>" data-score="<?=esc($score)?>" data-date="<?=esc($createdDate)?>" data-status="completed">
                    <td class="title-cell"><span class="project-title"><?=esc($title)?></span></td><td><?=esc($group ?: 'Not set')?></td>
                    <td><span class="type-pill"><?=esc($type)?></span></td><td class="score-cell"><?=esc(number_format($score, 2))?> / 100</td>
                    <td><?= $createdAt !== '' ? esc(date('M j, Y', strtotime($createdAt))) : '<span class="muted-cell">Not available</span>' ?></td>
                    <td><span class="status-pill status-completed">Completed</span></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="no-results-row" data-no-results hidden><td colspan="6">No evaluations match these filters. Adjust or reset your filters.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="table-footer"><div id="history-range" data-range-label aria-live="polite"></div><div class="page-size"><label for="history-page-size">Rows</label><select id="history-page-size" data-page-size><option>10</option><option>25</option><option>50</option></select></div><nav class="pagination" data-pagination aria-label="History list pages"></nav></div>
        <?php endif; ?>
      </section>
    </main>
  </div>
  <script src="evaluator-content.js?v=20261106" defer></script>
</body>
</html>
