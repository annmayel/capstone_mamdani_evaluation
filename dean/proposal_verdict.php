<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/submissions.php';
require_dean_access();

$projectId = (int) ($_GET['project_id'] ?? 0);
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$errors = [];
$success = isset($_GET['confirmed']) ? 'Official proposal verdict confirmed.' : (isset($_GET['accepted']) ? 'Revised proposal accepted; the Final Paper stage is now unlocked.' : '');
$criteria = proposal_criteria();
$evaluations = [];
$criterionAverages = array_fill_keys(array_keys($criteria), 0.0);
$averageTotal = null;
$recommendation = null;
$published = $project && !empty($project['proposal_confirmed_at']);
$submission = $project ? latest_stage_submission($pdo, $projectId, 'proposal') : null;
$submissionReady = $submission && !empty($submission['stored_filename'])
    && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $submission['stored_filename']);
$canConfirmRevision = $published
    && in_array(($project['proposal_official_verdict'] ?? ''), ['REDEFENSE', 'FAILED'], true)
    && $submissionReady
    && in_array($submission['status'] ?? '', ['submitted', 'under_evaluation'], true)
    && (int) ($project['proposal_revision_accepted_submission_id'] ?? 0) !== (int) $submission['id']
    && (int) ($project['proposal_verdict_submission_id'] ?? 0) !== (int) $submission['id'];
$canAcceptRevisions = false;

