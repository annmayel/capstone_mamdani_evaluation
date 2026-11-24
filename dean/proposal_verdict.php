<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_dean_access();

$projectId = (int) ($_GET['project_id'] ?? 0);
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$errors = [];
$success = isset($_GET['confirmed']) ? 'Official proposal verdict confirmed.' : '';
$criteria = proposal_criteria();
$evaluations = [];
$criterionAverages = array_fill_keys(array_keys($criteria), 0.0);
$averageTotal = null;
$recommendation = null;
$published = $project && !empty($project['proposal_confirmed_at']);

if ($project) {
    $scoreColumns = array_column($criteria, 'key');
    $scoreConditions = implode(' AND ', array_map(static fn($column) => $column . ' IS NOT NULL', $scoreColumns));
    $individualStmt = $pdo->prepare(
        'SELECT e.*, u.full_name AS evaluator_name
         FROM evaluations_project e
         INNER JOIN (
             SELECT evaluator_id, MAX(id) AS latest_id
             FROM evaluations_project
             WHERE project_id=? AND proposal_status=\'submitted\' AND ' . $scoreConditions . '
             GROUP BY evaluator_id
         ) latest ON latest.latest_id=e.id
         INNER JOIN users u ON u.id=e.evaluator_id
         ORDER BY u.full_name'
    );
    $individualStmt->execute([$projectId]);
    foreach ($individualStmt->fetchAll(PDO::FETCH_ASSOC) as $evaluation) {
        $validation = proposal_validate_scores($evaluation, true);
        if ($validation['errors']) {
            continue;
        }
        $evaluation['raw_score'] = $validation['total'];
        $evaluation['percentage'] = $validation['percentage'];
        $evaluation['recommended'] = proposal_verdict_from_score($validation['percentage']);
        foreach (array_keys($criteria) as $criterionKey) {
            $criterionAverages[$criterionKey] += (float) $evaluation[$criteria[$criterionKey]['key']];
        }
        $evaluations[] = $evaluation;
    }

    if ($evaluations) {
        foreach ($criterionAverages as $criterionKey => $sum) {
            $criterionAverages[$criterionKey] = round($sum / count($evaluations), 2);
        }
        $averageTotal = round(array_sum($criterionAverages), 2);
        $recommendation = proposal_verdict_from_score(round(($averageTotal / 120) * 100, 2));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['csrf'] ?? '')) {
            $errors[] = 'Your session could not be verified. Refresh the page and try again.';
        } elseif ($published) {
            $errors[] = 'The official proposal verdict has already been confirmed.';
        } elseif (!$recommendation) {
            $errors[] = 'At least one complete, submitted proposal rubric is required before confirmation.';
        } else {
            $revisions = trim((string) ($_POST['proposal_required_revisions'] ?? ''));
            $comments = trim((string) ($_POST['proposal_chairperson_comments'] ?? ''));
            if (strlen($revisions) > 4000) {
                $errors[] = 'Required revisions cannot exceed 4,000 characters.';
            }
            if (strlen($comments) > 4000) {
                $errors[] = 'Chairperson comments cannot exceed 4,000 characters.';
            }
            if ($recommendation['label'] !== 'Approved' && $revisions === '') {
                $errors[] = 'Record the required revisions before confirming this verdict.';
            }
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $confirmStmt = $pdo->prepare(
                        'UPDATE projects
                         SET proposal_official_score=?,proposal_official_verdict=?,proposal_required_revisions=?,
                             proposal_chairperson_comments=?,proposal_chairperson_id=?,proposal_confirmed_at=?
                         WHERE id=? AND proposal_confirmed_at IS NULL'
                    );
                    $confirmStmt->execute([
                        $recommendation['percentage'],
                        $recommendation['label'],
                        $revisions !== '' ? $revisions : null,
                        $comments !== '' ? $comments : null,
                        (int) $_SESSION['user']['id'],
                        date('Y-m-d H:i:s'),
                        $projectId,
                    ]);
                    if ($confirmStmt->rowCount() !== 1) {
                        throw new RuntimeException('Proposal verdict was already confirmed or the project changed.');
                    }
                    $pdo->commit();
                    header('Location: proposal_verdict.php?project_id=' . $projectId . '&confirmed=1');
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[proposal verdict] ' . $exception->getMessage());
                    $errors[] = 'The official proposal verdict could not be confirmed.';
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Proposal Verdict Review</title><link rel="stylesheet" href="../assets/style.css?v=20261124"><link rel="stylesheet" href="proposal-verdict.css?v=20261124"></head>
<body class="proposal-verdict-page">
<main class="proposal-verdict-shell">
  <a class="proposal-review-back" href="submissions.php">← Back to Capstone Submissions</a>
  <header><span>PROPOSAL STAGE · IT CHAIRPERSON REVIEW</span><h1>Proposal Verdict Review</h1><p><?=esc($project['title'] ?? 'Project not found')?></p></header>
  <?php if ($success): ?><div class="proposal-review-notice success" role="status"><?=esc($success)?></div><?php endif; ?>
  <?php if ($errors): ?><div class="proposal-review-notice error" role="alert"><strong>Please review:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=esc($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if (!$project): ?>
    <section class="proposal-review-card"><p>Project not found.</p></section>
  <?php else: ?>
    <section class="proposal-review-card">
      <h2>Panel Score Summary</h2>
      <?php if ($evaluations): ?>
        <div class="proposal-review-summary"><div><small>Panel Total</small><strong><?=number_format((float) $averageTotal, 2)?> / 120</strong></div><div><small>Panel Percentage</small><strong><?=number_format((float) $recommendation['percentage'], 2)?>%</strong></div><div><small>Recommended Verdict</small><strong><?=esc($recommendation['label'])?></strong><p><?=esc($recommendation['description'])?></p></div></div>
        <div class="proposal-review-table-wrap"><table class="proposal-review-table"><thead><tr><th>Criteria</th><th>Panel Average</th><th>Maximum</th></tr></thead><tbody>
          <?php foreach ($criteria as $criterionKey => $criterion): ?><tr><td><?=esc($criterion['label'])?></td><td><?=number_format($criterionAverages[$criterionKey], 2)?></td><td><?=esc($criterion['maximum'])?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
        <p class="proposal-review-empty">No complete proposal scoring rubrics have been submitted yet.</p>
      <?php endif; ?>
    </section>

    <?php foreach ($evaluations as $evaluation): ?>
      <section class="proposal-review-card">
        <h2><?=esc($evaluation['evaluator_name'])?> <small>· <?=number_format($evaluation['raw_score'], 2)?> / 120 · <?=number_format($evaluation['percentage'], 2)?>%</small></h2>
        <p class="proposal-review-computed">Computed recommendation: <strong><?=esc($evaluation['recommended']['label'])?></strong></p>
        <div class="proposal-review-detail-grid"><?php foreach ($criteria as $criterion): ?><article><strong><?=esc($criterion['label'])?> <span><?=number_format((float) $evaluation[$criterion['key']], 2)?> / <?=esc($criterion['maximum'])?></span></strong><p><?=nl2br(esc((string) ($evaluation[$criterion['comment_key']] ?: 'No criterion comment.')))?></p></article><?php endforeach; ?></div>
        <div class="proposal-review-overall"><strong>Evaluator Recommendations</strong><p><?=nl2br(esc((string) ($evaluation['proposal_evaluator_comments'] ?: 'No overall recommendations.')))?></p></div>
      </section>
    <?php endforeach; ?>

    <?php if ($published): ?>
      <section class="proposal-review-card proposal-official-result"><span>OFFICIAL PROPOSAL RESULT</span><h2><?=esc($project['proposal_official_verdict'])?></h2><p><?=number_format((float) $project['proposal_official_score'], 2)?>% · Confirmed <?=esc($project['proposal_confirmed_at'])?></p><?php if (!empty($project['proposal_required_revisions'])): ?><h3>Required Revisions</h3><p><?=nl2br(esc($project['proposal_required_revisions']))?></p><?php endif; ?><?php if (!empty($project['proposal_chairperson_comments'])): ?><h3>Chairperson Comments</h3><p><?=nl2br(esc($project['proposal_chairperson_comments']))?></p><?php endif; ?></section>
    <?php else: ?>
      <form method="post" class="proposal-review-card proposal-confirm-form">
        <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
        <h2>Confirm Official Proposal Verdict</h2>
        <p>The official verdict is fixed to the calculated recommendation above; it cannot be changed to another category here.</p>
        <label><span>Required revisions</span><textarea name="proposal_required_revisions" rows="4" maxlength="4000" <?=($recommendation && $recommendation['label'] === 'Approved' ? '' : 'required')?>><?=esc((string) ($_POST['proposal_required_revisions'] ?? ''))?></textarea></label>
        <label><span>IT Chairperson comments</span><textarea name="proposal_chairperson_comments" rows="3" maxlength="4000"><?=esc((string) ($_POST['proposal_chairperson_comments'] ?? ''))?></textarea></label>
        <button type="submit" class="btn primary" <?=(!$recommendation ? 'disabled' : '')?>>Confirm Official Verdict</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
