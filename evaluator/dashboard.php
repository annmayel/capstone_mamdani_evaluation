<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$uid = $_SESSION['user']['id'];
$evaluatorUser = $_SESSION['user'] ?? [];

$evaluatorFullName = trim((string)($evaluatorUser['full_name'] ?? 'Evaluator'));
$evaluatorEmail = trim((string)($evaluatorUser['email'] ?? ''));
$evaluatorInitial = strtoupper(substr($evaluatorFullName ?: 'E', 0, 1));
$displayName = $evaluatorFullName ?: 'Evaluator';
$evaluationError = $_GET['evaluation_error'] ?? '';
$projects = $pdo->prepare(
  'SELECT p.*, a.status AS assignment_status, a.created_at AS assigned_at,
    EXISTS(SELECT 1 FROM evaluations_project ep WHERE ep.project_id = p.id AND ep.evaluator_id = a.evaluator_id) AS has_project_evaluation,
    EXISTS(SELECT 1 FROM evaluations_paper er WHERE er.project_id = p.id AND er.evaluator_id = a.evaluator_id) AS has_paper_evaluation
   FROM projects p INNER JOIN evaluator_assignments a ON a.project_id = p.id
   WHERE a.evaluator_id = ? ORDER BY p.id DESC'
);
$projects->execute([$uid]);
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);
$assignedProjectIds = array_map(static fn($project) => (int) $project['id'], $projects);
$pendingProjectReviews = count(array_filter($projects, static fn($project) => empty($project['has_project_evaluation'])));
$pendingPaperReviews = count(array_filter($projects, static fn($project) => empty($project['has_paper_evaluation'])));
$completedEvaluations = count(array_filter($projects, static fn($project) => !empty($project['has_project_evaluation']) && !empty($project['has_paper_evaluation'])));
$averageScore = 0;
if ($assignedProjectIds) {
  $marks = implode(',', array_fill(0, count($assignedProjectIds), '?'));
  $averageStmt = $pdo->prepare("SELECT AVG(total_score) FROM evaluations_project WHERE evaluator_id = ? AND project_id IN ($marks)");
  $averageStmt->execute(array_merge([$uid], $assignedProjectIds));
  $averageScore = round((float) ($averageStmt->fetchColumn() ?: 0), 2);
}
$displayEmail = $evaluatorEmail ?: 'No email available';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluator Dashboard</title>
  <link rel="stylesheet" href="../assets/style.css?v=20260918">
  <link rel="stylesheet" href="evaluator-typography.css?v=20261112">
<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body>
  <div class="dashboard-layout evaluator-layout">
    <aside class="sidebar evaluator-sidebar">
      <div class="sidebar-header">
        <div class="logo-icon" aria-hidden="true"></div>
        <div class="brand-copy">
          <span class="brand-line">CAPSTONE</span>
          <span class="brand-line brand-emphasis">FUZZY</span>
          <small>EVALUATOR</small>
        </div>
      </div>
      <ul class="sidebar-nav">