if ($project) {
    $proposalSchemaReady = proposal_evaluation_schema_available($pdo);
    if (!$proposalSchemaReady) {
        $errors[] = 'The CEIT-T07A proposal evaluation schema is not installed. Apply migration 021 before reviewing proposal results.';
    } elseif ($submission) {
        $consolidated = proposal_consolidated($pdo, $projectId, (int) $submission['id']);
        $evaluations = $consolidated['evaluations'];
        $criterionAverages = $consolidated['criteria'];
        $averageTotal = $consolidated['calculated_score'];
        $recommendation = $consolidated['recommendation'];
        foreach ($evaluations as &$evaluation) {
            $evaluation['evaluator_name'] = '';
        }
        unset($evaluation);
        if ($evaluations) {
            $names = $pdo->prepare('SELECT id, full_name FROM users WHERE id = ?');
            foreach ($evaluations as &$evaluation) {
                $names->execute([(int) $evaluation['evaluator_id']]);
                $evaluation['evaluator_name'] = (string) ($names->fetchColumn() ?: 'Evaluator #' . (int) $evaluation['evaluator_id']);
                $evaluation['recommended'] = proposal_verdict_from_score((float) $evaluation['total_score']);
            }
            unset($evaluation);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_revisions') {
        if (!csrf_check($_POST['csrf'] ?? '')) {
            $errors[] = 'Your session could not be verified. Refresh the page and try again.';
        } elseif (!$canAcceptRevisions) {
            $errors[] = 'Accepted proposal revisions are completed by the original panel and do not require Chairperson acceptance.';
        } else {
            try {
                $pdo->beginTransaction();
                $acceptStmt = $pdo->prepare(
                    'UPDATE projects SET proposal_revision_accepted_submission_id=?
                     WHERE id=? AND proposal_confirmed_at IS NOT NULL
                       AND proposal_official_verdict IN (?,?,?)'
                );
                $acceptStmt->execute([
                    (int) $submission['id'],
                    $projectId,
                        'PASSED/APPROVED (Minor Revision)',
                        'PASSED/APPROVED (Major Revision)',
                        'PASSED/APPROVED (No Revision)',
                ]);
                if ($acceptStmt->rowCount() !== 1) {
                    throw new RuntimeException('Proposal revisions could not be accepted.');
                }
                $pdo->prepare("UPDATE student_submissions SET status='accepted' WHERE id=?")->execute([(int) $submission['id']]);
                $pdo->commit();
                header('Location: proposal_verdict.php?project_id=' . $projectId . '&accepted=1');
                exit;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[proposal verdict] ' . $exception->getMessage());
                $errors[] = 'The revised proposal could not be accepted.';
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_check($_POST['csrf'] ?? '')) {
            $errors[] = 'Your session could not be verified. Refresh the page and try again.';
        } elseif ($published && !$canConfirmRevision) {
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
            if ($recommendation['label'] !== 'PASSED/APPROVED (No Revision)' && $revisions === '') {
                $errors[] = 'Record the required revisions before confirming this verdict.';
            }
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    if ($canConfirmRevision) {
                        $historyStmt = $pdo->prepare(
                            'INSERT INTO stage_verdict_history
                             (project_id,stage,submission_id,chairperson_id,score,verdict_label,required_revisions,chairperson_comments,confirmed_at)
                             VALUES(?,?,?,?,?,?,?,?,?)'
                        );
                        $historyStmt->execute([
                            $projectId,
                            'proposal',
                            (int) ($project['proposal_verdict_submission_id'] ?? 0) ?: null,
                            (int) ($project['proposal_chairperson_id'] ?? $_SESSION['user']['id']),
                            (float) $project['proposal_official_score'],
                            (string) $project['proposal_official_verdict'],
                            $project['proposal_required_revisions'] ?? null,
                            $project['proposal_chairperson_comments'] ?? null,
                            (string) $project['proposal_confirmed_at'],
                        ]);
                    }
                    $confirmSql = 'UPDATE projects
                         SET proposal_official_score=?,proposal_official_verdict=?,proposal_required_revisions=?,
                             proposal_chairperson_comments=?,proposal_chairperson_id=?,proposal_confirmed_at=?,
                             proposal_verdict_submission_id=?,proposal_revision_accepted_submission_id=NULL
                         WHERE id=?';
                    $confirmParams = [
                        $recommendation['percentage'],
                        $recommendation['label'],
                        $revisions !== '' ? $revisions : null,
                        $comments !== '' ? $comments : null,
                        (int) $_SESSION['user']['id'],
                        date('Y-m-d H:i:s'),
                        $submission ? (int) $submission['id'] : null,
                        $projectId,
                    ];
                    if (!$canConfirmRevision) {
                        $confirmSql .= ' AND proposal_confirmed_at IS NULL';
                    }
                    $confirmStmt = $pdo->prepare($confirmSql);
                    $confirmStmt->execute($confirmParams);
                    if ($confirmStmt->rowCount() !== 1) {
                        throw new RuntimeException('Proposal verdict was already confirmed or the project changed.');
                    }
                    $pdo->prepare("UPDATE student_submissions SET status=? WHERE id=?")->execute([
                        $recommendation['label'] === 'PASSED/APPROVED (No Revision)' && $revisions === '' ? 'approved' : 'revision_required',
                        $submission ? (int) $submission['id'] : 0,
                    ]);
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
    <?php if ($submissionReady): ?><section class="proposal-review-card"><h2>Submitted Proposal Paper</h2><p><?=esc($submission['original_filename'])?> · <?=esc($submission['created_at'])?></p><a class="btn primary" href="../evaluator/view_submission.php?id=<?=esc($submission['id'])?>&amp;stage=proposal" target="_blank" rel="noopener">Open submitted PDF</a></section><?php endif; ?>
    <section class="proposal-review-card">
      <h2>Panel Score Summary</h2>
      <?php if ($evaluations): ?>
        <div class="proposal-review-summary"><div><small>Panel Total</small><strong><?=number_format((float) $averageTotal, 2)?> / 100</strong></div><div><small>Panel Percentage</small><strong><?=number_format((float) $recommendation['percentage'], 2)?>%</strong></div><div><small>Mamdani Output</small><strong><?=number_format((float) $recommendation['fuzzy']['defuzz'], 2)?> / 100</strong></div><div><small>Recommended Verdict</small><strong><?=esc($recommendation['label'])?></strong><p><?=esc($recommendation['description'])?></p></div></div>
        <div class="proposal-review-table-wrap"><table class="proposal-review-table"><thead><tr><th>Criteria</th><th>Panel Average</th><th>Maximum</th></tr></thead><tbody>
          <?php foreach ($criteria as $criterionKey => $criterion): ?><tr><td><?=esc($criterion['label'])?></td><td><?=number_format($criterionAverages[$criterionKey], 2)?></td><td><?=esc($criterion['maximum'])?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php else: ?>
        <p class="proposal-review-empty">No complete proposal scoring rubrics have been submitted yet.</p>
      <?php endif; ?>
    </section>

    <?php foreach ($evaluations as $evaluation): ?>
      <section class="proposal-review-card">
        <h2><?=esc($evaluation['evaluator_name'])?> <small>· <?=number_format((float) $evaluation['total_score'], 2)?> / 100</small></h2>
        <p class="proposal-review-computed">Computed recommendation: <strong><?=esc($evaluation['recommended']['label'])?></strong></p>
        <div class="proposal-review-detail-grid"><?php foreach ($criteria as $criterion): ?><article><strong><?=esc($criterion['label'])?> <span><?=number_format((float) $evaluation[$criterion['key']], 2)?> / <?=esc($criterion['maximum'])?></span></strong><p><?=nl2br(esc((string) ($evaluation[$criterion['comment_key']] ?: 'No criterion comment.')))?></p></article><?php endforeach; ?></div>
        <div class="proposal-review-overall"><strong>Evaluator Recommendations</strong><p><?=nl2br(esc((string) ($evaluation['recommendations'] ?: $evaluation['overall_comments'] ?: 'No overall recommendations.')))?></p></div>
      </section>
    <?php endforeach; ?>

    <?php if ($published): ?>
      <section class="proposal-review-card proposal-official-result"><span>OFFICIAL PROPOSAL RESULT</span><h2><?=esc($project['proposal_official_verdict'])?></h2><p><?=number_format((float) $project['proposal_official_score'], 2)?>% · Confirmed <?=esc($project['proposal_confirmed_at'])?></p><?php if (!empty($project['proposal_required_revisions'])): ?><h3>Required Revisions</h3><p><?=nl2br(esc($project['proposal_required_revisions']))?></p><?php endif; ?><?php if (!empty($project['proposal_chairperson_comments'])): ?><h3>Chairperson Comments</h3><p><?=nl2br(esc($project['proposal_chairperson_comments']))?></p><?php endif; ?></section>
      <?php if ($canAcceptRevisions): ?>
        <form method="post" class="proposal-review-card proposal-confirm-form">
          <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
          <input type="hidden" name="action" value="accept_revisions">
          <h2>Accept Revised Proposal</h2>
          <p>Review the newly submitted paper above, then confirm that the required proposal revisions are complete.</p>
          <button type="submit" class="btn primary">Accept Revisions and Unlock Final Paper</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!$published || $canConfirmRevision): ?>
      <form method="post" class="proposal-review-card proposal-confirm-form">
        <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
        <h2>Confirm Official Proposal Verdict</h2>
        <p>The official verdict is fixed to the calculated recommendation above; it cannot be changed to another category here.</p>
        <label><span>Required revisions</span><textarea name="proposal_required_revisions" rows="4" maxlength="4000" <?=($recommendation && $recommendation['label'] === 'PASSED/APPROVED (No Revision)' ? '' : 'required')?>><?=esc((string) ($_POST['proposal_required_revisions'] ?? ''))?></textarea></label>
        <label><span>IT Chairperson comments</span><textarea name="proposal_chairperson_comments" rows="3" maxlength="4000"><?=esc((string) ($_POST['proposal_chairperson_comments'] ?? ''))?></textarea></label>
        <button type="submit" class="btn primary" <?=(!$recommendation ? 'disabled' : '')?>>Confirm Official Verdict</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
