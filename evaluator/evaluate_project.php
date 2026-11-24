<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/submissions.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }

$projectId = (int) ($_GET['project_id'] ?? 0);
$evaluatorId = (int) $_SESSION['user']['id'];
$stmt = $pdo->prepare('SELECT p.*, a.status AS assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id=p.id WHERE p.id=? AND a.evaluator_id=? LIMIT 1');
$stmt->execute([$projectId, $evaluatorId]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$project) { header('Location: projects.php?evaluation_error=not_assigned'); exit; }

$submission = latest_stage_submission($pdo, $projectId, 'proposal');
if (!$submission || !in_array($submission['status'], ['submitted', 'under_evaluation'], true)
    || empty($submission['stored_filename'])
    || !is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $submission['stored_filename'])) {
    header('Location: evaluate_selection.php?project_id=' . $projectId . '&evaluation_error=proposal_not_submitted');
    exit;
}
if ($submission['status'] === 'submitted') {
    $pdo->prepare("UPDATE student_submissions SET status = 'under_evaluation' WHERE id = ? AND status = 'submitted'")->execute([(int) $submission['id']]);
    $submission['status'] = 'under_evaluation';
}

$criteria = proposal_criteria();
$latest = $pdo->prepare('SELECT * FROM evaluations_project WHERE project_id=? AND evaluator_id=? AND proposal_submission_id=? ORDER BY id DESC LIMIT 1');
$latest->execute([$projectId, $evaluatorId, (int) $submission['id']]);
$existing = $latest->fetch(PDO::FETCH_ASSOC) ?: null;
$hasRubricScores = $existing && count(array_filter(
    array_column($criteria, 'key'),
    static fn($key) => $existing[$key] !== null && $existing[$key] !== ''
)) === count($criteria);
$hasProposalData = false;
if ($existing) {
    foreach ($criteria as $criterion) {
        if ($existing[$criterion['key']] !== null || trim((string) ($existing[$criterion['comment_key']] ?? '')) !== '') {
            $hasProposalData = true;
            break;
        }
    }
}
$isLocked = $hasRubricScores && $hasProposalData && ($existing['proposal_status'] ?? 'submitted') === 'submitted';
$formValues = [];
$commentValues = [];
foreach ($criteria as $criterion) {
    $formValues[$criterion['key']] = $existing && $existing[$criterion['key']] !== null ? (string) $existing[$criterion['key']] : '';
    $commentValues[$criterion['comment_key']] = $existing ? (string) ($existing[$criterion['comment_key']] ?? '') : '';
}
$remarks = $existing ? (string) ($existing['remarks'] ?? '') : '';
$errors = [];
$success = '';
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Your session could not be verified. Refresh the page and try again.';
    } elseif ($isLocked) {
        $errors[] = 'This proposal evaluation has already been submitted and cannot be edited.';
    } elseif (!in_array($action, ['draft', 'submit'], true)) {
        $errors[] = 'Choose Save Draft or Submit Evaluation.';
    } else {
        foreach ($criteria as $criterion) {
            $formValues[$criterion['key']] = trim((string) ($_POST[$criterion['key']] ?? ''));
            $commentValues[$criterion['comment_key']] = trim((string) ($_POST[$criterion['comment_key']] ?? ''));
            if (strlen($commentValues[$criterion['comment_key']]) > 2000) {
                $errors[] = $criterion['label'] . ' comment cannot exceed 2,000 characters.';
            }
        }
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (strlen($remarks) > 1000) {
            $errors[] = 'Evaluator comments cannot exceed 1,000 characters.';
        }

        $validation = proposal_validate_scores($_POST, $action === 'submit');
        $errors = array_merge($errors, $validation['errors']);
        if (!$errors) {
            $status = $action === 'submit' ? 'submitted' : 'draft';
            $values = [];
            foreach ($criteria as $criterion) {
                $values[$criterion['key']] = $validation['scores'][$criterion['key']];
                $values[$criterion['comment_key']] = $commentValues[$criterion['comment_key']] !== ''
                    ? $commentValues[$criterion['comment_key']]
                    : null;
            }
            $values['proposal_total_score'] = $validation['total'];
            $values['proposal_evaluator_comments'] = $remarks !== '' ? $remarks : null;
            $values['proposal_status'] = $status;

            try {
                $pdo->beginTransaction();
                if ($existing) {
                    $set = [];
                    $params = [];
                    foreach ($values as $column => $value) {
                        $set[] = $column . '=?';
                        $params[] = $value;
                    }
                    $params[] = (int) $existing['id'];
                    $update = $pdo->prepare('UPDATE evaluations_project SET ' . implode(',', $set) . ' WHERE id=?');
                    $update->execute($params);
                } else {
                    $columns = array_merge(['project_id', 'evaluator_id', 'proposal_submission_id'], array_keys($values), ['created_at']);
                    $placeholders = array_fill(0, count($columns) - 1, '?');
                    $insert = $pdo->prepare(
                        'INSERT INTO evaluations_project (' . implode(',', $columns) . ') VALUES (' .
                        implode(',', $placeholders) . ',NOW())'
                    );
                    $insert->execute(array_merge([$projectId, $evaluatorId, (int) $submission['id']], array_values($values)));
                }

                $pdo->commit();
                $_SESSION['proposal_flash'] = $status === 'submitted'
                    ? 'Proposal evaluation submitted.'
                    : 'Proposal evaluation draft saved.';
                header('Location: evaluate_project.php?project_id=' . $projectId);
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'The proposal evaluation could not be saved.';
                error_log('[proposal] ' . $exception->getMessage());
            }
        }
    }
}