<li class="nav-section-label">WORKSPACE</li>
<li><a href="dashboard.php" class="active" aria-current="page">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 13.5h7V20H4zM13 4h7v7h-7zM13 13.5h7V20h-7zM4 4h7v7H4z"/></svg></span>
<span class="nav-label">Dashboard</span>
</a></li>
<li class="nav-section-label">EVALUATION</li>
<li><a href="projects.php" class="">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 8.5A2.5 2.5 0 0 1 6 6h4l2 2h6.5A2.5 2.5 0 0 1 21 10.5v7A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5v-9zM3.5 9.5h17"/></svg></span>
<span class="nav-label">Evaluate Projects</span>
</a></li>
<li><a href="papers.php" class="">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3.5h8l4 4V18a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5.5a2 2 0 0 1 2-2zM15 3.5v4h4M8 11h8M8 15h8"/></svg></span>
<span class="nav-label">Evaluate Papers</span>
</a></li>
<li><a href="evaluation_history.php" class="">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v5l3 2M16 2.5v3M8 2.5v3M4 9.5h3M17 9.5h3M12 2.5a9.5 9.5 0 1 0 0 19 9.5 9.5 0 0 0 0-19z"/></svg></span>
<span class="nav-label">Evaluation History</span>
</a></li>
<li class="nav-section-label">ACCOUNT</li>
<li><a href="profile.php" class="">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zm-6.5 9.5c1.7-2.5 4.3-3.8 6.5-3.8s4.8 1.3 6.5 3.8"/></svg></span>
<span class="nav-label">Profile</span>
</a></li>
<li><a href="../logout.php" class="">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6a2 2 0 0 1-2-2v-2M14 12H4M6 9l-3 3 3 3"/></svg></span>
<span class="nav-label">Logout</span>
</a></li>
      </ul>
      <div class="sidebar-user evaluator-sidebar-user">
        <div class="sidebar-user-avatar"><?= esc($displayName ?: $evaluatorFullName ?: 'Evaluator')[0] ?? 'E' ?></div>
        <div class="sidebar-user-info">
          <h5><?= esc($displayName ?: $evaluatorFullName ?: 'Evaluator') ?></h5>
          <p><?= esc($displayEmail ?: $evaluatorEmail ?: 'No email available') ?></p>
        </div>
      </div>
    </aside>
<div class="main-content">
      <div class="main-header">
        <div class="main-header-text">
          <h1>Welcome back, <?=esc($displayName)?>!</h1>
          <p>Here are your assigned projects for evaluation.</p>
        </div>
        <div class="main-header-actions">
          <div class="date-selector">📅 This Week</div>
          <button class="btn-export">📥 Export Report</button>
        </div>
      </div>
      <?php if ($evaluationError === 'not_assigned'): ?><div class="home-alert" style="margin-bottom:18px;">This project is not assigned to your evaluator account. Ask the Dean to assign it to you, then open the evaluation tab again.</div><?php endif; ?>
      <div class="stat-grid">
        <div class="stat-card-v2">
          <div class="card-title">Assigned Projects</div>
          <div class="card-value"><?=esc(count($projects))?></div>
          <div class="trend">Assigned to you</div>
        </div>
        <div class="stat-card-v2">
          <div class="card-title">Pending Reviews</div>
          <div class="card-value"><?=esc($pendingProjectReviews + $pendingPaperReviews)?></div>
          <div class="trend">Project + paper reviews</div>
        </div>
        <div class="stat-card-v2">
          <div class="card-title">Completed</div>
          <div class="card-value"><?=esc($completedEvaluations)?></div>
          <div class="trend">Completed assignments</div>
        </div>
        <div class="stat-card-v2">
          <div class="card-title">Avg Score Given</div>
          <div class="card-value"><?=esc(number_format($averageScore, 2))?> <small>/ 100</small></div>
          <div class="trend">Current average</div>
        </div>
      </div>
      <div class="table-container" id="assigned-projects">
        <h3>Assigned Projects for Evaluation</h3>
        <table>
          <thead>
            <tr><th>Title</th><th>Group</th><th>Adviser</th><th>Defense Date</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach($projects as $p): ?>
              <tr>
                <td><?=esc($p['title'])?></td>
                <td><?=esc($p['group_name'])?></td>
                <td><?=esc($p['adviser'])?></td>
                <td><?=esc($p['defense_date'])?></td>
                <td><span style="color: #F59E0B; font-weight: 600;"> <?=esc(ucwords(str_replace('_', ' ', $p['assignment_status'] ?? 'assigned')))?> </span></td>
                <td>
                  <a href="evaluate_project.php?project_id=<?=esc($p['id'])?>" class="btn primary" style="padding: 6px 12px;">Evaluate Project</a>
                  <a href="evaluate_paper.php?project_id=<?=esc($p['id'])?>" class="btn ghost" style="padding: 6px 12px;">Evaluate Paper</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</body></html>
</body>
</html>
