<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/e3.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/stage_submission_migration.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'dean') {
  header('Location: ../index.php');
  exit;
}
$workflowSchemaState = stage_submission_schema_state($pdo);
if (!stage_submission_schema_is_ready($workflowSchemaState)) {
  http_response_code(503);
  header('Content-Type: text/plain; charset=UTF-8');
  exit('E3 verdict storage is not ready. Run php /var/www/html/bin/migrate_workflow_schema.php from the Railway application shell, then reload this page.');
}
$projectId = (int) ($_GET['project_id'] ?? 0);
if ($projectId <= 0) {
  $projects = $pdo->query(
    'SELECT p.id, p.code, p.title, p.group_name, p.members, p.academic_year, u.full_name AS student_name
     FROM projects p LEFT JOIN users u ON u.id = p.student_id
     ORDER BY p.id DESC'
  )->fetchAll(PDO::FETCH_ASSOC);
  $assignmentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM evaluator_assignments WHERE project_id = ?');
  $verdictStmt = $pdo->prepare(
    'SELECT * FROM e3_final_verdicts
     WHERE project_id = ? AND final_submission_id = ? AND published_at IS NOT NULL
     ORDER BY id DESC LIMIT 1'
  );
  $search = trim((string) ($_GET['search'] ?? ''));
  $evaluationFilter = (string) ($_GET['evaluation_status'] ?? '');
  $verdictFilter = (string) ($_GET['verdict_status'] ?? '');
  $yearFilter = (string) ($_GET['academic_year'] ?? '');
  $rows = [];
  $statusCounts = ['awaiting_final' => 0, 'awaiting_review' => 0, 'confirmed_or_revision' => 0];
  foreach ($projects as $listProject) {
    $listProjectId = (int) $listProject['id'];
    $proposal = latest_stage_submission($pdo, $listProjectId, 'proposal');
    $finalPaper = latest_stage_submission($pdo, $listProjectId, 'final');
    $summary = $finalPaper ? e3_consolidated($pdo, $listProjectId, (int) $finalPaper['id']) : null;
    $assignmentCountStmt->execute([$listProjectId]);
    $assignmentCount = (int) $assignmentCountStmt->fetchColumn();
    $official = null;
    if ($finalPaper) {
      $verdictStmt->execute([$listProjectId, (int) $finalPaper['id']]);
      $official = $verdictStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $evaluationCount = (int) ($summary['evaluator_count'] ?? 0);
    if (!$finalPaper) {
      $evaluationStatus = 'Awaiting Final Paper';
    } elseif ($evaluationCount === 0) {
      $evaluationStatus = 'Awaiting Evaluators';
    } elseif ($assignmentCount > $evaluationCount) {
      $evaluationStatus = 'Evaluation In Progress';
    } else {
      $evaluationStatus = 'Awaiting Chairperson Review';
    }
    if ($official) {
      $verdictStatus = $official['verdict_label'] === 'Passed' ? 'Officially Confirmed' : 'Revision Required';
    } elseif ($evaluationStatus === 'Awaiting Chairperson Review') {
      $verdictStatus = 'Awaiting Chairperson Review';
    } else {
      $verdictStatus = 'Not Confirmed';
    }
    if (!$finalPaper) {
      $statusCounts['awaiting_final']++;
    }
    if ($verdictStatus === 'Awaiting Chairperson Review') {
      $statusCounts['awaiting_review']++;
    }
    if (in_array($verdictStatus, ['Officially Confirmed', 'Revision Required'], true)) {
      $statusCounts['confirmed_or_revision']++;
    }

    $searchable = implode(' ', [
      (string) $listProject['id'],
      (string) ($listProject['code'] ?? ''),
      (string) ($listProject['title'] ?? ''),
      (string) ($listProject['group_name'] ?? ''),
      (string) ($listProject['members'] ?? ''),
      (string) ($listProject['student_name'] ?? ''),
    ]);
    if ($search !== '' && stripos($searchable, $search) === false) {
      continue;
    }
    if ($yearFilter !== '' && (string) ($listProject['academic_year'] ?? '') !== $yearFilter) {
      continue;
    }
    if ($evaluationFilter !== '' && $evaluationStatus !== $evaluationFilter) {
      continue;
    }
    if ($verdictFilter !== '' && $verdictStatus !== $verdictFilter) {
      continue;
    }

    $listProject['proposal_submission'] = $proposal;
    $listProject['final_submission'] = $finalPaper;
    $listProject['summary'] = $summary;
    $listProject['evaluation_count'] = $evaluationCount;
    $listProject['assignment_count'] = $assignmentCount;
    $listProject['evaluation_status'] = $evaluationStatus;
    $listProject['verdict_status'] = $verdictStatus;
    $listProject['official'] = $official;
    $rows[] = $listProject;
  }
  $academicYears = array_values(array_unique(array_filter(array_map(
    static fn($project) => (string) ($project['academic_year'] ?? ''),
    $projects
  ))));
  sort($academicYears);
  ?>
  <!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IT Chairperson E3 Verdict Management</title>
    <link rel="stylesheet" href="../assets/style.css?v=20261124">
    <link rel="stylesheet" href="e3-verdict.css?v=20261124">
  </head>
  <body>
  <main class="main-content e3-verdict-main" style="margin-left:0;width:100%">
    <div class="evaluation-content">
      <a class="btn ghost evaluation-back" href="dashboard.php">← Back to Chairperson Dashboard</a>
      <header class="main-header"><div class="main-header-text"><h1>E3 Verdict Management</h1><p>Review Final Hearing evaluations and confirm the official verdict.</p></div></header>
      <section class="e3-verdict-summary">
        <article><span>Total Projects</span><strong><?=count($projects)?></strong></article>
        <article><span>Awaiting Final Paper</span><strong><?=$statusCounts['awaiting_final']?></strong></article>
        <article><span>Awaiting Chairperson Review</span><strong><?=$statusCounts['awaiting_review']?></strong></article>
        <article><span>Officially Confirmed / Revisions</span><strong><?=$statusCounts['confirmed_or_revision']?></strong></article>
      </section>
      <section class="evaluation-card">
        <form class="e3-verdict-filters" method="get">
          <label>Search project, team, or student<input type="search" name="search" value="<?=esc($search)?>" placeholder="Project ID or name"></label>
          <label>Academic year<select name="academic_year"><option value="">All years</option><?php foreach ($academicYears as $academicYear): ?><option value="<?=esc($academicYear)?>" <?=$yearFilter === $academicYear ? 'selected' : ''?>><?=esc($academicYear)?></option><?php endforeach; ?></select></label>
          <label>Evaluation status<select name="evaluation_status"><option value="">All statuses</option><?php foreach (['Awaiting Final Paper', 'Awaiting Evaluators', 'Evaluation In Progress', 'Awaiting Chairperson Review'] as $status): ?><option value="<?=esc($status)?>" <?=$evaluationFilter === $status ? 'selected' : ''?>><?=esc($status)?></option><?php endforeach; ?></select></label>
          <label>Verdict status<select name="verdict_status"><option value="">All statuses</option><?php foreach (['Not Confirmed', 'Awaiting Chairperson Review', 'Officially Confirmed', 'Revision Required'] as $status): ?><option value="<?=esc($status)?>" <?=$verdictFilter === $status ? 'selected' : ''?>><?=esc($status)?></option><?php endforeach; ?></select></label>
          <div class="e3-verdict-filter-actions"><button class="btn primary" type="submit">Apply Filters</button><a class="btn ghost" href="e3_verdict.php">Clear</a></div>
        </form>
        <div class="e3-verdict-table-wrap">
          <table class="data-table e3-verdict-table">
            <thead><tr><th>Project</th><th>Student / Team</th><th>Final Paper</th><th>Evaluation Status</th><th>Submitted Evaluators</th><th>Total Score</th><th>Recommended Verdict</th><th>Official Verdict</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="9">No projects match these filters.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): $recommendation = $row['summary'] && $row['summary']['calculated_score'] !== null ? e3_stage_verdict_from_score((float) $row['summary']['calculated_score']) : null; ?>
              <tr>
                <td><strong><?=esc($row['code'] ?: 'Project ' . $row['id'])?></strong><small><?=esc($row['title'])?></small><small>ID: <?=esc($row['id'])?> · <?=esc($row['academic_year'] ?: 'Year not set')?></small></td>
                <td><?=esc($row['student_name'] ?: $row['group_name'] ?: $row['members'] ?: 'Not set')?></td>
                <td><?php if ($row['final_submission']): ?><strong><?=esc($row['final_submission']['original_filename'])?></strong><small><?=esc(stage_submission_status_label($row['final_submission']))?></small><small><?=esc($row['final_submission']['created_at'])?></small><?php else: ?>Not submitted<?php endif; ?></td>
                <td><span class="e3-status-pill"><?=esc($row['evaluation_status'])?></span></td>
                <td><?=esc($row['evaluation_count'])?> / <?=esc($row['assignment_count'])?></td>
                <td><?=$row['summary'] && $row['summary']['calculated_score'] !== null ? number_format((float) $row['summary']['calculated_score'], 2) . ' / 100' : '—'?></td>
                <td><?=esc($recommendation['label'] ?? 'Pending')?></td>
                <td><span class="e3-status-pill <?=in_array($row['verdict_status'], ['Officially Confirmed', 'Revision Required'], true) ? 'confirmed' : ''?>"><?=esc($row['official']['verdict_label'] ?? $row['verdict_status'])?></span></td>
                <td><div class="e3-verdict-row-actions"><a class="btn ghost" href="e3_verdict.php?project_id=<?=esc($row['id'])?>#evaluator-results">View Evaluation</a><?php if ($row['final_submission']): ?><a class="btn primary" href="e3_verdict.php?project_id=<?=esc($row['id'])?>#official-verdict"><?= $row['official'] ? 'View Official Result' : ($recommendation ? 'Review / Confirm' : 'Review Verdict') ?></a><?php endif; ?></div></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </main>
  </body>
  </html>
  <?php
  exit;
}
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
$projectStmt->execute([$projectId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$errors = [];
$success = '';
$proposalSubmission = null;
$finalSubmission = null;
$consolidated = null;
$individualEvaluations = [];
$existing = null;
$publishedForCurrentSubmission = false;
$canReconfirm = false;
$submissionReady = false;
$recommended = null;
$verdictHistory = [];
if ($project) {
  $proposalSubmission = latest_stage_submission($pdo, $projectId, 'proposal');
  $finalSubmission = latest_stage_submission($pdo, $projectId, 'final');
  $submissionReady = $finalSubmission && !empty($finalSubmission['stored_filename'])
    && is_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $finalSubmission['stored_filename'])
    && proposal_allows_final_submission($project, $proposalSubmission);
  $consolidated = $finalSubmission
    ? e3_consolidated($pdo, $projectId, (int) $finalSubmission['id'])
    : [
      'evaluations' => [],
      'criteria' => array_fill_keys(array_keys(e3_criteria()), null),
      'calculated_score' => null,
      'evaluator_count' => 0,
    ];
  $calculated = $consolidated['calculated_score'];
  if ($finalSubmission) {
    $individualStmt = $pdo->prepare(
      'SELECT e.*, u.full_name, u.email
       FROM e3_evaluations e INNER JOIN users u ON u.id = e.evaluator_id
       WHERE e.project_id = ? AND e.status = ? AND e.final_submission_id = ?
       ORDER BY u.full_name'
    );
    $individualStmt->execute([$projectId, 'submitted', (int) $finalSubmission['id']]);
    $individualEvaluations = $individualStmt->fetchAll(PDO::FETCH_ASSOC);
  }
  $existingStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? LIMIT 1');
  $existingStmt->execute([$projectId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  $historyStmt = $pdo->prepare(
    'SELECT * FROM stage_verdict_history
     WHERE project_id = ? AND stage = ?
     ORDER BY id DESC'
  );
  $historyStmt->execute([$projectId, 'final']);
  $verdictHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
  $publishedForCurrentSubmission = $existing && $existing['published_at'] !== null && $finalSubmission
    && (int) ($existing['final_submission_id'] ?? 0) === (int) $finalSubmission['id'];
  $canReconfirm = $existing && $existing['published_at'] !== null && $submissionReady
    && $finalSubmission && (int) ($existing['final_submission_id'] ?? 0) !== (int) $finalSubmission['id'];
  $recommended = $calculated === null ? null : e3_stage_verdict_from_score((float) $calculated);

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
      $errors[] = 'Your session could not be verified.';
    } elseif (!$submissionReady) {
      $errors[] = 'A Final Paper can be reviewed only after the proposal stage is officially cleared and the final PDF is submitted.';
    } elseif ($publishedForCurrentSubmission || ($existing && $existing['published_at'] !== null && !$canReconfirm)) {
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
      $verdict = is_finite($finalScore) && $finalScore >= 0 && $finalScore <= 100
        ? e3_stage_verdict_from_score($finalScore)
        : null;
      if ($verdict && $verdict['label'] !== 'Passed' && $revisions === '') {
        $errors[] = 'Record required corrections before confirming a verdict that requires revisions.';
      }
      if (!$errors) {
        try {
          $pdo->beginTransaction();
          $chairpersonId = (int) $_SESSION['user']['id'];
          if ($existing) {
            if ($existing['published_at'] !== null && $canReconfirm) {
              $historyStmt = $pdo->prepare(
                'INSERT INTO stage_verdict_history
                 (project_id,stage,submission_id,chairperson_id,score,verdict_label,required_revisions,chairperson_comments,confirmed_at)
                 VALUES(?,?,?,?,?,?,?,?,?)'
              );
              $historyStmt->execute([
                $projectId,
                'final',
                (int) ($existing['final_submission_id'] ?? 0) ?: null,
                (int) $existing['chairperson_id'],
                (float) $existing['final_score'],
                (string) $existing['verdict_label'],
                $existing['revisions'] ?? null,
                $existing['chairperson_comments'] ?? null,
                (string) $existing['published_at'],
              ]);
            }
            $stmt = $pdo->prepare('UPDATE e3_final_verdicts SET chairperson_id=?, final_submission_id=?, calculated_score=?, final_score=?, verdict_label=?, has_departure=?, departure_reason=?, departure_authority=?, revisions=?, chairperson_comments=?, signature_name=?, signature_title=?, published_at=? WHERE id=?');
            $stmt->execute([$chairpersonId, (int) $finalSubmission['id'], $calculated, round($finalScore, 2), $verdict['label'], $hasDeparture ? 1 : 0, $reason ?: null, $authority ?: null, $revisions ?: null, $comments ?: null, $signatureName ?: null, $signatureTitle ?: null, date('Y-m-d H:i:s'), (int) $existing['id']]);
          } else {
            $stmt = $pdo->prepare('INSERT INTO e3_final_verdicts (project_id,chairperson_id,final_submission_id,calculated_score,final_score,verdict_label,has_departure,departure_reason,departure_authority,revisions,chairperson_comments,signature_name,signature_title,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$projectId, $chairpersonId, (int) $finalSubmission['id'], $calculated, round($finalScore, 2), $verdict['label'], $hasDeparture ? 1 : 0, $reason ?: null, $authority ?: null, $revisions ?: null, $comments ?: null, $signatureName ?: null, $signatureTitle ?: null, date('Y-m-d H:i:s')]);
          }
          $pdo->prepare('UPDATE projects SET overall_score=?, overall_rating=? WHERE id=?')->execute([round($finalScore, 2), $verdict['label'], $projectId]);
          $paperStatus = $verdict['label'] === 'Passed' ? 'accepted' : 'revision_required';
          $pdo->prepare('UPDATE student_submissions SET status=? WHERE id=?')->execute([$paperStatus, (int) $finalSubmission['id']]);
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
          log_workflow_database_error('e3_chairperson_verdict','publish_official_final_verdict',$exception);
          $errors[] = 'The final verdict could not be published.';
        }
      }
    }
  }
  $existingStmt = $pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id = ? LIMIT 1');
  $existingStmt->execute([$projectId]);
  $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  $success = isset($_GET['published']) ? 'Final hearing verdict confirmed and published to the student.' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IT Chairperson E3 Verdict</title>
  <link rel="stylesheet" href="../assets/style.css?v=20261124">
  <link rel="stylesheet" href="e3-verdict.css?v=20261124">
</head>
<body>
<main class="main-content" style="margin-left:0;width:100%">
  <div class="evaluation-content">
    <header class="main-header"><div class="main-header-text"><h1>IT Chairperson E3 Verdict</h1><p><?=esc($project['title'] ?? 'Project not found')?></p><?php if ($finalSubmission): ?><p>Final Paper: <?=esc($finalSubmission['original_filename'])?> · <?=esc($finalSubmission['created_at'])?></p><a class="btn ghost" href="../evaluator/view_submission.php?id=<?=esc($finalSubmission['id'])?>&amp;stage=final" target="_blank" rel="noopener">Open Final Paper PDF</a><?php endif; ?><p><a href="e3_verdict.php">← Back to E3 Verdict Management</a></p></div></header>
    <?php if ($success): ?><div class="evaluation-notice success"><strong><?=esc($success)?></strong></div><?php endif; ?>
    <?php if ($errors): ?><div class="evaluation-notice error"><strong>Please review:</strong><ul><?php foreach (array_unique($errors) as $error): ?><li><?=esc($error)?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$project): ?><section class="evaluation-card"><p>Project not found.</p></section><?php else: ?>
    <section class="evaluation-card" id="evaluator-results">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 1</span><h2>GRADING RUBRIC — 100 POINTS</h2></div>
      <table class="data-table"><thead><tr><th>Item</th><th>Criterion</th><th>Maximum</th><th>Consolidated Score</th></tr></thead><tbody>
      <?php foreach (e3_criteria() as $code => $criterion): ?>
        <tr><td><?=esc($code)?></td><td><?=esc($criterion['label'])?></td><td><?=esc($criterion['maximum'])?></td><td><?= $consolidated['criteria'][$code] === null ? 'Pending' : number_format($consolidated['criteria'][$code], 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
      <p><strong>Submitted evaluator sheets:</strong> <?=esc($consolidated['evaluator_count'])?></p>
      <p><strong>Calculated score:</strong> <?= $consolidated['calculated_score'] === null ? 'Pending' : number_format($consolidated['calculated_score'], 2) ?> / 100</p>
      <?php if ($recommended): ?><p><strong>Mamdani fuzzy output:</strong> <?=number_format((float) $recommended['fuzzy']['defuzz'], 2)?> / 100</p><p><strong>Recommended final-hearing verdict:</strong> <?=esc($recommended['label'])?></p><?php endif; ?>
      <p><strong>Chairperson confirmation status:</strong> <?=$publishedForCurrentSubmission ? 'Officially confirmed for this Final Paper' : 'Pending confirmation for this Final Paper'?></p>
      <?php if (!empty($individualEvaluations)): ?>
        <h3 style="margin-top:20px">Confidential Individual E3 Evaluations</h3>
        <div class="e3-verdict-evaluator-list">
        <?php foreach ($individualEvaluations as $individual): $individualCriterionComments = []; foreach (preg_split('/\R/', (string) ($individual['revisions'] ?? '')) ?: [] as $commentLine) { if (preg_match('/^([^—]+) — (.*)$/u', $commentLine, $commentMatch)) { $individualCriterionComments[trim($commentMatch[1])] = trim($commentMatch[2]); } } ?>
          <article class="e3-verdict-evaluator">
            <header><div><strong><?=esc($individual['full_name'])?></strong><small><?=esc($individual['email'])?> · <?=esc($individual['submitted_at'] ?? $individual['created_at'] ?? 'Submission time unavailable')?></small></div><strong><?=number_format((float) $individual['total_score'], 2)?> / 100</strong></header>
            <div class="e3-verdict-evaluator-scores">
              <?php foreach (e3_criteria() as $code => $criterion): ?>
                <div><span><?=esc($code)?> · <?=esc($criterion['label'])?></span><strong><?=number_format((float) $individual[$criterion['key']], 2)?> / <?=esc($criterion['maximum'])?></strong><?php if (!empty($individualCriterionComments[$code])): ?><small><?=nl2br(esc($individualCriterionComments[$code]))?></small><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
            <?php if (trim((string) ($individual['remarks'] ?? '')) !== ''): ?><p><strong>Evaluator comments:</strong><br><?=nl2br(esc($individual['remarks']))?></p><?php endif; ?>
            <?php if (trim((string) ($individual['revisions'] ?? '')) !== ''): ?><p><strong>Evaluator revisions:</strong><br><?=nl2br(esc($individual['revisions']))?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
    <?php if ($existing && $existing['published_at'] !== null): ?>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">OFFICIAL</span><h2><?=$canReconfirm ? 'Previous Chairperson-confirmed final result' : 'Chairperson-confirmed final result'?></h2></div>
        <p><strong>Final Paper submission ID:</strong> <?=esc($existing['final_submission_id'] ?: 'Legacy record')?></p>
        <p><strong>Official verdict:</strong> <?=esc($existing['verdict_label'])?></p>
        <p><strong>Confirmed score:</strong> <?=number_format((float) $existing['final_score'], 2)?> / 100</p>
        <p><strong>Confirmation date:</strong> <?=esc($existing['published_at'])?></p>
        <?php if (!empty($existing['revisions'])): ?><p><strong>Required revisions:</strong><br><?=nl2br(esc($existing['revisions']))?></p><?php endif; ?>
        <?php if (!empty($existing['chairperson_comments'])): ?><p><strong>Chairperson comments:</strong><br><?=nl2br(esc($existing['chairperson_comments']))?></p><?php endif; ?>
      </section>
    <?php endif; ?>
    <section class="evaluation-card" id="official-verdict">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 2</span><h2>FINAL SCORE AND VERDICT</h2></div>
      <form method="post" onsubmit="return confirm('Confirm and publish this official final-hearing verdict to the student?');">
        <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
        <label class="evaluation-field"><span class="evaluation-field-label">Final score</span><input type="number" name="final_score" min="0" max="100" step="0.01" value="<?=esc($canReconfirm ? (string) ($consolidated['calculated_score'] ?? '') : ($existing['final_score'] ?? (string) ($consolidated['calculated_score'] ?? '')))?>" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : 'required'?>></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Reason for permitted departure</span><textarea name="departure_reason" rows="3" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : ''?>><?=esc($canReconfirm ? '' : ($existing['departure_reason'] ?? ''))?></textarea></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Authority for departure</span><textarea name="departure_authority" rows="2" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : ''?>><?=esc($canReconfirm ? '' : ($existing['departure_authority'] ?? ''))?></textarea></label>
    </section>
    <?php if ($verdictHistory): ?>
      <section class="evaluation-card">
        <div class="evaluation-card-heading"><span class="evaluation-step">HISTORY</span><h2>Previous Official Verdicts</h2></div>
        <table class="data-table"><thead><tr><th>Submission</th><th>Score</th><th>Verdict</th><th>Required Revisions</th><th>Confirmed</th></tr></thead><tbody>
        <?php foreach ($verdictHistory as $history): ?><tr><td><?=esc($history['submission_id'] ?: 'Legacy record')?></td><td><?=number_format((float) $history['score'], 2)?> / 100</td><td><?=esc($history['verdict_label'])?></td><td><?=nl2br(esc($history['required_revisions'] ?: 'None'))?></td><td><?=esc($history['confirmed_at'])?></td></tr><?php endforeach; ?>
        </tbody></table>
      </section>
    <?php endif; ?>
    <section class="evaluation-card">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 3</span><h2>REVISIONS, COMMENTS AND RECOMMENDATIONS</h2></div>
        <label class="evaluation-field"><span class="evaluation-field-label">Required corrections and revisions</span><textarea name="revisions" rows="5" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : ''?>><?=esc($canReconfirm ? '' : ($existing['revisions'] ?? ''))?></textarea></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Chairperson comments</span><textarea name="chairperson_comments" rows="4" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : ''?>><?=esc($canReconfirm ? '' : ($existing['chairperson_comments'] ?? ''))?></textarea></label>
    </section>
    <section class="evaluation-card">
      <div class="evaluation-card-heading"><span class="evaluation-step">PART 4</span><h2>PANEL MEMBER'S SIGNATURE</h2></div>
        <label class="evaluation-field"><span class="evaluation-field-label">Authorized name</span><input type="text" name="signature_name" value="<?=esc($canReconfirm ? ($_SESSION['user']['full_name'] ?? '') : ($existing['signature_name'] ?? ($_SESSION['user']['full_name'] ?? '')) )?>" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : ''?>></label>
        <label class="evaluation-field"><span class="evaluation-field-label">Title / authority</span><input type="text" name="signature_title" value="<?=esc($canReconfirm ? 'IT Chairperson' : ($existing['signature_title'] ?? 'IT Chairperson'))?>" <?=($publishedForCurrentSubmission || !$submissionReady) ? 'disabled' : ''?>></label>
        <?php if (!$publishedForCurrentSubmission && $submissionReady): ?><div class="evaluation-actions"><button class="btn primary" type="submit"><?=$canReconfirm ? 'Confirm Revised Final Verdict' : 'Confirm Official Final Verdict'?></button></div><?php elseif ($existing && $existing['published_at'] !== null): ?><p><strong>Published:</strong> <?=esc($existing['published_at'])?></p><?php endif; ?>
      </form>
    </section>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
