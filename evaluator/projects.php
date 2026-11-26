<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/proposal_revisions.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$evaluatorId = (int) $_SESSION['user']['id'];
$stmt = $pdo->prepare('SELECT p.*, a.status AS assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id = p.id WHERE a.evaluator_id = ? ORDER BY p.id DESC');
$stmt->execute([$evaluatorId]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
$proposalSchemaReady = proposal_evaluation_schema_available($pdo);
$proposalScoreColumns = array_column(proposal_criteria(), 'key');
$revisionSchemaReady = proposal_revision_schema_available($pdo);
foreach ($projects as &$project) {
    $project['evaluator_proposal_status'] = 'assigned';
    $project['proposal_submission_id'] = null;
    $project['proposal_panel_complete'] = false;
    $project['revision_cycle_id'] = null;
    try {
        $latestSubmission = latest_stored_pdf_stage_submission($pdo, (int) $project['id'], 'proposal');
        if ($latestSubmission) {
            $project['proposal_submission_id'] = (int) $latestSubmission['id'];
        }
        if ($proposalSchemaReady) {
            // Use the submission ID saved with the evaluator's sheet, rather
            // than assuming it is still the latest uploaded proposal. This
            // preserves the status and view link after a later revision upload.
            $evaluationStmt = $pdo->prepare(
                "SELECT pe.*, s.id AS saved_submission_id, s.stored_filename
                 FROM proposal_evaluations pe
                 INNER JOIN student_submissions s
                   ON s.id = pe.proposal_submission_id
                  AND s.project_id = pe.project_id
                  AND s.stage = 'proposal'
                 WHERE pe.project_id = ? AND pe.evaluator_id = ? AND pe.status = 'submitted'
                 ORDER BY pe.submitted_at DESC, pe.id DESC"
            );
            $evaluationStmt->execute([(int) $project['id'], $evaluatorId]);
            while ($evaluation = $evaluationStmt->fetch(PDO::FETCH_ASSOC)) {
                $hasCompleteScores = count(array_filter(
                    $proposalScoreColumns,
                    static fn(string $column): bool => array_key_exists($column, $evaluation) && $evaluation[$column] !== null
                )) === count($proposalScoreColumns);
                $filename = (string) ($evaluation['stored_filename'] ?? '');
                if (!$hasCompleteScores || $filename === '' || basename($filename) !== $filename || !str_ends_with(strtolower($filename), '.pdf')) {
                    continue;
                }
                $project['evaluator_proposal_status'] = 'submitted';
                $project['proposal_submission_id'] = (int) $evaluation['saved_submission_id'];
                break;
            }
        }
        if ($proposalSchemaReady && !empty($project['proposal_submission_id'])) {
            $panel = proposal_panel_completion($pdo, (int) $project['id'], (int) $project['proposal_submission_id']);
            $project['proposal_panel_complete'] = $panel['ready'];
        }
        if ($revisionSchemaReady) {
            $reviewStmt = $pdo->prepare("SELECT c.id FROM proposal_revision_cycles c INNER JOIN proposal_revision_reviews r ON r.revision_cycle_id=c.id WHERE c.project_id=? AND c.status IN ('pending_panel_review','returned') AND r.evaluator_id=? AND r.decision='pending' ORDER BY c.id DESC LIMIT 1");
            $reviewStmt->execute([(int)$project['id'],$evaluatorId]);
            $project['revision_cycle_id'] = $reviewStmt->fetchColumn() ?: null;
        }
    } catch (PDOException $exception) {
        error_log('[evaluator projects] Could not load evaluator proposal state: ' . $exception->getMessage());
    }
}
unset($project);
$evaluatorUser = $_SESSION['user'] ?? [];
$evaluatorFullName = trim((string)($evaluatorUser['full_name'] ?? 'Evaluator'));
$evaluatorEmail = trim((string)($evaluatorUser['email'] ?? ''));
$displayName = $evaluatorFullName ?: 'Evaluator';
$displayEmail = $evaluatorEmail ?: 'No email available';

function evaluator_content_sidebar(string $active, string $displayName, string $displayEmail): void { ?>
  <aside class="sidebar evaluator-sidebar" id="evaluator-sidebar" aria-label="Evaluator navigation">
    <div class="sidebar-header">
      <img class="logo-icon" src="../images_bgnd/CSUCC%20LOGO.png" alt="" aria-hidden="true">
      <div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div>
    </div>
    <ul class="sidebar-nav">
      <li class="nav-section-label">Workspace</li>
      <li><a href="dashboard.php"><span class="nav-icon" aria-hidden="true">▦</span><span class="nav-label">Dashboard</span></a></li>
      <li class="nav-section-label">Evaluation</li>
      <li><a href="projects.php" class="<?=$active === 'projects' ? 'active' : ''?>" <?=$active === 'projects' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5A2.5 2.5 0 0 1 6 6h4l2 2h6.5A2.5 2.5 0 0 1 21 10.5v7A2.5 2.5 0 0 1 18.5 20h-13A2.5 2.5 0 0 1 3 17.5v-9zM3.5 9.5h17"/></svg></span><span class="nav-label">Evaluation Workspace</span></a></li>
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
  <title>Evaluation Workspace</title>
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
        <div class="main-header-text"><span class="page-eyebrow">Proposal stage</span><h1>Evaluation Workspace</h1><p>Review each assigned proposal manuscript and proposed project in one official CEIT-T07A evaluation.</p></div>
        <div class="assigned-count"><strong><?=count($projects)?></strong> assigned <?=count($projects) === 1 ? 'submission' : 'submissions'?></div>
      </header>
      <?php if (($_GET['evaluation_error'] ?? '') === 'proposal_not_submitted'): ?><div class="e3-alert error" role="alert">A valid proposal PDF for this assigned project is not available yet.</div><?php endif; ?>
      <section class="work-panel" aria-labelledby="project-list-title">
        <div class="work-panel-head"><div><h2 id="project-list-title">Assigned Proposal Submissions</h2><p>Each submission has one unified evaluation sheet.</p></div></div>
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
          <div class="empty-state" data-empty-state><div class="empty-state-mark" aria-hidden="true">⌑</div><h3>No submissions assigned yet</h3><p>Proposal submissions assigned to you will appear here.</p></div>
        <?php else: ?>
          <div class="table-scroll">
            <table class="data-table" data-evaluator-list aria-describedby="project-range">
              <thead><tr><th scope="col">Proposal title</th><th scope="col">Group</th><th scope="col">Adviser</th><th scope="col">Defense date</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
              <tbody>
                <?php foreach ($projects as $project):
                  $assignmentStatus = strtolower((string)($project['evaluator_proposal_status'] ?? 'assigned'));
                  $statusLabel = ucwords(str_replace('_', ' ', $assignmentStatus));
                  $statusClass = $assignmentStatus === 'submitted' ? 'completed' : 'assigned';
                  $title = (string)($project['title'] ?? '');
                  $group = (string)($project['group_name'] ?? '');
                  $adviser = (string)($project['adviser'] ?? '');
                  $defenseDate = (string)($project['defense_date'] ?? '');
                ?>
                  <tr data-record data-title="<?=esc($title)?>" data-search="<?=esc($title . ' ' . $group . ' ' . $adviser)?>" data-group="<?=esc($group)?>" data-adviser="<?=esc($adviser)?>" data-date="<?=esc($defenseDate)?>" data-status="<?=esc($assignmentStatus)?>">
                    <td class="title-cell"><span class="project-title"><?=esc($title)?></span></td><td><?=esc($group ?: 'Not set')?></td><td><?=esc($adviser ?: 'Not assigned')?></td>
                    <td><?= $defenseDate !== '' ? esc(date('M j, Y', strtotime($defenseDate))) : '<span class="muted-cell">Not set</span>' ?></td>
                    <td><span class="status-pill status-<?=esc($statusClass)?>"><?=esc($statusLabel)?></span><?php if (!empty($project['proposal_panel_complete'])): ?><br><small class="muted-cell">Panel Completed</small><?php endif; ?></td>
                    <td><?php if (!empty($project['revision_cycle_id'])): ?><a class="action-link" href="review_proposal_revision.php?cycle_id=<?=esc($project['revision_cycle_id'])?>">Review Revision</a><?php elseif ($assignmentStatus === 'submitted' && !empty($project['proposal_submission_id'])): ?><a class="action-link" href="evaluate_project.php?project_id=<?=esc($project['id'])?>&amp;proposal_submission_id=<?=esc($project['proposal_submission_id'])?>">View Submitted Evaluation</a><?php else: ?><a class="action-link" href="evaluate_selection.php?project_id=<?=esc($project['id'])?>">Evaluate Submission</a><?php endif; ?></td>
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
