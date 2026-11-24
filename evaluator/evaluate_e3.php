<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/e3.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') {
  header('Location: ../index.php');
  exit;
}

$projectId = (int) ($_GET['project_id'] ?? 0);
$evaluatorId = (int) $_SESSION['user']['id'];
$projectStmt = $pdo->prepare('SELECT p.*, a.status AS assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id = p.id WHERE p.id = ? AND a.evaluator_id = ? LIMIT 1');
$projectStmt->execute([$projectId, $evaluatorId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
if (!$project) {
  header('Location: projects.php?evaluation_error=not_assigned');
  exit;
}

$errors = [];
$success = '';
$criteria = e3_criteria();
$existingStmt = $pdo->prepare('SELECT * FROM e3_evaluations WHERE project_id = ? AND evaluator_id = ? LIMIT 1');
$existingStmt->execute([$projectId, $evaluatorId]);
$existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$form = $existing ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $errors[] = 'Your session could not be verified. Refresh the page and try again.';
  } elseif ($existing && $existing['status'] === 'submitted') {
    $errors[] = 'This E3 evaluation has already been submitted and cannot be edited.';
  } else {
    $action = (string) ($_POST['action'] ?? 'submit');
    foreach ($criteria as $criterion) {
      $key = $criterion['key'];
      if (array_key_exists($key, $_POST)) {
        $form[$key] = (string) $_POST[$key];
      }
    }
    $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));
    $form['revisions'] = trim((string) ($_POST['revisions'] ?? ''));

    $validation = e3_validate_scores($_POST);
    if ($action === 'submit' && $validation['errors']) {
      $errors = array_merge($errors, $validation['errors']);
    }
    if (!$errors) {
      if ($action === 'submit') {
        $scores = $validation['scores'];
        $total = $validation['total'];
        $status = 'submitted';
        $submittedAt = date('Y-m-d H:i:s');
      } else {
        $scores = [];
        $total = 0;
        foreach ($criteria as $criterion) {
          $key = $criterion['key'];
          $raw = trim((string) ($_POST[$key] ?? ''));
          if ($raw !== '' && preg_match('/^(?:\d+(?:\.\d{1,2})?)$/', $raw)) {
            $value = (float) $raw;
            if ($value >= 0 && $value <= (float) $criterion['maximum']) {
              $scores[$key] = round($value, 2);
              $total += $value;
            }
          }
        }
        $status = 'draft';
        $submittedAt = null;
      }

      try {
        $pdo->beginTransaction();
        $values = [];
        foreach ($criteria as $criterion) {
          $values[$criterion['key']] = $scores[$criterion['key']] ?? null;
        }
        if ($existing) {
          $stmt = $pdo->prepare('UPDATE e3_evaluations SET fr1_1=?, fr1_2=?, fr1_3=?, fr1_4=?, arc2_1=?, arc2_2=?, arc2_3=?, dp3_1=?, dp3_2=?, pmd4_1=?, pmd4_2=?, pmd4_3=?, total_score=?, remarks=?, revisions=?, status=?, submitted_at=? WHERE id=?');
          $stmt->execute([
            $values['fr1_1'], $values['fr1_2'], $values['fr1_3'], $values['fr1_4'],
            $values['arc2_1'], $values['arc2_2'], $values['arc2_3'],
            $values['dp3_1'], $values['dp3_2'],
            $values['pmd4_1'], $values['pmd4_2'], $values['pmd4_3'],
            $total, $form['remarks'], $form['revisions'], $status, $submittedAt, (int) $existing['id'],
          ]);
          $evaluationId = (int) $existing['id'];
        } else {
          $stmt = $pdo->prepare('INSERT INTO e3_evaluations (project_id,evaluator_id,fr1_1,fr1_2,fr1_3,fr1_4,arc2_1,arc2_2,arc2_3,dp3_1,dp3_2,pmd4_1,pmd4_2,pmd4_3,total_score,remarks,revisions,status,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
          $stmt->execute([
            $projectId, $evaluatorId,
            $values['fr1_1'], $values['fr1_2'], $values['fr1_3'], $values['fr1_4'],
            $values['arc2_1'], $values['arc2_2'], $values['arc2_3'],
            $values['dp3_1'], $values['dp3_2'],
            $values['pmd4_1'], $values['pmd4_2'], $values['pmd4_3'],
            $total, $form['remarks'], $form['revisions'], $status, $submittedAt,
          ]);
          $evaluationId = (int) $pdo->lastInsertId();
        }
        if ($status === 'submitted') {
          $pdo->prepare('UPDATE evaluator_assignments SET status = ? WHERE project_id = ? AND evaluator_id = ?')->execute(['completed', $projectId, $evaluatorId]);
          $pdo->prepare('INSERT INTO evaluation_history (project_id,evaluator_id,evaluation_type,project_score,paper_score,overall_score,overall_rating) VALUES (?,?,?,?,?,?,?)')->execute([$projectId, $evaluatorId, 'overall', $total, $total, $total, e3_verdict($total)['label']]);
        }
        $pdo->commit();
        $_SESSION['e3_flash'] = $status === 'submitted'
          ? 'E3 evaluation submitted. Total: ' . number_format($total, 2) . '/100.'
          : 'E3 evaluation draft saved.';
        header('Location: evaluate_e3.php?project_id=' . $projectId);
        exit;
      } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[e3] Evaluation save failed: ' . $exception->getMessage());
        $errors[] = 'The E3 evaluation could not be saved.';
      }
    }
  }
}

