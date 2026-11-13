<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$evaluatorId = (int) $_SESSION['user']['id'];
$stmt = $pdo->prepare('SELECT p.*, a.status AS assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id = p.id WHERE a.evaluator_id = ? ORDER BY p.id DESC');
$stmt->execute([$evaluatorId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
$evaluatorUser = $_SESSION['user'] ?? [];
$evaluatorFullName = trim((string)($evaluatorUser['full_name'] ?? 'Evaluator'));
$evaluatorEmail = trim((string)($evaluatorUser['email'] ?? ''));
$displayName = $evaluatorFullName ?: 'Evaluator';
$displayEmail = $evaluatorEmail ?: 'No email available';

function evaluator_content_sidebar(string $active, string $displayName, string $displayEmail): void { ?>
  <aside class="sidebar evaluator-sidebar" id="evaluator-sidebar" aria-label="Evaluator navigation">
    <div class="sidebar-header">
      <div class="logo-icon" aria-hidden="true"></div>
      <div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div>
    </div>
    <ul class="sidebar-nav">
      <li class="nav-section-label">Workspace</li>
      <li><a href="dashboard.php"><span class="nav-icon" aria-hidden="true">▦</span><span class="nav-label">Dashboard</span></a></li>
      <li class="nav-section-label">Evaluation</li>
      <li><a href="projects.php" class="<?=$active === 'projects' ? 'active' : ''?>" <?=$active === 'projects' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">▰</span><span class="nav-label">Evaluate Projects</span></a></li>
      <li><a href="papers.php" class="<?=$active === 'papers' ? 'active' : ''?>" <?=$active === 'papers' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">▤</span><span class="nav-label">Evaluate Papers</span></a></li>
      <li><a href="evaluation_history.php" class="<?=$active === 'history' ? 'active' : ''?>" <?=$active === 'history' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">◷</span><span class="nav-label">Evaluation History</span></a></li>
      <li class="nav-section-label">Account</li>
      <li><a href="profile.php" class="<?=$active === 'profile' ? 'active' : ''?>" <?=$active === 'profile' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">◎</span><span class="nav-label">Profile</span></a></li>
      <li><a href="../logout.php"><span class="nav-icon" aria-hidden="true">↪</span><span class="nav-label">Logout</span></a></li>
    </ul>
    <div class="sidebar-user evaluator-sidebar-user">
      <div class="sidebar-user-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div>
      <div class="sidebar-user-info"><h5><?=esc($displayName)?></h5><p><?=esc($displayEmail)?></p></div>
    </div>
  </aside>
<?php } ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluate Projects</title>
  <link rel="stylesheet" href="../assets/style.css?v=20260918">
  <link rel="stylesheet" href="evaluator-content.css?v=20261106">
  <link rel="stylesheet" href="evaluator-typography.css?v=20261112">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="evaluator-content-page">
  <div class="dashboard-layout evaluator-layout">
    <?php evaluator_content_sidebar('projects', $displayName, $displayEmail); ?>
    <button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
    <main class="main-content">
      <div class="evaluator-topbar">
        <button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu" aria-controls="evaluator-sidebar" aria-expanded="false">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="evaluator-topbar-end"><div class="account-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div><span><?=esc($displayName)?></span></div>
      </div>
      <header class="main-header">
        <div class="main-header-text"><span class="page-eyebrow">Evaluation workspace</span><h1>Evaluate Projects</h1><p>Review only the capstone projects assigned to you.</p></div>
        <div class="assigned-count"><strong><?=count($projects)?></strong> assigned <?=count($projects) === 1 ? 'project' : 'projects'?></div>
      </header>
      <section class="work-panel" aria-labelledby="project-list-title">
        <div class="work-panel-head"><div><h2 id="project-list-title">Assigned Project Evaluations</h2><p>Search and refine your project assignments.</p></div></div>
        <div class="filters" aria-label="Project filters">
          <div class="filter-field filter-search"><label for="project-search">Search projects</label><input id="project-search" type="search" placeholder="Title, group, or adviser" data-filter="search"></div>
          <div class="filter-field"><label for="project-status">Assignment status</label><select id="project-status" data-filter="status" data-populate="status"><option value="">All statuses</option></select></div>
          <div class="filter-field"><label for="project-adviser">Adviser</label><select id="project-adviser" data-filter="adviser" data-populate="adviser"><option value="">All advisers</option></select></div>
          <div class="filter-field"><label for="project-date-from">Defense date from</label><input id="project-date-from" type="date" data-filter="date-from"></div>
          <div class="filter-field"><label for="project-date-to">Defense date to</label><input id="project-date-to" type="date" data-filter="date-to"></div>
          <div class="filter-field"><label for="project-sort">Sort by</label><select id="project-sort" data-filter="sort"><option value="">Default order</option><option value="title-asc">Title: A to Z</option><option value="title-desc">Title: Z to A</option><option value="date-asc">Defense date: earliest</option><option value="date-desc">Defense date: latest</option><option value="status-asc">Status</option></select></div>
          <button class="filter-reset" type="button" data-reset-filters>Reset filters</button>
        </div>
        <?php if (!$projects): ?>
          <div class="empty-state" data-empty-state><div class="empty-state-mark" aria-hidden="true">⌑</div><h3>No projects assigned yet</h3><p>Projects assigned to you will appear here.</p></div>
        <?php else: ?>
          <div class="table-scroll">
            <table class="data-table" data-evaluator-list aria-describedby="project-range">
              <thead><tr><th scope="col">Project title</th><th scope="col">Group</th><th scope="col">Adviser</th><th scope="col">Defense date</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
              <tbody>
                <?php foreach ($projects as $project):
                  $assignmentStatus = strtolower((string)($project['assignment_status'] ?? 'assigned'));
                  $statusLabel = ucwords(str_replace('_', ' ', $assignmentStatus));
                  $statusClass = preg_replace('/[^a-z0-9_-]/', '', $assignmentStatus);
                  $title = (string)($project['title'] ?? '');
                  $group = (string)($project['group_name'] ?? '');
                  $adviser = (string)($project['adviser'] ?? '');
                  $defenseDate = (string)($project['defense_date'] ?? '');
                ?>
                  <tr data-record data-title="<?=esc($title)?>" data-search="<?=esc($title . ' ' . $group . ' ' . $adviser)?>" data-group="<?=esc($group)?>" data-adviser="<?=esc($adviser)?>" data-date="<?=esc($defenseDate)?>" data-status="<?=esc($assignmentStatus)?>">
                    <td class="title-cell"><span class="project-title"><?=esc($title)?></span></td><td><?=esc($group ?: 'Not set')?></td><td><?=esc($adviser ?: 'Not assigned')?></td>
                    <td><?= $defenseDate !== '' ? esc(date('M j, Y', strtotime($defenseDate))) : '<span class="muted-cell">Not set</span>' ?></td>
                    <td><span class="status-pill status-<?=esc($statusClass)?>"><?=esc($statusLabel)?></span></td>
                    <td><a class="action-link" href="evaluate_project.php?project_id=<?=esc($project['id'])?>">Evaluate project</a></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="no-results-row" data-no-results hidden><td colspan="6">No projects match these filters. Adjust or reset your filters.</td></tr>
              </tbody>
            </table>
          </div>
          <div class="table-footer"><div id="project-range" data-range-label aria-live="polite"></div><div class="page-size"><label for="project-page-size">Rows</label><select id="project-page-size" data-page-size><option>10</option><option>25</option><option>50</option></select></div><nav class="pagination" data-pagination aria-label="Project list pages"></nav></div>
        <?php endif; ?>
      </section>
    </main>
  </div>
  <script src="evaluator-content.js?v=20261106" defer></script>
</body>
</html>
