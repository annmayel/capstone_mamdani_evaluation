<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'dean') { header('Location: ../index.php'); exit; }

$deanProfileErrors = [];
$deanProfileUpdated = isset($_GET['profile_updated']);
$deanUserId = (int) ($_SESSION['user']['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
  $deanFullName = trim((string) ($_POST['full_name'] ?? ''));
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $deanProfileErrors[] = 'Your session expired. Refresh the page and try again.';
  } elseif ($deanFullName === '' || strlen($deanFullName) > 200) {
    $deanProfileErrors[] = 'Enter your full name (up to 200 characters).';
  } else {
    $updateDeanNameStmt = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ? AND role = ?');
    $updateDeanNameStmt->execute([$deanFullName, $deanUserId, 'dean']);
    $_SESSION['user']['full_name'] = $deanFullName;
    header('Location: dashboard.php?profile_updated=1#profile');
    exit;
  }
}
$deanProfileStmt = $pdo->prepare('SELECT full_name, email, employee_id_number, department FROM users WHERE id = ? AND role = ? LIMIT 1');
$deanProfileStmt->execute([$deanUserId, 'dean']);
$deanProfile = $deanProfileStmt->fetch(PDO::FETCH_ASSOC);
if (!$deanProfile) { header('Location: ../logout.php'); exit; }
$_SESSION['user']['full_name'] = $deanProfile['full_name'];

define('FUZZY_EMAIL_NOTIFICATIONS_EMBEDDED', true);
ob_start();
require_once __DIR__ . '/email_notifications.php';
$emailDeliverySection = ob_get_clean();