if (isset($_SESSION['proposal_flash'])) {
    $success = (string) $_SESSION['proposal_flash'];
    unset($_SESSION['proposal_flash']);
}
$validation = proposal_validate_scores($formValues, false);
$recommended = $validation['complete'] ? proposal_verdict_from_score($validation['percentage']) : null;
$verdictBandsJson = json_encode(proposal_verdict_bands(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$name = trim((string) ($_SESSION['user']['full_name'] ?? 'Evaluator'));
$email = trim((string) ($_SESSION['user']['email'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Proposal Evaluation</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261124">
  <link rel="stylesheet" href="evaluator-content.css?v=20261124">
  <link rel="stylesheet" href="evaluator-typography.css?v=20261124">
  <link rel="stylesheet" href="evaluate_selection.css?v=20261124">
  <link rel="stylesheet" href="evaluator-proposal.css?v=20261124">
</head>
<body class="evaluator-content-page">
<div class="dashboard-layout evaluator-layout">
  <aside class="sidebar evaluator-sidebar" id="evaluator-sidebar">
    <div class="sidebar-header"><img class="logo-icon" src="../images_bgnd/CSUCC%20LOGO.png" alt=""><div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div></div>
    <ul class="sidebar-nav"><li class="nav-section-label">Workspace</li><li><a href="dashboard.php"><span class="nav-icon">▦</span><span>Dashboard</span></a></li><li class="nav-section-label">Evaluation</li><li><a class="active" href="projects.php"><span class="nav-icon">▰</span><span>Evaluate Projects</span></a></li><li><a href="evaluation_history.php"><span class="nav-icon">◷</span><span>Evaluation History</span></a></li><li class="nav-section-label">Account</li><li><a href="profile.php"><span class="nav-icon">◎</span><span>Profile</span></a></li><li><a href="../logout.php"><span class="nav-icon">↪</span><span>Logout</span></a></li></ul>
    <div class="sidebar-user evaluator-sidebar-user"><div class="sidebar-user-avatar"><?=esc(strtoupper(substr($name, 0, 1)))?></div><div class="sidebar-user-info"><h5><?=esc($name)?></h5><p><?=esc($email)?></p></div></div>
  </aside>
  <button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
  <main class="main-content e3-main proposal-evaluation-main">
    <div class="evaluator-topbar"><button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu" aria-controls="evaluator-sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="evaluator-topbar-end"><div class="account-avatar"><?=esc(strtoupper(substr($name, 0, 1)))?></div><span><?=esc($name)?></span></div></div>
    <a class="selection-back" href="evaluate_selection.php?project_id=<?=$projectId?>">← Back to Evaluation Type</a>
    <header class="main-header proposal-page-header"><div class="main-header-text"><span class="page-eyebrow">Proposal stage</span><h1><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg>Proposal Evaluation</h1><p><?=esc($project['title'])?></p><a class="e3-btn secondary" href="view_submission.php?id=<?=esc($submission['id'])?>&amp;stage=proposal" target="_blank" rel="noopener">Review submitted PDF: <?=esc($submission['original_filename'])?></a></div></header>
    <?php if ($success): ?><div class="e3-alert success" role="status"><?=esc($success)?></div><?php endif; ?>
    <?php if ($errors): ?><div class="e3-alert error" role="alert"><strong>Please review:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=esc($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($isLocked): ?><div class="proposal-submitted-note" role="status">Your proposal evaluation has been submitted. Scores and comments are read-only while the IT Chairperson reviews the panel results.</div><?php endif; ?>

    <form method="post" class="proposal-scoring-form" id="proposal-scoring-form">
      <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
      <div class="proposal-scoring-grid">
        <section class="proposal-rubric-card" aria-labelledby="proposal-rubric-title">
          <div class="proposal-section-heading"><span class="proposal-icon-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/></svg></span><h2 id="proposal-rubric-title">Scoring Rubric</h2></div>
          <div class="proposal-rubric-head"><span>Criteria</span><span>Description</span><span>Score</span><span>Max Points</span><span>Comments / Suggestions</span></div>
          <div class="proposal-rubric-body">
          <?php foreach ($criteria as $criterion): ?>
            <div class="proposal-criterion-row">
              <div class="proposal-criterion-name"><span class="proposal-icon-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><?=$criterion['icon']?></svg></span><strong><?=esc($criterion['label'])?></strong></div>
              <p class="proposal-criterion-description"><?=esc($criterion['description'])?></p>
              <label class="proposal-score-input"><span class="proposal-mobile-label">Score</span><input type="number" name="<?=esc($criterion['key'])?>" min="0" max="<?=esc($criterion['maximum'])?>" step="0.01" value="<?=esc($formValues[$criterion['key']])?>" <?=($isLocked ? 'disabled' : '')?> <?=(!$isLocked ? 'required' : '')?> aria-label="<?=esc($criterion['label'])?> score"></label>
              <span class="proposal-maximum"><span class="proposal-mobile-label">Max</span>/ <?=esc($criterion['maximum'])?></span>
              <label class="proposal-criterion-comment"><span class="proposal-mobile-label">Comments / Suggestions</span><textarea name="<?=esc($criterion['comment_key'])?>" rows="2" maxlength="2000" placeholder="Add criterion feedback..." <?=($isLocked ? 'disabled' : '')?>><?=esc($commentValues[$criterion['comment_key']])?></textarea></label>
            </div>
          <?php endforeach; ?>
          </div>
          <div class="proposal-total-strip"><strong><span class="proposal-icon-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4ZM7 6H4v2a4 4 0 0 0 4 4m9-6h3v2a4 4 0 0 1-4 4"/></svg></span>Total Score</strong><span><b id="proposal-total"><?=number_format($validation['total'], 2)?></b> / 120</span><strong class="proposal-total-percent" id="proposal-percent"><?=number_format($validation['percentage'], 2)?>%</strong></div>
        </section>

        <aside class="proposal-summary-column">
          <section class="proposal-summary-card" aria-labelledby="proposal-summary-title">
            <div class="proposal-section-heading"><span class="proposal-icon-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/></svg></span><h2 id="proposal-summary-title">Evaluation Summary</h2></div>
            <div class="proposal-summary-content"><div class="proposal-score-ring" id="proposal-score-ring" style="--score-angle: <?=round($validation['percentage'] * 3.6, 2)?>deg"><strong id="proposal-ring-percent"><?=number_format($validation['percentage'], 2)?>%</strong><span>Total Score</span></div><div class="proposal-recommendation"><span>Recommended Verdict</span><strong id="proposal-recommended"><?=esc($recommended['label'] ?? 'Complete all criteria')?></strong><p id="proposal-recommendation-description"><?=esc($recommended['description'] ?? 'Score each criterion to calculate the recommended proposal result.')?></p></div></div>
          </section>
          <section class="proposal-verdict-card" aria-labelledby="proposal-verdict-title">
            <div class="proposal-section-heading"><span class="proposal-icon-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></svg></span><h2 id="proposal-verdict-title">Proposal Verdict</h2></div>
            <p class="proposal-verdict-guidance">The verdict is calculated from the score and cannot be selected manually.</p>
            <div class="proposal-verdict-options" id="proposal-verdict-options">
              <?php foreach ([
                  'Approved' => ['Description' => 'Proposal accepted.', 'icon' => '<path d="m5 12 4 4L19 6"/>'],
                  'Approved with Minor Revision' => ['Description' => 'Small corrections required.', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>'],
                  'Approved with Major Revision' => ['Description' => 'Major revisions required.', 'icon' => '<path d="M10.3 3.9 2.6 17.2A2 2 0 0 0 4.3 20h15.4a2 2 0 0 0 1.7-2.8L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>'],
                  'Not Approved' => ['Description' => 'Proposal must be revised and re-evaluated.', 'icon' => '<path d="m18 6-12 12M6 6l12 12"/>'],
              ] as $verdictLabel => $verdictData): ?>
                <div class="proposal-verdict-option" data-verdict="<?=esc($verdictLabel)?>" aria-current="false"><span class="proposal-verdict-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><?=$verdictData['icon']?></svg></span><span><strong><?=esc($verdictLabel)?></strong><small><?=esc($verdictData['Description'])?></small></span></div>
              <?php endforeach; ?>
            </div>
          </section>
        </aside>
      </div>

      <section class="proposal-comments-card">
        <label for="proposal-comments"><span class="proposal-icon-circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/></svg></span>Evaluator Comments / Recommendations</label>
        <textarea id="proposal-comments" name="remarks" rows="3" maxlength="1000" placeholder="Add overall feedback and required revisions..." <?=($isLocked ? 'disabled' : '')?>><?=esc($remarks)?></textarea>
        <div class="proposal-comments-footer"><span id="proposal-comment-count"><?=strlen($remarks)?> / 1000</span><div class="proposal-form-actions"><button class="e3-btn secondary" type="submit" name="action" value="draft" <?=($isLocked ? 'disabled' : '')?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>Save Draft</button><button class="e3-btn primary" type="submit" name="action" value="submit" <?=($isLocked ? 'disabled' : '')?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>Submit Evaluation</button></div></div>
      </section>
    </form>
  </main>
</div>
<script src="evaluator-content.js?v=20261124" defer></script>
<script>
(() => {
  const form = document.getElementById('proposal-scoring-form');
  if (!form) return;
  const inputs = Array.from(form.querySelectorAll('.proposal-score-input input'));
  const total = document.getElementById('proposal-total');
  const percent = document.getElementById('proposal-percent');
  const ringPercent = document.getElementById('proposal-ring-percent');
  const ring = document.getElementById('proposal-score-ring');
  const recommended = document.getElementById('proposal-recommended');
  const explanation = document.getElementById('proposal-recommendation-description');
  const options = Array.from(document.querySelectorAll('.proposal-verdict-option'));
  const comment = document.getElementById('proposal-comments');
  const commentCount = document.getElementById('proposal-comment-count');
  const verdictBands = <?=$verdictBandsJson ?: '[]'?>;
  const verdictFor = score => {
    const roundedScore = Math.floor(Number(score.toFixed(2)) + 0.5);
    const band = verdictBands.find(item => roundedScore >= item.minimum && roundedScore <= item.maximum);
    return band ? band.label : null;
  };
  const explanationFor = verdict => ({
    'Approved': 'The proposal meets the required standard and may proceed.',
    'Approved with Minor Revision': 'Small corrections are required before proceeding.',
    'Approved with Major Revision': 'Significant revisions are required before proceeding.',
    'Not Approved': 'The proposal requires further work and re-evaluation.'
  })[verdict];
  const update = () => {
    let sum = 0;
    let complete = true;
    inputs.forEach(input => {
      if (input.value === '') {
        complete = false;
        return;
      }
      const value = Number(input.value);
      if (!Number.isFinite(value) || value < 0 || value > Number(input.max)) {
        complete = false;
        return;
      }
      sum += value;
    });
    const percentage = sum / 120 * 100;
    total.textContent = sum.toFixed(2);
    percent.textContent = percentage.toFixed(2) + '%';
    ringPercent.textContent = percentage.toFixed(2) + '%';
    ring.style.setProperty('--score-angle', (percentage * 3.6) + 'deg');
    const verdict = complete ? verdictFor(percentage) : null;
    recommended.textContent = verdict || 'Complete all criteria';
    explanation.textContent = verdict ? explanationFor(verdict) : 'Score each criterion to calculate the recommended proposal result.';
    options.forEach(option => {
      const active = verdict && option.dataset.verdict === verdict;
      option.classList.toggle('is-recommended', Boolean(active));
      option.setAttribute('aria-current', active ? 'true' : 'false');
    });
  };
  form.addEventListener('input', update);
  if (comment && commentCount) {
    comment.addEventListener('input', () => { commentCount.textContent = comment.value.length + ' / 1000'; });
  }
  update();
})();
</script>
</body>
</html>