if (isset($_SESSION['e3_flash'])) {
  $success = (string) $_SESSION['e3_flash'];
  unset($_SESSION['e3_flash']);
}

$submission = null;
try {
  $submissionStmt = $pdo->prepare('SELECT id, original_filename FROM student_submissions WHERE project_id = ? ORDER BY id DESC LIMIT 1');
  $submissionStmt->execute([$projectId]);
  $submission = $submissionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $exception) {
  $submission = null;
}

$fieldValue = static function(string $key) use ($form): string {
  return array_key_exists($key, $form) && $form[$key] !== null ? (string) $form[$key] : '';
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEIT-UR-E3 Final Hearing Evaluation</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261124">
</head>
<body>
<main class="main-content" style="margin-left:0;width:100%">
  <div class="evaluation-content">
    <a class="btn ghost evaluation-back" href="projects.php">← Back to Assigned Projects</a>
    <header class="main-header"><div class="main-header-text"><h1>CEIT-UR-E3 Final Hearing Evaluation</h1><p>PART 1: GRADING RUBRIC — 100 POINTS</p></div></header>
    <?php if ($success): ?><div class="evaluation-notice success" role="status"><strong><?=esc($success)?></strong></div><?php endif; ?>
    <?php if ($errors): ?><div class="evaluation-notice error" role="alert"><strong>Please review:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=esc($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="evaluation-info evaluation-card">
      <div class="evaluation-card-heading"><h2>Project Information</h2></div>
      <dl class="evaluation-meta">
        <div><dt>Project title</dt><dd><?=esc($project['title'])?></dd></div>
        <div><dt>Group</dt><dd><?=esc($project['group_name'] ?? 'No group')?></dd></div>
        <div><dt>Adviser</dt><dd><?=esc($project['adviser'] ?? 'Not assigned')?></dd></div>
      </dl>
      <?php if ($submission): ?><a class="evaluation-link" href="view_submission.php?id=<?=esc($submission['id'])?>" target="_blank">Open submitted capstone paper: <?=esc($submission['original_filename'])?></a><?php endif; ?>
    </section>
    <form method="post" class="evaluation-form evaluation-card">
      <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
      <div class="criteria-grid evaluation-fields">
      <?php $lastCategory = ''; foreach ($criteria as $code => $criterion): ?>
        <?php if ($lastCategory !== $criterion['category']): $lastCategory = $criterion['category']; ?>
          <h2 style="grid-column:1/-1"><?=esc($criterion['category'])?></h2>
        <?php endif; ?>
        <label class="evaluation-field" for="score-<?=esc($criterion['key'])?>">
          <span class="evaluation-field-label"><?=esc($code)?> — <?=esc($criterion['label'])?><span class="required-mark">*</span></span>
          <span class="evaluation-field-hint">Maximum <?=esc($criterion['maximum'])?> points</span>
          <input id="score-<?=esc($criterion['key'])?>" type="number" name="<?=esc($criterion['key'])?>" min="0" max="<?=esc($criterion['maximum'])?>" step="0.01" value="<?=esc($fieldValue($criterion['key']))?>" <?=($existing && $existing['status'] === 'submitted') ? 'disabled' : 'required'?>>
        </label>
      <?php endforeach; ?>
      </div>
      <label class="evaluation-field evaluation-remarks" for="remarks"><span class="evaluation-field-label">Revisions, comments and recommendations</span><textarea id="remarks" name="remarks" rows="5" <?=($existing && $existing['status'] === 'submitted') ? 'disabled' : ''?>><?=esc($fieldValue('remarks'))?></textarea></label>
      <label class="evaluation-field evaluation-remarks" for="revisions"><span class="evaluation-field-label">Required revisions</span><textarea id="revisions" name="revisions" rows="5" <?=($existing && $existing['status'] === 'submitted') ? 'disabled' : ''?>><?=esc($fieldValue('revisions'))?></textarea></label>
      <div class="evaluation-summary"><div><strong>Total score</strong><small>Maximum 100</small></div><output id="e3-total">0.00 <span>/ 100</span></output></div>
      <div class="evaluation-actions">
        <?php if (!$existing || $existing['status'] !== 'submitted'): ?>
          <button class="btn ghost" type="submit" name="action" value="draft">Save Progress</button>
          <button class="btn primary" type="submit" name="action" value="submit">Submit E3 Evaluation</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</main>
<script>
(() => {
  const form = document.querySelector('.evaluation-form');
  const output = document.getElementById('e3-total');
  const update = () => {
    let total = 0;
    form.querySelectorAll('input[type="number"]').forEach(input => {
      const value = parseFloat(input.value);
      if (Number.isFinite(value)) total += value;
    });
    output.innerHTML = total.toFixed(2) + ' <span>/ 100</span>';
  };
  form.addEventListener('input', update);
  update();
})();
</script>
</body>
</html>