$dashboardProjects = $pdo->query('SELECT id, code, title, group_name FROM projects ORDER BY title ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
$totalProjects = count($dashboardProjects);
$totalEvaluators = $pdo->query("SELECT COUNT(*) FROM users WHERE role='evaluator' OR (role='dean' AND can_evaluate = 1)")->fetchColumn();
$scoreComparisonProjects = [];
$projectScoreTotals = ['project' => 0.0, 'paper' => 0.0];
$projectScoreCounts = ['project' => 0, 'paper' => 0];
$projectsWithScores = 0;
$ratingCounts = [];
foreach ($dashboardProjects as $project) {
  $scores = fuzzy_consolidated_scores($pdo, (int) $project['id']);
  if ($scores['project_score'] !== null || $scores['paper_score'] !== null) {
    $projectsWithScores++;
  }
  $scoreComparisonProjects[] = [
    'id' => (int) $project['id'],
    'code' => $project['code'],
    'title' => $project['title'],
    'project_score' => $scores['project_score'],
    'paper_score' => $scores['paper_score'],
  ];
  foreach (['project', 'paper'] as $scoreType) {
    $score = $scores[$scoreType . '_score'];
    if ($score !== null) {
      $projectScoreTotals[$scoreType] += $score;
      $projectScoreCounts[$scoreType]++;
    }
  }
  if ($scores['final_score'] !== null) {
    $rating = official_verdict_from_score($scores['final_score'])['label'];
    $ratingCounts[$rating] = ($ratingCounts[$rating] ?? 0) + 1;
  }
}
$avgProject = $projectScoreCounts['project'] ? round($projectScoreTotals['project'] / $projectScoreCounts['project'], 2) : 0;
$avgPaper = $projectScoreCounts['paper'] ? round($projectScoreTotals['paper'] / $projectScoreCounts['paper'], 2) : 0;
$recentProjects = $pdo->query('SELECT id, code, title, group_name FROM projects ORDER BY id DESC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC);
foreach ($recentProjects as &$recentProject) {
  $scores = fuzzy_consolidated_scores($pdo, (int) $recentProject['id']);
  $verdict = $scores['final_score'] === null ? null : official_verdict_from_score($scores['final_score']);
  $recentProject['project_score'] = $scores['project_score'];
  $recentProject['paper_score'] = $scores['paper_score'];
  $recentProject['final_score'] = $scores['final_score'];
  $recentProject['verdict'] = $verdict['label'] ?? 'Awaiting both evaluations';
}
unset($recentProject);
$recentActivity = $pdo->query(
  "SELECT activity_type, title, detail, created_at FROM (
     SELECT 'Project registered' AS activity_type, p.title, CONCAT('Project ', p.code, ' was added') AS detail, p.id AS activity_id, NULL AS created_at
     FROM projects p
     UNION ALL
     SELECT 'Project evaluation' AS activity_type, p.title, CONCAT(u.full_name, ' submitted a project evaluation') AS detail, e.id AS activity_id, e.created_at
     FROM evaluations_project e INNER JOIN projects p ON p.id = e.project_id INNER JOIN users u ON u.id = e.evaluator_id
     UNION ALL
     SELECT 'Paper evaluation' AS activity_type, p.title, CONCAT(u.full_name, ' submitted a paper evaluation') AS detail, e.id AS activity_id, e.created_at
     FROM evaluations_paper e INNER JOIN projects p ON p.id = e.project_id INNER JOIN users u ON u.id = e.evaluator_id
   ) activity ORDER BY COALESCE(created_at, '1970-01-01') DESC, activity_id DESC LIMIT 8"
)->fetchAll(PDO::FETCH_ASSOC);
$reportProjects = $pdo->query('SELECT id, code, title FROM projects ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC);
$defaultReportProjectId = (int) ($reportProjects[0]['id'] ?? 0);
$deanNotifications = [];
try {
  $notificationStmt = $pdo->prepare('SELECT title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 20');
  $notificationStmt->execute([(int) ($_SESSION['user']['id'] ?? 0)]);
  $deanNotifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
  $deanNotifications = [];
}
$unreadNotifications = count(array_filter($deanNotifications, static fn($notification) => !(int) $notification['is_read']));

?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dean Dashboard</title>
<link rel="stylesheet" href="../assets/style.css?v=20261112"><link rel="icon" type="image/png" href="../favicon.png?v=20261016"></head>
<body class="dean-dashboard">
  <div class="dashboard-layout dean-dashboard-layout">
    <div class="sidebar">
      <div class="sidebar-header">
        <div class="logo-icon" aria-hidden="true">△</div>
        <div>Capstone <span>Fuzzy</span></div>
        <button class="dean-sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></button>
      </div>
      <ul class="sidebar-nav">
        <li class="nav-section-label">Main</li>
        <li><a href="#dashboard" onclick="return showSection('dashboard')" class="active" id="nav-dashboard"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="5" rx="1"/><rect x="13" y="10" width="8" height="11" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/></svg></span><span>Dashboard</span></a></li>
        <li><a href="#analytics" onclick="return showSection('analytics')" id="nav-analytics"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M8 16v-4M13 16V7M18 16v-7"/></svg></span><span>Analytics</span></a></li>

        <li class="nav-section-label">Capstone Management</li>
        <li><a href="#users" onclick="return showSection('users')" id="nav-users"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Users</span></a></li>
        <li><a href="#project_setup" onclick="return showSection('project_setup')" id="nav-project_setup"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5A2.5 2.5 0 0 1 5.5 5H10l2 2h6.5A2.5 2.5 0 0 1 21 9.5v8a2.5 2.5 0 0 1-2.5 2.5h-13A2.5 2.5 0 0 1 3 17.5z"/><path d="M3.5 10h17"/></svg></span><span>Project Setup</span></a></li>
        <li><a href="#submissions" onclick="return showSection('submissions')" id="nav-submissions"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg></span><span>Capstone Submissions</span></a></li>
        <li><a href="#assign_evaluators" onclick="return showSection('assign_evaluators')" id="nav-assign_evaluators"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></svg></span><span>Assign Evaluators</span></a></li>

        <li class="nav-section-label">Fuzzy Configuration</li>
        <li><a href="#fuzzy_rules" onclick="return showSection('fuzzy_rules')" id="nav-fuzzy_rules"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.6.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.6-.9l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.9l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.6-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.6.9l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1-.1 1.9Z"/></svg></span><span>Fuzzy Rules</span></a></li>
        <li><a href="#membership_functions" onclick="return showSection('membership_functions')" id="nav-membership_functions"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M2 14h4M10 8h4M18 16h4"/></svg></span><span>Membership Functions</span></a></li>
        <li><a href="#simulator" onclick="return showSection('simulator')" id="nav-simulator"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6M10 3v6.5L4.5 19a1.4 1.4 0 0 0 1.2 2h12.6a1.4 1.4 0 0 0 1.2-2L14 9.5V3"/><path d="M7 16h10"/></svg></span><span>Simulator</span></a></li>

        <li class="nav-section-label">Reports &amp; Activity</li>
        <li><a href="#notifications" onclick="return showSection('notifications')" id="nav-notifications"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span><span>Notifications</span><?php if ($unreadNotifications): ?><strong class="nav-notification-count" aria-label="<?=$unreadNotifications?> unread notifications"><?=esc($unreadNotifications)?></strong><?php endif; ?></a></li>
        <li><a href="#email_delivery" onclick="return showSection('email_delivery')" id="nav-email_delivery"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg></span><span>Email Delivery</span></a></li>
        <li><a href="#generate_report" onclick="return showSection('generate_report')" id="nav-generate_report"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 16v-3M12 16V9M16 16v-5"/></svg></span><span>Generate Report</span></a></li>
        <li><a href="#profile" onclick="return showSection('profile')" id="nav-profile"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg></span><span>Profile</span></a></li>

        <li class="nav-logout"><a href="../logout.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg></span><span>Logout</span></a></li>
      </ul>
      <div class="sidebar-user">
        <div class="sidebar-user-avatar"><?=esc(strtoupper(substr((string) ($deanProfile['full_name'] ?? 'IT Chairperson'), 0, 1)))?></div>
        <div class="sidebar-user-info">
          <h5><?=esc($deanProfile['full_name'] ?: 'IT Chairperson')?></h5>
          <p><?=esc($deanProfile['email'] ?? '')?></p>
        </div>
      </div>
    </div>
    <button class="dean-sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1" hidden></button>
    <div class="main-content">
      <header class="dean-topbar">
        <div class="dean-topbar-title"><strong id="dean-page-title">Dean Dashboard</strong><small>Capstone evaluation management</small></div>
        <div class="dean-topbar-actions">
          <button class="dean-notification-button" type="button" aria-label="View notifications" onclick="return showSection('notifications')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php if ($unreadNotifications): ?><span><?=esc($unreadNotifications)?></span><?php endif; ?></button>
          <details class="dean-account-menu"><summary><span class="dean-account-avatar"><?=esc(strtoupper(substr((string) ($deanProfile['full_name'] ?? 'D'), 0, 1)))?></span><span><strong><?=esc($deanProfile['full_name'] ?: 'Dean')?></strong><small>Dean</small></span><i aria-hidden="true"></i></summary><div class="dean-account-links"><a href="#profile" onclick="return showSection('profile')">My profile</a><a href="../logout.php">Sign out</a></div></details>
        </div>
      </header>
      <!-- Dashboard Section -->
      <div id="section-dashboard" class="content-section">
        <div class="main-header">
          <div class="main-header-text">
            <h1>Welcome back, <?=esc($deanProfile['full_name'] ?: 'Administrator')?>!</h1>
            <p>Here's what's happening with your capstone evaluations.</p>
          </div>
          <div class="main-header-actions">
            <div class="date-selector"><svg class="dashboard-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>This Week</div>
            <a class="btn-export" href="#generate_report" onclick="return showSection('generate_report')"><svg class="dashboard-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 17v3h14v-3"/></svg>Export Report</a>
          </div>
        </div>
        <div class="stat-grid">
          <div class="stat-card-v2">
            <div class="card-title">Total Projects</div>
            <div class="card-value"><?=esc($totalProjects)?></div>
            <div class="stat-caption">Registered capstone projects</div>
          </div>
          <div class="stat-card-v2">
            <div class="card-title">Evaluators</div>
            <div class="card-value"><?=esc($totalEvaluators)?></div>
            <div class="stat-caption">Eligible evaluation accounts</div>
          </div>
          <div class="stat-card-v2">
            <div class="card-title">Average Project Score</div>
            <div class="card-value"><?=esc($avgProject)?> <small>/ 100</small></div>
            <div class="stat-caption">Panel average across scored projects</div>
          </div>
          <div class="stat-card-v2">
            <div class="card-title">Average Paper Score</div>
            <div class="card-value"><?=esc($avgPaper)?> <small>/ 100</small></div>
            <div class="stat-caption">Panel average across scored papers</div>
          </div>
        </div>
        <div class="dean-overview-grid">
          <section class="dean-panel dean-score-panel"><div class="dean-panel-heading"><div><h2>Evaluation Overview</h2><p>Panel-average scores by registered project</p></div><a href="#analytics" onclick="return showSection('analytics')">View analytics</a></div><div class="dean-overview-summary"><div><span>Projects with scores</span><strong><?=esc($projectsWithScores)?></strong></div><div><span>Not yet scored</span><strong><?=esc($totalProjects - $projectsWithScores)?></strong></div><div><span>Rated projects</span><strong><?=esc(array_sum($ratingCounts))?></strong></div></div><div class="dean-main-chart"><canvas id="scoreComparisonChart"></canvas></div></section>
          <section class="dean-panel dean-activity-panel"><div class="dean-panel-heading"><div><h2>Recent Activity</h2><p>Latest project and evaluation updates</p></div><a href="#notifications" onclick="return showSection('notifications')">View all</a></div><?php if (!$recentActivity): ?><div class="dean-empty-state">No project or evaluation activity yet.</div><?php else: ?><ol class="dean-activity-list"><?php foreach ($recentActivity as $activity): ?><li><span class="activity-indicator <?=str_contains($activity['activity_type'], 'Paper') ? 'paper' : (str_contains($activity['activity_type'], 'Project') && str_contains($activity['detail'], 'submitted') ? 'evaluation' : 'project')?>"></span><div><strong><?=esc($activity['activity_type'])?></strong><p><?=esc($activity['detail'])?> · <?=esc($activity['title'])?></p><time><?=esc($activity['created_at'] ? date('M j, Y g:i A', strtotime($activity['created_at'])) : 'Registration date unavailable')?></time></div></li><?php endforeach; ?></ol><?php endif; ?></section>
          <section class="dean-panel dean-rating-panel"><div class="dean-panel-heading"><div><h2>Final Rating Distribution</h2><p>Official CEIT-T07B verdicts</p></div></div><div class="dean-rating-chart"><canvas id="donutChart"></canvas></div><ul class="dean-rating-legend" id="rating-legend"></ul></section>
        </div>
        <section class="dean-panel dean-recent-projects"><div class="dean-panel-heading"><div><h2>Recent Projects</h2><p>Latest panel averages and official verdicts</p></div><a href="#project_setup" onclick="return showSection('project_setup')">Manage projects</a></div><div class="dean-table-scroll"><table class="dean-data-table"><thead><tr><th>Code</th><th>Project</th><th>Group</th><th>Project Average</th><th>Paper Average</th><th>Final Average</th><th>CEIT-T07B Verdict</th></tr></thead><tbody><?php if (!$recentProjects): ?><tr><td colspan="7">No projects registered.</td></tr><?php else: foreach ($recentProjects as $project): ?><tr><td><?=esc($project['code'])?></td><td><?=esc($project['title'])?></td><td><?=esc($project['group_name'] ?: 'Not set')?></td><td><?=esc($project['project_score'] === null ? 'Pending' : number_format($project['project_score'], 2))?></td><td><?=esc($project['paper_score'] === null ? 'Pending' : number_format($project['paper_score'], 2))?></td><td><?=esc($project['final_score'] === null ? 'Pending' : number_format($project['final_score'], 2) . ' / 100')?></td><td><?=esc($project['verdict'])?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
      </div>

      <div id="section-profile" class="content-section" style="display: none;">
        <div class="main-header">
          <div class="main-header-text"><h1>Dean Profile</h1><p>Update the name displayed across your account.</p></div>
        </div>
        <div class="performance-card">
          <h3><?=esc($_POST['full_name'] ?? $deanProfile['full_name'] ?? 'Dean')?></h3>
          <?php if ($deanProfileUpdated): ?><div class="register-field-feedback is-valid" role="status">Your full name has been updated.</div><?php endif; ?>
          <?php foreach ($deanProfileErrors as $profileError): ?><div class="register-field-feedback is-invalid" role="alert"><?=esc($profileError)?></div><?php endforeach; ?>
          <form method="post" action="dashboard.php#profile" class="student-profile-form">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
            <label for="dean-profile-full-name">Full name</label>
            <input id="dean-profile-full-name" name="full_name" type="text" maxlength="200" value="<?=esc($_POST['full_name'] ?? $deanProfile['full_name'] ?? '')?>" required>
            <button class="btn primary" type="submit">Save full name</button>
          </form>
          <p><strong>Email:</strong> <?=esc($deanProfile['email'] ?? '')?></p>
          <p><strong>Employee ID Number:</strong> <?=esc($deanProfile['employee_id_number'] ?? 'Not set')?></p>
          <p><strong>Department / Program:</strong> <?=esc($deanProfile['department'] ?? 'Not set')?></p>
          <p><strong>Role:</strong> Dean / IT Chairperson</p>
        </div>
      </div>

      <!-- Analytics Section -->
      <div id="section-analytics" class="content-section" style="display: none;">
        <iframe class="dean-module-frame" src="analytics.php?embedded=1" title="Analytics"></iframe>
      </div>

      <!-- Users Section -->
      <div id="section-users" class="content-section" style="display: none;">
        <iframe class="dean-module-frame dean-module-frame-tall" src="users.php?embedded=1" title="Users Management"></iframe>
      </div>

      <!-- Assign Evaluators Section -->
      <div id="section-assign_evaluators" class="content-section" style="display: none;">
        <iframe
          src="assign_evaluators.php?embedded=1"
          title="Assign Evaluators"
          style="display:block;width:100%;min-height:720px;border:0;background:#f8fafc;"
        ></iframe>
      </div>

      <!-- Project Setup Section -->
      <div id="section-project_setup" class="content-section" style="display: none;">
        <iframe src="project_setup.php?embedded=1" title="Project Setup" style="display:block;width:100%;min-height:900px;border:0;background:#f8fafc;"></iframe>
      </div>

      <!-- Capstone Submissions Section -->
      <div id="section-submissions" class="content-section" style="display: none;">
        <iframe
          id="submissions-frame"
          src="submissions.php?embedded=1"
          title="Capstone Submissions"
          style="display:block;width:100%;min-height:720px;border:0;background:#f8fafc;"
        ></iframe>
      </div>

      <!-- Notifications Section -->
      <div id="section-notifications" class="content-section" style="display: none;">
        <div class="main-header">
          <div class="main-header-text">
            <h1>Notifications</h1>
            <p>Updates from students, evaluators, and the capstone workflow.</p>
          </div>
        </div>
        <div class="table-container">
          <h3>Recent Activity</h3>
          <?php if (!$deanNotifications): ?>
            <div class="empty-state">No notifications yet. New student submissions will appear here.</div>
          <?php else: foreach ($deanNotifications as $notification): ?>
            <?php $notificationLink = str_contains((string) ($notification['link'] ?? ''), 'submissions') ? '#submissions' : '#dashboard'; ?>
            <article class="dean-notification <?=!(int) $notification['is_read'] ? 'unread' : ''?>">
              <span class="dean-notification-icon">●</span>
              <div><strong><?=esc($notification['title'])?></strong><p><?=esc($notification['message'])?></p><small><?=esc(date('M d, Y g:i A', strtotime($notification['created_at'])))?></small></div>
              <a class="btn ghost" href="<?=$notificationLink?>" onclick="return showSection('<?=$notificationLink === '#submissions' ? 'submissions' : 'dashboard'?>')"><?= $notificationLink === '#submissions' ? 'Review' : 'Open' ?></a>
            </article>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <?=$emailDeliverySection?>

      <!-- Fuzzy Rules Section -->
      <div id="section-fuzzy_rules" class="content-section" style="display: none;">
        <iframe class="dean-module-frame dean-module-frame-tall" src="fuzzy_rules.php?embedded=1" title="Fuzzy Rules"></iframe>
      </div>

      <!-- Membership Functions Section -->
      <div id="section-membership_functions" class="content-section" style="display: none;">
        <iframe class="dean-module-frame dean-module-frame-tall" src="membership_functions.php?embedded=1" title="Membership Functions"></iframe>
      </div>

      <!-- Simulator Section -->
      <div id="section-simulator" class="content-section" style="display: none;">
        <iframe class="dean-module-frame dean-module-frame-tall" src="fuzzy_simulator.php?embedded=1" title="Fuzzy Simulator"></iframe>
      </div>

      <!-- Generate Report Section -->
      <div id="section-generate_report" class="content-section" style="display: none;">
        <div class="module-toolbar">
          <div><h2>Generate Report</h2><p>Select a project to view its evaluation report and download a PDF.</p></div>
          <label for="report-project-select">Project
            <select id="report-project-select" onchange="loadReport(this.value)">
              <?php if (!$reportProjects): ?><option value="0">No projects available</option><?php endif; ?>
              <?php foreach ($reportProjects as $reportProject): ?><option value="<?=esc($reportProject['id'])?>"><?=esc($reportProject['code'] . ' - ' . $reportProject['title'])?></option><?php endforeach; ?>
            </select>
          </label>
        </div>
        <iframe id="report-frame" class="dean-module-frame dean-module-frame-tall" src="generate_report.php?project_id=<?=$defaultReportProjectId?>&amp;embedded=1" title="Generate Report"></iframe>
      </div>
    </div>
  </div>
  <dialog id="submission-preview" class="dean-submission-preview" aria-labelledby="submission-preview-title">
    <div class="dean-submission-preview-header">
      <h2 id="submission-preview-title">PDF preview</h2>
      <button type="button" class="btn ghost" id="close-submission-preview" aria-label="Close PDF preview">Close</button>
    </div>
    <iframe id="submission-preview-frame" title="Capstone submission PDF"></iframe>
  </dialog>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  // Tab switching function
  function showSection(sectionName, updateHistory = true) {
    closeSubmissionPreview(false);

    // Hide all sections
    const sections = document.querySelectorAll('.content-section');
    sections.forEach(section => {
      section.style.display = 'none';
    });
    
    // Remove active class from all nav links
    const navLinks = document.querySelectorAll('.sidebar-nav li a');
    navLinks.forEach(link => {
      link.classList.remove('active');
    });
    
    // Show selected section
    const targetSection = document.getElementById('section-' + sectionName);
    if (targetSection) {
      targetSection.style.display = 'block';
    }
    
    // Add active class to clicked nav link
    const activeNav = document.getElementById('nav-' + sectionName);
    if (activeNav) {
      activeNav.classList.add('active');
      const pageTitle = document.getElementById('dean-page-title');
      const activeLabel = activeNav.querySelector('span:not(.nav-icon)');
      if (pageTitle && activeLabel) pageTitle.textContent = activeLabel.textContent.trim();
    }

    if (window.location.hash !== '#' + sectionName) {
      const updateUrl = updateHistory ? 'pushState' : 'replaceState';
      window.history[updateUrl](null, '', '#' + sectionName);
    }
    
    // Prevent default link behavior
    return false;
  }

  function loadReport(projectId) {
    const reportFrame = document.getElementById('report-frame');
    if (reportFrame && projectId) reportFrame.src = 'generate_report.php?project_id=' + encodeURIComponent(projectId) + '&embedded=1';
  }

  const submissionsFrame = document.getElementById('submissions-frame');
  const submissionPreview = document.getElementById('submission-preview');
  const submissionPreviewFrame = document.getElementById('submission-preview-frame');
  submissionPreview.hidden = true;
  let previewReturnFocus = null;

  function closeSubmissionPreview(restoreFocus = true) {
    if (submissionPreview.open) {
      submissionPreview.close();
    }
    submissionPreview.hidden = true;
    submissionPreviewFrame.src = 'about:blank';
    if (restoreFocus && previewReturnFocus) previewReturnFocus.focus();
    previewReturnFocus = null;
  }

  document.getElementById('close-submission-preview').addEventListener('click', closeSubmissionPreview);
  submissionPreview.addEventListener('cancel', event => {
    event.preventDefault();
    closeSubmissionPreview();
  });
  submissionPreview.addEventListener('click', event => {
    if (event.target === submissionPreview) closeSubmissionPreview();
  });
  window.addEventListener('message', event => {
    if (event.origin !== window.location.origin || event.source !== submissionsFrame.contentWindow) return;
    if (!event.data || event.data.type !== 'dean:preview-submission') return;
    const submissionId = Number(event.data.submissionId);
    if (!Number.isSafeInteger(submissionId) || submissionId < 1) return;

    previewReturnFocus = submissionsFrame;
    submissionPreview.hidden = false;
    submissionPreviewFrame.src = '../evaluator/view_submission.php?id=' + encodeURIComponent(submissionId);
    submissionPreview.showModal();
    document.getElementById('close-submission-preview').focus();
  });

  const initialSection = window.location.hash.slice(1);
  showSection(document.getElementById('section-' + initialSection) ? initialSection : 'dashboard', false);
  window.addEventListener('popstate', () => {
    const sectionName = window.location.hash.slice(1);
    showSection(document.getElementById('section-' + sectionName) ? sectionName : 'dashboard', false);
  });
  window.addEventListener('hashchange', () => {
    const sectionName = window.location.hash.slice(1);
    showSection(document.getElementById('section-' + sectionName) ? sectionName : 'dashboard', false);
  });
  
  const scoreComparisonProjects = <?=json_encode($scoreComparisonProjects, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
  const projectLabels = scoreComparisonProjects.map(project => {
    const code = String(project.code || '').trim();
    const title = String(project.title || 'Untitled Project').trim();
    return code ? `${code} · ${title}` : title;
  });
  const projectScoreValues = scoreComparisonProjects.map(project => project.project_score === null ? null : Number(project.project_score));
  const paperScoreValues = scoreComparisonProjects.map(project => project.paper_score === null ? null : Number(project.paper_score));
  
  const scoreComparisonCanvas = document.getElementById('scoreComparisonChart');
  if (scoreComparisonCanvas) {
    const scoreComparisonHeight = Math.min(560, Math.max(190, scoreComparisonProjects.length * 44 + 58));
    scoreComparisonCanvas.parentElement.style.height = scoreComparisonHeight + 'px';
    new Chart(scoreComparisonCanvas, {
      type: 'bar',
      data: {
        labels: projectLabels,
        datasets: [
          {label: 'Project Score', data: projectScoreValues, backgroundColor: 'rgba(23,107,120,.82)', borderColor: '#176B78', borderWidth: 1, borderRadius: 4},
          {label: 'Paper Score', data: paperScoreValues, backgroundColor: 'rgba(185,139,101,.82)', borderColor: '#B98B65', borderWidth: 1, borderRadius: 4}
        ]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        datasets: {bar: {barPercentage: 0.78, categoryPercentage: 0.72}},
        interaction: {mode: 'index', intersect: false},
        plugins: {
          legend: {display: true, position: 'bottom'},
          tooltip: {
            callbacks: {
              label: context => `${context.dataset.label}: ${context.raw === null ? 'Not yet evaluated' : `${Number(context.raw).toFixed(2)} / 100`}`
            }
          }
        },
        scales: {
          x: {
            min: 0,
            max: 100,
            title: {display: true, text: 'Score'},
            grid: {color: 'rgba(61,77,85,.10)'}
          },
          y: {
            grid: {display: false},
            ticks: {autoSkip: false}
          }
        }
      }
    ,
      plugins: [{
        id: 'scoreComparisonValueLabels',
        afterDatasetsDraw(chart) {
          const {ctx, chartArea} = chart;
          ctx.save();
          ctx.font = '600 10px Poppins, Inter, sans-serif';
          ctx.textBaseline = 'middle';
          chart.data.datasets.forEach((dataset, datasetIndex) => {
            const meta = chart.getDatasetMeta(datasetIndex);
            meta.data.forEach((bar, index) => {
              const value = dataset.data[index];
              if (value === null || value === undefined || !Number.isFinite(Number(value))) return;
              const x = bar.x;
              const y = bar.y;
              const label = Number(value).toFixed(1);
              if (bar.width > 34) {
                ctx.fillStyle = '#fff';
                ctx.textAlign = 'right';
                ctx.fillText(label, Math.min(x - 6, chartArea.right - 4), y);
              } else if (x + 30 <= chart.width) {
                ctx.fillStyle = '#263a41';
                ctx.textAlign = 'left';
                ctx.fillText(label, x + 5, y);
              }
            });
          });
          ctx.restore();
        }
      }]
    });
  }
  
  const ratingCounts = <?=json_encode($ratingCounts, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)?>;
  const ratingLabels = Object.keys(ratingCounts);
  const ratingValues = Object.values(ratingCounts);
  const ratingColors = ['#176B78', '#B98B65', '#59747C', '#C9D5D8', '#8D6650'];
  const ratingLegend = document.getElementById('rating-legend');
  ratingLabels.forEach((rating, index) => {
    const item = document.createElement('li');
    item.innerHTML = `<span style="--legend-color:${ratingColors[index % ratingColors.length]}"></span><strong>${rating}</strong><small>${ratingValues[index]}</small>`;
    ratingLegend.append(item);
  });
  new Chart(document.getElementById('donutChart'), {
    type: 'doughnut',
    data: {labels: ratingLabels, datasets: [{data: ratingValues, backgroundColor: ratingLabels.map((_, index) => ratingColors[index % ratingColors.length]), borderWidth: 0}]},
    options: {responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: {legend: {display: false}}}
  });

  const sidebar = document.querySelector('.dean-dashboard-layout .sidebar');
  const sidebarToggle = document.querySelector('.dean-sidebar-toggle');
  const sidebarBackdrop = document.querySelector('.dean-sidebar-backdrop');
  const mobileNavigation = window.matchMedia('(max-width: 820px)');
  const closeMobileNavigation = () => {
    sidebar?.classList.remove('is-mobile-open');
    if (sidebarToggle) {
      sidebarToggle.setAttribute('aria-expanded', 'false');
      sidebarToggle.setAttribute('aria-label', 'Open navigation');
      sidebarToggle.title = 'Open navigation';
    }
    if (sidebarBackdrop) sidebarBackdrop.hidden = true;
  };
  sidebarToggle?.addEventListener('click', () => {
    if (mobileNavigation.matches) {
      const isOpen = sidebar.classList.toggle('is-mobile-open');
      sidebarToggle.setAttribute('aria-expanded', String(isOpen));
      sidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
      sidebarToggle.title = isOpen ? 'Close navigation' : 'Open navigation';
      if (sidebarBackdrop) sidebarBackdrop.hidden = !isOpen;
      return;
    }
    const isCollapsed = sidebar.classList.toggle('is-collapsed');
    sidebarToggle.setAttribute('aria-expanded', String(!isCollapsed));
    sidebarToggle.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
    sidebarToggle.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
    localStorage.setItem('dean-sidebar-collapsed', String(isCollapsed));
  });
  sidebarBackdrop?.addEventListener('click', closeMobileNavigation);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && mobileNavigation.matches) closeMobileNavigation();
  });
  document.querySelectorAll('.dean-dashboard-layout .sidebar-nav a[onclick]').forEach(link => {
    link.addEventListener('click', () => {
      if (mobileNavigation.matches) closeMobileNavigation();
    });
  });
  if (mobileNavigation.matches) {
    sidebarToggle?.setAttribute('aria-expanded', 'false');
    sidebarToggle?.setAttribute('aria-label', 'Open navigation');
    if (sidebarToggle) sidebarToggle.title = 'Open navigation';
  } else if (localStorage.getItem('dean-sidebar-collapsed') === 'true') {
    sidebar?.classList.add('is-collapsed');
    sidebarToggle?.setAttribute('aria-expanded', 'false');
    sidebarToggle?.setAttribute('aria-label', 'Expand sidebar');
    if (sidebarToggle) sidebarToggle.title = 'Expand sidebar';
  }
  </script>
</body></html>
