<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/email_notifications.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$project_id = intval($_GET['project_id'] ?? 0);
$evaluator_id = (int) $_SESSION['user']['id'];
$project = $pdo->prepare('SELECT p.*, a.status AS assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id = p.id WHERE p.id = ? AND a.evaluator_id = ? LIMIT 1');
$project->execute([$project_id, $evaluator_id]);
$project = $project->fetch(PDO::FETCH_ASSOC);
if (!$project) {
  header('Location: projects.php?evaluation_error=not_assigned');
  exit;
}
$fields = [
  'functionality' => 'Functionality',
  'technical_quality' => 'Technical Quality',
  'performance' => 'Performance',
  'usability' => 'Usability',
  'uiux' => 'User Interface / UX',
  'security' => 'Security',
  'innovation' => 'Innovation',
  'relevance' => 'Relevance',
  'reliability' => 'Reliability',
  'completeness' => 'Completeness',
];
$errors = [];
$formValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$flashKey = 'project:' . $project_id . ':' . $evaluator_id;
$savedFlash = $_SESSION['evaluation_flash'][$flashKey] ?? null;
unset($_SESSION['evaluation_flash'][$flashKey]);
$saved = is_array($savedFlash);
$savedScore = $saved ? (float) $savedFlash['score'] : 0.0;
$savedStatus = $saved ? (string) $savedFlash['status'] : '';
$savedVerdict = $saved ? ($savedFlash['verdict'] ?? null) : null;
$formInputValue = static fn(string $key): string => isset($formValues[$key]) && is_scalar($formValues[$key]) ? (string) $formValues[$key] : '';
$submissionTokenKey = 'project:' . $project_id . ':' . $evaluator_id;
if (empty($_SESSION['evaluation_submission_tokens'][$submissionTokenKey])) {
  $_SESSION['evaluation_submission_tokens'][$submissionTokenKey] = bin2hex(random_bytes(32));
}
$submissionToken = $_SESSION['evaluation_submission_tokens'][$submissionTokenKey];
$submission = null;
try {
  $submissionStmt = $pdo->prepare('SELECT id, original_filename, created_at FROM student_submissions WHERE project_id = ? ORDER BY id DESC LIMIT 1');
  $submissionStmt->execute([$project_id]);
  $submission = $submissionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $exception) {
  error_log('[evaluation] Could not load proposal document for project ' . $project_id . ': ' . $exception->getMessage());
  $errors[] = 'The submitted proposal document could not be loaded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Your session could not be verified. Refresh the page and try again.';
  } elseif (!is_string($_POST['submission_token'] ?? null) || !hash_equals($submissionToken, $_POST['submission_token'])) {
    $errors[] = 'This form was already submitted or expired. Refresh the page before submitting again.';
  }

  $scores = [];
  foreach ($fields as $field => $label) {
    $rawValue = $_POST[$field] ?? null;
    if (!is_string($rawValue) || !preg_match('/^-?\d+(?:\.\d{1,2})?$/', trim($rawValue))) {
      $errors[] = $label . ' is required and must be a number from 0 to 10 with at most two decimal places.';
      continue;
    }
    $value = (float) $rawValue;
    if ($value < 0 || $value > 10) {
      $errors[] = $label . ' must be between 0 and 10.';
      continue;
    }
    $scores[$field] = $value;
  }

  $remarks = $_POST['remarks'] ?? '';
  if (!is_string($remarks)) {
    $remarks = '';
    $errors[] = 'Remarks must be plain text.';
  }

  if (!$errors && count($scores) === count($fields)) {
    $projectScore = round(array_sum($scores), 2);
    require_once __DIR__ . '/../php/fuzzy_engine.php';
    try {
      $pdo->beginTransaction();
      $assignmentCheck = $pdo->prepare('SELECT id FROM evaluator_assignments WHERE project_id = ? AND evaluator_id = ? FOR UPDATE');
      $assignmentCheck->execute([$project_id, $evaluator_id]);
      if (!$assignmentCheck->fetchColumn()) {
        throw new RuntimeException('The evaluator assignment is no longer active.');
      }

      $stmt = $pdo->prepare('INSERT INTO evaluations_project (project_id,evaluator_id,functionality,technical_quality,performance,usability,uiux,security,innovation,relevance,reliability,completeness,total_score,remarks,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $stmt->execute([
        $project_id,
        $evaluator_id,
        $scores['functionality'],
        $scores['technical_quality'],
        $scores['performance'],
        $scores['usability'],
        $scores['uiux'],
        $scores['security'],
        $scores['innovation'],
        $scores['relevance'],
        $scores['reliability'],
        $scores['completeness'],
        $projectScore,
        trim($remarks),
      ]);
      $evaluationId = (int) $pdo->lastInsertId();
      $panelScores = fuzzy_consolidated_scores($pdo, $project_id);
      $projectScore = (float) $panelScores['project_score'];
      $paperScore = (float) ($panelScores['paper_score'] ?? 0);
      $fuzzyResult = $panelScores['final_score'] === null
        ? null
        : build_fuzzy_trace($projectScore, $paperScore);
      $overall = $fuzzyResult['final_score'] ?? null;
      $rating = $fuzzyResult['rating'] ?? null;
      $pdo->prepare('UPDATE projects SET project_score = ?, paper_score = ?, overall_score = ?, overall_rating = ? WHERE id = ?')->execute([$projectScore, $paperScore, $overall, $rating, $project_id]);

      $otherEvaluation = $pdo->prepare('SELECT id FROM evaluations_paper WHERE project_id = ? AND evaluator_id = ? ORDER BY id DESC LIMIT 1');
      $otherEvaluation->execute([$project_id, $evaluator_id]);
      $hasPaperEvaluation = (bool) $otherEvaluation->fetchColumn();
      $assignmentStatus = $hasPaperEvaluation ? 'completed' : 'project_evaluated';
      $pdo->prepare('UPDATE evaluator_assignments SET status = ? WHERE project_id = ? AND evaluator_id = ?')->execute([$assignmentStatus, $project_id, $evaluator_id]);
      $pdo->prepare('INSERT INTO evaluation_history (project_id, evaluator_id, evaluation_type, project_score, paper_score, overall_score, overall_rating) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$project_id, $evaluator_id, 'project', $projectScore, $paperScore, $overall, $rating]);
      $studentStmt = $pdo->prepare('SELECT student_id FROM projects WHERE id = ?');
      $studentStmt->execute([$project_id]);
      $studentId = (int) $studentStmt->fetchColumn();
      $pdo->commit();
      unset($_SESSION['evaluation_submission_tokens'][$submissionTokenKey]);
      $_SESSION['evaluation_flash'][$flashKey] = [
        'score' => $projectScore,
        'status' => $assignmentStatus,
        'official_score' => $overall,
        'rounded_score' => $fuzzyResult['rounded_score'] ?? null,
        'verdict' => $rating,
        'next_step' => $fuzzyResult['next_step'] ?? null,
      ];

      if ($studentId > 0) {
        if ($panelScores['final_score'] !== null) {
          $paperEvaluationStmt = $pdo->prepare('SELECT id FROM evaluations_paper WHERE project_id = ? ORDER BY id DESC LIMIT 1');
          $paperEvaluationStmt->execute([$project_id]);
          $paperEvaluationId = (int) $paperEvaluationStmt->fetchColumn();
          $notificationKey = 'final-result-ready:' . $project_id . ':' . $evaluationId . ':' . $paperEvaluationId . ':' . $studentId;
          notify_user_once($pdo, $studentId, $notificationKey, 'Final CEIT-T07B verdict available', 'The panel-average project and paper scores are complete. View your official CEIT-T07B verdict and fuzzy analysis.', 'students/student_dashboard.php?view=results');
        } else {
          notify_user_once($pdo, $studentId, 'project-evaluation:' . $evaluationId . ':' . $studentId, 'Project evaluation updated', 'Your project evaluation score is now available in Evaluation Results.', 'students/student_dashboard.php?view=results');
        }
      }
      fuzzy_email_try_component_report($pdo, $project_id, $evaluationId, 'system');
      if ($fuzzyResult !== null) {
        fuzzy_email_try_final_report($pdo, $project_id, $fuzzyResult);
      }
      header('Location: evaluate_project.php?project_id=' . $project_id);
      exit;
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      error_log('[evaluation] Project evaluation save failed for project ' . $project_id . ' and evaluator ' . $evaluator_id . ': ' . $exception->getMessage());
      $errors[] = 'The project evaluation could not be saved. Your entries are still available; please try again.';
    }
  }
}

