<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/e3.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'dean') {
  header('Location: ../index.php');
  exit;
}
$projectId = (int) ($_GET['project_id'] ?? 0);
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$errors = [];
$success = '';
if ($project) {
  $consolidated = e3_consolidated($pdo, $projectId);
  $calculated = $consolidated['calculated_score'];
  $individualStmt = $pdo->prepare('SELECT e.*, u.full_name, u.email FROM e3_evaluations e INNER JOIN users u ON u.id = e.evaluator_id WHERE e.project_id = ? AND e.status = ? ORDER BY u.full_name');
  $individualStmt->execute([$projectId, 'submitted']);
  $individualEvaluations = $individualStmt->fetchAll(PDO::FETCH_ASSOC);
  $existingStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? LIMIT 1');
  $existingStmt->execute([$projectId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
      $errors[] = 'Your session could not be verified.';
    } elseif ($existing && $existing['published_at'] !== null) {
      $errors[] = 'This verdict has already been published and cannot be changed.';
    } elseif ($calculated === null) {
      $errors[] = 'A final score cannot be finalized until submitted evaluator sheets are available.';
    } else {
      $finalScore = (float) ($_POST['final_score'] ?? $calculated);
      $reason = trim((string) ($_POST['departure_reason'] ?? ''));
      $authority = trim((string) ($_POST['departure_authority'] ?? ''));
      $revisions = trim((string) ($_POST['revisions'] ?? ''));
      $comments = trim((string) ($_POST['chairperson_comments'] ?? ''));
      $signatureName = trim((string) ($_POST['signature_name'] ?? ($_SESSION['user']['full_name'] ?? '')));
      $signatureTitle = trim((string) ($_POST['signature_title'] ?? 'IT Chairperson'));
      $hasDeparture = abs($finalScore - $calculated) > 0.009;
      if (!is_finite($finalScore) || $finalScore < 0 || $finalScore > 100) {
        $errors[] = 'Final score must be from 0 to 100.';
      }
      if ($hasDeparture && ($reason === '' || $authority === '')) {
        $errors[] = 'A departure from the calculated score requires a reason and authority.';
      }
      if (!$errors) {
        $verdict = e3_verdict($finalScore);
        try {
          $pdo->beginTransaction();
          $chairpersonId = (int) $_SESSION['user']['id'];
          if ($existing) {
            $stmt = $pdo->prepare('UPDATE e3_final_verdicts SET calculated_score=?, final_score=?, verdict_label=?, has_departure=?, departure_reason=?, departure_authority=?, revisions=?, chairperson_comments=?, signature_name=?, signature_title=?, published_at=? WHERE id=?');
            $stmt->execute([$calculated, round($finalScore, 2), $verdict['label'], $hasDeparture ? 1 : 0, $reason ?: null, $authority ?: null, $revisions ?: null, $comments ?: null, $signatureName ?: null, $signatureTitle ?: null, date('Y-m-d H:i:s'), (int) $existing['id']]);
          } else {
            $stmt = $pdo->prepare('INSERT INTO e3_final_verdicts (project_id,chairperson_id,calculated_score,final_score,verdict_label,has_departure,departure_reason,departure_authority,revisions,chairperson_comments,signature_name,signature_title,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$projectId, $chairpersonId, $calculated, round($finalScore, 2), $verdict['label'], $hasDeparture ? 1 : 0, $reason ?: null, $authority ?: null, $revisions ?: null, $comments ?: null, $signatureName ?: null, $signatureTitle ?: null, date('Y-m-d H:i:s')]);
          }
          $pdo->prepare('UPDATE projects SET overall_score=?, overall_rating=? WHERE id=?')->execute([round($finalScore, 2), $verdict['label'], $projectId]);
          $studentId = (int) ($project['student_id'] ?? 0);
          if ($studentId > 0) {
            $pdo->prepare('INSERT INTO notifications (user_id,title,message,link,is_read,created_at) VALUES (?,?,?,?,0,NOW())')->execute([
              $studentId,
              'Final CEIT-UR-E3 verdict published',
              'Your final approved and published E3 verdict is now available.',
              'students/e3_result.php',
            ]);
          }
          $pdo->commit();
          header('Location: e3_verdict.php?project_id=' . $projectId . '&published=1');
          exit;
        } catch (Throwable $exception) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          error_log('[e3] Verdict publish failed: ' . $exception->getMessage());
          $errors[] = 'The final verdict could not be published.';
        }
      }
    }
  }
  $existingStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? LIMIT 1');
  $existingStmt->execute([$projectId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  $success = isset($_GET['published']) ? 'Final E3 verdict published to the student.' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IT Chairperson E3 Verdict</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261124">
</head>
<body>
<main class="main-content" style="margin-left:0;width:100%">
  <div class="evaluation-content">
    <header class="main-header"><div class="main-header-text"><h1>IT Chairperson E3 Verdict</h1><p><?=esc($project['title'] ?? 'Project not found')?></p></div></header>
    <?php if ($success): ?><div class="evaluation-notice success"><strong><?=esc($success)?></strong></div><?php endif; ?>
    <?php if ($errors): ?><div class="evaluation-notice error"><strong>Please review:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=esc($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$project): ?><section class="evaluation-card"><p>Project not found.</p></section><?php else: ?>
    <section class="evaluation-card">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 1</span><h2>GRADING RUBRIC — 100 POINTS</h2></div>
      <table class="data-table"><thead><tr><th>Item</th><th>Criterion</th><th>Maximum</th><th>Consolidated Score</th></tr></thead><tbody>
      <?php foreach (e3_criteria() as $code => $criterion): ?>
        <tr><td><?=esc($code)?></td><td><?=esc($criterion['label'])?></td><td><?=esc($criterion['maximum'])?></td><td><?= $consolidated['criteria'][$code] === null ? 'Pending' : number_format($consolidated['criteria'][$code], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <p><strong>Submitted evaluator sheets:</strong> <?=esc($consolidated['evaluator_count'])?></p>
      <p><strong>Calculated score:</strong> <?= $consolidated['calculated_score'] === null ? 'Pending' : number_format($consolidated['calculated_score'], 2) ?> / 100</p>
      <?php if (!empty($individualEvaluations)): ?>
        <h3 style="margin-top:20px">Confidential Individual E3 Evaluations</h3>
        <table class="data-table"><thead><tr><th>Evaluator</th><th>Status</th><th>Total</th><th>Remarks / Revisions</th></tr></thead><tbody>
        <?php foreach ($individualEvaluations as $individual): ?>
          <tr><td><?=esc($individual['full_name'])?></td><td><?=esc($individual['status'])?></td><td><?=number_format((float) $individual['total_score'], 2)?> / 100</td><td><?=esc(trim((string) ($individual['revisions'] ?: $individual['remarks'] ?: 'None')))?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>
    <section class="evaluation-card">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 2</span><h2>FINAL SCORE AND VERDICT</h2></div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
        <label class="evaluation-field"><span class="evaluation-field-label">Final score</span><input type="number" name="final_score" min="0" max="100" step="0.01" value="<?=esc($existing['final_score'] ?? (string) ($consolidated['calculated_score'] ?? ''))?>" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : 'required'?>></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Reason for permitted departure</span><textarea name="departure_reason" rows="3" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : ''?>><?=esc($existing['departure_reason'] ?? '')?></textarea></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Authority for departure</span><textarea name="departure_authority" rows="2" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : ''?>><?=esc($existing['departure_authority'] ?? '')?></textarea></label>
    </section>
    <section class="evaluation-card">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 3</span><h2>REVISIONS, COMMENTS AND RECOMMENDATIONS</h2></div>
        <label class="evaluation-field"><span class="evaluation-field-label">Required corrections and revisions</span><textarea name="revisions" rows="5" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : ''?>><?=esc($existing['revisions'] ?? '')?></textarea></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Chairperson comments</span><textarea name="chairperson_comments" rows="4" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : ''?>><?=esc($existing['chairperson_comments'] ?? '')?></textarea></label>
    </section>
    <section class="evaluation-card">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 4</span><h2>PANEL MEMBER'S SIGNATURE</h2></div>
        <label class="evaluation-field"><span class="evaluation-field-label">Authorized name</span><input type="text" name="signature_name" value="<?=esc($existing['signature_name'] ?? ($_SESSION['user']['full_name'] ?? ''))?>" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : ''?>></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Title / authority</span><input type="text" name="signature_title" value="<?=esc($existing['signature_title'] ?? 'IT Chairperson')?>" <?=($existing && $existing['published_at'] !== null) ? 'disabled' : ''?>></label>
        <?php if (!$existing || $existing['published_at'] === null): ?><div class="evaluation-actions"><button class="btn primary" type="submit">Publish Final E3 Verdict</button></div><?php else: ?><p><strong>Published:</strong> <?=esc($existing['published_at'])?></p><?php endif; ?>
      </form>
    </section>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
