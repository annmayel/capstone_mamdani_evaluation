<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$message = '';
$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        die('CSRF validation failed');
    }

    $projectId = intval($_POST['project_id'] ?? 0);
    $selected = $_POST['evaluators'] ?? [];
    $assignedBy = (int) ($_SESSION['user']['id'] ?? 0);

    if ($projectId > 0 && !empty($selected)) {
        $projectStmt = $pdo->prepare('SELECT title, student_id FROM projects WHERE id = ? LIMIT 1');
        $projectStmt->execute([$projectId]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            $newAssignmentCount = 0;
            foreach ($selected as $evaluatorId) {
                $evaluatorId = intval($evaluatorId);
                if ($evaluatorId <= 0) continue;
                $existingAssignmentStmt = $pdo->prepare('SELECT id FROM evaluator_assignments WHERE project_id = ? AND evaluator_id = ? LIMIT 1');
                $existingAssignmentStmt->execute([$projectId, $evaluatorId]);
                $existingAssignmentId = (int) $existingAssignmentStmt->fetchColumn();
                $isNewAssignment = !$existingAssignmentId;
                $stmt = $pdo->prepare('INSERT INTO evaluator_assignments (project_id, evaluator_id, assigned_by, status, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), status = VALUES(status), created_at = NOW()');
                $stmt->execute([$projectId, $evaluatorId, $assignedBy, 'assigned']);
                if ($isNewAssignment) {
                    $newAssignmentCount++;
                    $assignmentIdStmt = $pdo->prepare('SELECT id FROM evaluator_assignments WHERE project_id = ? AND evaluator_id = ? LIMIT 1');
                    $assignmentIdStmt->execute([$projectId, $evaluatorId]);
                    $assignmentId = (int) $assignmentIdStmt->fetchColumn();
                    notify_user_once($pdo, $evaluatorId, 'evaluator-assigned:' . $assignmentId . ':' . $evaluatorId, 'New evaluator assignment', 'You have been assigned to evaluate "' . ($project['title'] ?? 'a capstone project') . '".', 'evaluator/dashboard.php#dashboard');
                    require_once __DIR__ . '/../php/email_notifications.php';
                    $recipientStmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND (role = 'evaluator' OR (role = 'dean' AND can_evaluate = 1)) LIMIT 1");
                    $recipientStmt->execute([$evaluatorId]);
                    $evaluator = $recipientStmt->fetch(PDO::FETCH_ASSOC);
                    if ($evaluator) {
                        fuzzy_email_notify($pdo, [
                            'event_key' => 'evaluator_assigned:' . $assignmentId . ':' . $evaluatorId,
                            'event_type' => 'evaluator_assigned',
                            'recipient_user_id' => (int) $evaluator['id'],
                            'recipient_email' => (string) $evaluator['email'],
                            'recipient_name' => (string) $evaluator['full_name'],
                            'project_id' => $projectId,
                            'subject' => 'A capstone evaluation has been assigned to you',
                            'heading' => 'New evaluator assignment',
                            'intro' => 'You have been assigned to evaluate a capstone project. Sign in to review the submission and complete the assigned evaluation.',
                            'sections' => [['title' => 'Project', 'rows' => [['Title', (string) ($project['title'] ?? 'Capstone project')]]]],
                            'portal_path' => '/evaluator/dashboard.php',
                        ]);
                    }
                }
            }
        if (!empty($project['student_id'])) {
            $pdo->prepare("UPDATE projects SET status = 'Awaiting Evaluation' WHERE id = ? AND status IN ('Submitted', 'Awaiting Evaluator Assignment')")->execute([$projectId]);
            $studentStmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
            $studentStmt->execute([(int) $project['student_id']]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
            if ($student && $newAssignmentCount > 0) {
                require_once __DIR__ . '/../php/email_notifications.php';
                $latestAssignmentStmt = $pdo->prepare('SELECT MAX(id) FROM evaluator_assignments WHERE project_id = ?');
                $latestAssignmentStmt->execute([$projectId]);
                $latestAssignmentId = (int) $latestAssignmentStmt->fetchColumn();
                notify_user_once($pdo, (int) $student['id'], 'student-evaluator-assigned:' . $projectId . ':' . $latestAssignmentId, 'Evaluator assigned', 'An evaluator has been assigned to your capstone project.', 'students/student_dashboard.php?view=proposal');
                fuzzy_email_notify($pdo, [
                    'event_key' => 'student_evaluator_assigned:' . $projectId . ':' . (int) $student['id'] . ':' . $latestAssignmentId,
                    'event_type' => 'evaluator_assigned',
                    'recipient_user_id' => (int) $student['id'],
                    'recipient_email' => (string) $student['email'],
                    'recipient_name' => (string) $student['full_name'],
                    'project_id' => $projectId,
                    'subject' => 'An evaluator has been assigned to your capstone project',
                    'heading' => 'Evaluator assigned',
                    'intro' => 'An evaluator has been assigned to your capstone project. Your submission is awaiting review.',
                    'sections' => [['title' => 'Project', 'rows' => [['Title', (string) ($project['title'] ?? 'Capstone project')], ['Status', 'Awaiting Evaluation']]]],
                    'portal_path' => '/students/student_dashboard.php?view=proposal',
                ]);
            }
        }
        $message = 'Evaluator assignment saved.';
    }
}

$projects = $pdo->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$evaluators = $pdo->query("SELECT * FROM users WHERE (role='evaluator' OR (role='dean' AND can_evaluate = 1)) ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$assignmentMap = [];
$rows = $pdo->query('SELECT project_id, evaluator_id FROM evaluator_assignments ORDER BY project_id, evaluator_id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $assignmentMap[$row['project_id']][] = (int) $row['evaluator_id'];
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Assign Evaluators</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261112">
  <style>
    .assignment-page {
      --assignment-ink: #10232a;
      --assignment-muted: #64727a;
      --assignment-border: #e5ddd8;
      --assignment-surface: #ffffff;
      --assignment-soft: #faf8f6;
      max-width: 1120px;
      margin: 0 auto;
      padding: 20px 22px 32px;
      color: var(--assignment-ink);
    }

    .assignment-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 20px;
    }

    .assignment-kicker {
      margin: 0 0 5px;
      color: #b58863;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.4px;
      text-transform: uppercase;
    }

    .assignment-header h1 {
      margin: 0;
      font-size: clamp(24px, 3vw, 32px);
      line-height: 1.15;
    }

    .assignment-header p {
      max-width: 620px;
      margin: 8px 0 0;
      color: var(--assignment-muted);
      font-size: 14px;
    }

    .assignment-count {
      flex: 0 0 auto;
      padding: 10px 14px;
      border: 1px solid var(--assignment-border);
      border-radius: 10px;
      background: var(--assignment-surface);
      color: var(--assignment-muted);
      font-size: 12px;
      text-align: center;
    }

    .assignment-count strong {
      display: block;
      color: var(--assignment-ink);
      font-size: 20px;
      line-height: 1;
    }

    .assignment-form {
      display: grid;
      gap: 16px;
    }

    .assignment-card {
      border: 1px solid var(--assignment-border);
      border-radius: 12px;
      background: var(--assignment-surface);
      box-shadow: 0 8px 24px rgba(16, 35, 42, 0.05);
    }

    .project-card {
      padding: 18px;
    }

    .section-label {
      display: block;
      margin-bottom: 8px;
      color: var(--assignment-ink);
      font-size: 13px;
      font-weight: 700;
    }

    .section-hint {
      margin: -3px 0 13px;
      color: var(--assignment-muted);
      font-size: 12px;
    }

    .project-select {
      width: 100%;
      min-height: 44px;
      padding: 10px 13px;
      border: 1px solid #d8cbc2;
      border-radius: 8px;
      background: var(--assignment-soft);
      color: var(--assignment-ink);
      font: inherit;
      font-size: 14px;
    }

    .project-select:focus {
      outline: 3px solid rgba(181, 136, 99, 0.18);
      border-color: #b58863;
    }

    .evaluator-card {
      padding: 18px;
    }

    .evaluator-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 10px;
    }

    .evaluator-option {
      position: relative;
      display: flex;
      align-items: center;
      gap: 11px;
      min-width: 0;
      padding: 12px;
      border: 1px solid var(--assignment-border);
      border-radius: 9px;
      background: var(--assignment-soft);
      cursor: pointer;
      transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
    }

    .evaluator-option:hover,
    .evaluator-option:has(input:focus-visible) {
      border-color: #b58863;
      box-shadow: 0 0 0 3px rgba(181, 136, 99, 0.12);
    }

    .evaluator-option:has(input:checked) {
      border-color: #b58863;
      background: #fff8f2;
      box-shadow: inset 3px 0 0 #b58863;
    }

    .evaluator-option input {
      flex: 0 0 auto;
      width: 17px;
      height: 17px;
      accent-color: #b58863;
    }

    .evaluator-details {
      min-width: 0;
    }

    .evaluator-name,
    .evaluator-email {
      display: block;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .evaluator-name {
      color: var(--assignment-ink);
      font-size: 13px;
      font-weight: 700;
    }

    .evaluator-email {
      margin-top: 2px;
      color: var(--assignment-muted);
      font-size: 11px;
    }

    .assignment-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }

    .assignment-actions .btn {
      min-height: 42px;
      box-sizing: border-box;
    }

    .assignment-actions .primary {
      flex: 0 0 auto;
      min-width: 190px;
    }

    .assignment-actions .ghost {
      flex: 0 0 auto;
    }

    .assignment-alert {
      margin-bottom: 16px;
      padding: 11px 13px;
      border: 1px solid #c8e4d4;
      border-radius: 8px;
      background: #effaf3;
      color: #22633d;
      font-size: 13px;
    }

    .current-assignments {
      margin-top: 24px;
    }

    .current-heading {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }

    .current-heading h2 {
      margin: 0;
      font-size: 18px;
    }

    .current-heading span {
      color: var(--assignment-muted);
      font-size: 12px;
    }

    .assignment-table-wrap {
      overflow-x: auto;
      border: 1px solid var(--assignment-border);
      border-radius: 12px;
      background: var(--assignment-surface);
      box-shadow: 0 8px 24px rgba(16, 35, 42, 0.04);
    }

    .assignment-table {
      width: 100%;
      min-width: 540px;
      border-collapse: collapse;
      font-size: 13px;
    }

    .assignment-table th,
    .assignment-table td {
      padding: 13px 16px;
      border-bottom: 1px solid #eee8e4;
      text-align: left;
      vertical-align: middle;
    }

    .assignment-table th {
      background: var(--assignment-soft);
      color: var(--assignment-muted);
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.6px;
      text-transform: uppercase;
    }

    .assignment-table tr:last-child td {
      border-bottom: 0;
    }

    .project-title {
      color: var(--assignment-ink);
      font-weight: 700;
    }

    .evaluator-pill {
      display: inline-block;
      margin: 2px 5px 2px 0;
      padding: 5px 9px;
      border: 1px solid #efd8c4;
      border-radius: 999px;
      background: #fff8f2;
      color: #765437;
      font-size: 11px;
      font-weight: 600;
    }

    .no-assignment {
      color: var(--assignment-muted);
    }

    @media (max-width: 640px) {
      .assignment-page {
        padding: 16px 14px 24px;
      }

      .assignment-header {
        display: block;
      }

      .assignment-count {
        display: inline-block;
        margin-top: 14px;
      }

      .assignment-actions {
        display: grid;
        grid-template-columns: 1fr;
      }

      .assignment-actions .btn,
      .assignment-actions .primary {
        width: 100%;
        min-width: 0;
      }
    }
  </style>
<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="dean-module-page dean-assignment-page">
  <main class="assignment-page">
    <header class="assignment-header">
      <div>
        <p class="assignment-kicker">Dean workspace</p>
        <h1>Assign evaluators</h1>
        <p>Pair each capstone project with the faculty members responsible for its review.</p>
      </div>
      <div class="assignment-count"><strong><?=count($projects)?></strong>projects available</div>
    </header>
    <?php if ($message): ?><div class="assignment-alert"><?=esc($message)?></div><?php endif; ?>

    <form method="post" class="assignment-form">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <section class="assignment-card project-card">
        <label class="section-label" for="project-select">Project to assign</label>
        <p class="section-hint">Choose a project to see and update its evaluator panel.</p>
        <select class="project-select" id="project-select" name="project_id" required>
          <option value="">Select a project</option>
          <?php foreach ($projects as $project): ?>
              <option value="<?=esc($project['id'])?>" <?=((int) $project['id'] === $selectedProjectId ? 'selected' : '')?>><?=esc($project['title'])?> (<?=esc($project['group_name'] ?? 'No group')?>)</option>
          <?php endforeach; ?>
        </select>
      </section>

      <section class="assignment-card evaluator-card">
        <label class="section-label">Evaluator panel</label>
        <p class="section-hint">Select one or more evaluators. Selected members are highlighted.</p>
        <div class="evaluator-grid">
          <?php foreach ($evaluators as $e): ?>
            <?php $isAssigned = in_array((int) $e['id'], $assignmentMap[$selectedProjectId] ?? [], true); ?>
            <label class="evaluator-option">
              <input type="checkbox" name="evaluators[]" value="<?=esc($e['id'])?>" <?=$isAssigned ? 'checked' : ''?>>
              <span class="evaluator-details">
                <span class="evaluator-name"><?=esc($e['full_name'])?></span>
                <span class="evaluator-email"><?=esc($e['email'])?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </section>

      <div class="assignment-actions">
        <button class="btn primary" type="submit">Save Assignment</button>
        <a class="btn ghost" href="dashboard.php#submissions" target="_top">Review Submissions</a>
        <a class="btn ghost" href="dashboard.php#dashboard" target="_top">Back to Dashboard</a>
      </div>
    </form>

    <section class="current-assignments">
      <div class="current-heading">
        <h2>Current assignments</h2>
        <span>Live evaluator coverage by project</span>
      </div>
      <div class="assignment-table-wrap">
      <table class="assignment-table">
        <thead>
          <tr>
            <th>Project</th>
            <th>Assigned Evaluators</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projects as $project): ?>
            <tr>
              <td class="project-title"><?=esc($project['title'])?></td>
              <td>
                <?php if (isset($assignmentMap[$project['id']])): ?>
                  <?php foreach ($assignmentMap[$project['id']] as $evaluatorId): ?>
                    <?php
                    $assigned = null;
                    foreach ($evaluators as $e) {
                        if ((int)$e['id'] === (int)$evaluatorId) { $assigned = $e; break; }
                    }
                    ?>
                    <?php if ($assigned): ?>
                      <span class="evaluator-pill">
                        <?=esc($assigned['full_name'])?>
                      </span>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="no-assignment">No evaluator assigned</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </section>
  </main>
</body>
</html>