?>
<?php
$evaluatorUser = $_SESSION['user'] ?? [];
$evaluatorFullName = trim((string)($evaluatorUser['full_name'] ?? 'Evaluator'));
$evaluatorEmail = trim((string)($evaluatorUser['email'] ?? ''));
$evaluatorInitial = strtoupper(substr($evaluatorFullName ?: 'E', 0, 1));
$displayName = $evaluatorFullName ?: 'Evaluator';
$displayEmail = $evaluatorEmail ?: 'No email available';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluate Project</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261016-evaluator-forms">
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
<li><a href="dashboard.php" class="">
<span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 13.5h7V20H4zM13 4h7v7h-7zM13 13.5h7V20h-7zM4 4h7v7H4z"/></svg></span>
<span class="nav-label">Dashboard</span>
</a></li>
<li class="nav-section-label">EVALUATION</li>
<li><a href="projects.php" class="active" aria-current="page">
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
<main class="main-content"><div class="evaluation-content">
<a class="btn ghost evaluation-back" href="projects.php">← Back to Assigned Projects</a>
<header class="main-header"><div class="main-header-text"><h1>Evaluate Project</h1><p>Complete the project criteria review for this assigned capstone.</p></div></header>
<?php if ($saved): ?><div class="evaluation-notice success" role="status"><strong>Project evaluation saved.</strong><span><?=number_format((float) $savedScore, 2)?> / 100 · <?=esc(ucwords(str_replace('_', ' ', $savedStatus)))?></span><?php if ($savedVerdict): ?><span>Official average: <?=number_format((float) $savedFlash['official_score'], 2)?> / 100 (rounded <?=esc($savedFlash['rounded_score'])?>) · <?=esc($savedVerdict)?></span><span><?=esc($savedFlash['next_step'])?></span><?php else: ?><span>The official verdict will be available after both panel-average component scores are recorded.</span><?php endif; ?></div><?php endif; ?>
<?php if ($errors): ?><div class="evaluation-notice error" role="alert"><strong>Please review the following:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=esc($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="evaluation-info evaluation-card" aria-labelledby="project-information-title">
  <div class="evaluation-card-heading"><span class="evaluation-step">PROJECT DETAILS</span><h2 id="project-information-title">Project Information</h2></div>
  <dl class="evaluation-meta">
    <div><dt>Project title</dt><dd><?=esc($project['title'])?></dd></div>
    <div><dt>Group</dt><dd><?=esc($project['group_name'] ?? 'No group')?></dd></div>
    <div><dt>Adviser</dt><dd><?=esc($project['adviser'] ?? 'Not assigned')?></dd></div>
    <div><dt>Defense date</dt><dd><?=esc($project['defense_date'] ?? 'Not set')?></dd></div>
  </dl>
  <?php if ($submission): ?><a class="evaluation-link" href="view_submission.php?id=<?=esc($submission['id'])?>" target="_blank" rel="noopener">Open submitted proposal: <?=esc($submission['original_filename'])?></a><?php else: ?><p class="evaluation-document-missing">No proposal document has been submitted.</p><?php endif; ?>
</section>
<form method="post" class="evaluation-form evaluation-card" id="project-evaluation-form" novalidate>
  <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
  <input type="hidden" name="submission_token" value="<?=esc($submissionToken)?>">
  <div class="evaluation-card-heading"><span class="evaluation-step">SYSTEM REVIEW</span><h2>Evaluation Criteria <small>Score each item from 0 to 10</small></h2></div>
  <p class="evaluation-guidance">Enter a score for every required criterion. Decimal scores up to two places are accepted.</p>
  <div class="criteria-grid evaluation-fields">
    <?php foreach ($fields as $key => $label): ?>
      <label class="evaluation-field" for="score-<?=esc($key)?>">
        <span class="evaluation-field-label"><?=esc($label)?><span class="required-mark" aria-hidden="true">*</span></span>
        <span class="evaluation-field-hint">Maximum 10 points</span>
        <input id="score-<?=esc($key)?>" type="number" name="<?=esc($key)?>" min="0" max="10" step="0.01" inputmode="decimal" value="<?=esc($formInputValue($key))?>" required>
      </label>
    <?php endforeach; ?>
  </div>
  <label class="evaluation-field evaluation-remarks" for="project-remarks"><span class="evaluation-field-label">Evaluator remarks</span><span class="evaluation-field-hint">Provide specific, actionable feedback for the project team.</span><textarea id="project-remarks" name="remarks" rows="5" placeholder="Add clear feedback for the project team..."><?=esc($formInputValue('remarks'))?></textarea></label>
  <div class="evaluation-summary"><div><span class="evaluation-step">LIVE SCORE</span><strong>Current project score</strong><small>Sum of all 10 criteria · maximum 100</small></div><output id="project-total" aria-live="polite">0.00 <span>/ 100</span></output></div>
  <div class="evaluation-actions"><a class="btn ghost" href="projects.php">Cancel</a><button class="btn primary" type="submit" id="submit-evaluation">Submit Evaluation</button></div>
</form></div></main></div>
<div class="confirm-modal" id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-title" aria-hidden="true"><div class="confirm-dialog"><h3 id="confirm-title">Submit project evaluation?</h3><p>Review the scores and remarks before confirming. Your evaluation will be saved to the project history.</p><div class="confirm-actions"><button class="btn ghost" type="button" id="cancel-confirm">Cancel</button><button class="btn primary" type="button" id="confirm-submit">Confirm Submission</button></div></div></div>
<script>
(() => {
  const form = document.getElementById('project-evaluation-form');
  const modal = document.getElementById('confirm-modal');
  const total = document.getElementById('project-total');
  const confirmButton = document.getElementById('confirm-submit');
  const inputs = [...form.querySelectorAll('input[type="number"]')];
  let confirmed = false;
  const updateTotal = () => {
    const sum = inputs.reduce((value, input) => value + (Number(input.value) || 0), 0);
    total.innerHTML = `${sum.toFixed(2)} <span>/ 100</span>`;
  };
  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  };
  inputs.forEach(input => input.addEventListener('input', updateTotal));
  updateTotal();
  form.addEventListener('submit', event => {
    if (confirmed) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
  });
  document.getElementById('cancel-confirm').addEventListener('click', closeModal);
  confirmButton.addEventListener('click', () => {
    confirmed = true;
    confirmButton.disabled = true;
    confirmButton.textContent = 'Saving…';
    form.requestSubmit();
  });
})();
</script></body></html>
