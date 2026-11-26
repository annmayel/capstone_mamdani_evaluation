<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$schemaError = false;
try {
  $submissionUrlColumn = $pdo->query("SHOW COLUMNS FROM student_submissions LIKE 'submission_url'")->fetch(PDO::FETCH_ASSOC);
  $submissionUrlSelect = $submissionUrlColumn ? 's.submission_url' : 'NULL AS submission_url';
  $projectLinkColumns = $pdo->query("SHOW COLUMNS FROM projects WHERE Field IN ('google_drive_url', 'github_url', 'other_project_url')")->fetchAll(PDO::FETCH_COLUMN);
  $driveUrlSelect = in_array('google_drive_url', $projectLinkColumns, true) ? 'p.google_drive_url' : 'NULL AS google_drive_url';
  $githubUrlSelect = in_array('github_url', $projectLinkColumns, true) ? 'p.github_url' : 'NULL AS github_url';
  $otherUrlSelect = in_array('other_project_url', $projectLinkColumns, true) ? 'p.other_project_url' : 'NULL AS other_project_url';
  $stmt = $pdo->query(
    "SELECT p.*, p.id AS project_id, s.id AS submission_id, s.stage AS submission_stage, s.status AS submission_status, s.original_filename, {$submissionUrlSelect}, {$driveUrlSelect}, {$githubUrlSelect}, {$otherUrlSelect}, s.created_at AS submitted_at,
        (SELECT GROUP_CONCAT(DISTINCT assigned_user.full_name ORDER BY assigned_user.full_name SEPARATOR ', ')
         FROM evaluator_assignments assigned
         INNER JOIN users assigned_user ON assigned_user.id = assigned.evaluator_id
         WHERE assigned.project_id = p.id) AS evaluators
     FROM projects p
     LEFT JOIN student_submissions s ON s.id = (
       SELECT latest_submission.id
       FROM student_submissions latest_submission
       WHERE latest_submission.project_id = p.id
       ORDER BY latest_submission.created_at DESC, latest_submission.id DESC
       LIMIT 1
     )
     ORDER BY s.created_at DESC, p.id DESC"
  );
  $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $submissionsByProject = [];
  $projectIds = array_values(array_filter(array_map(static fn($row) => (int) $row['project_id'], $submissions)));
  if ($projectIds) {
    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
    $resourceUrlSelect = $submissionUrlColumn ? 'ss.submission_url' : 'NULL AS submission_url';
    $resourceStmt = $pdo->prepare("SELECT ss.id, ss.project_id, ss.stage, ss.original_filename, {$resourceUrlSelect}, ss.created_at, ss.status FROM student_submissions ss WHERE ss.project_id IN ({$placeholders}) ORDER BY ss.created_at DESC, ss.id DESC");
    $resourceStmt->execute($projectIds);
    foreach ($resourceStmt->fetchAll(PDO::FETCH_ASSOC) as $resource) {
      $submissionsByProject[(int) $resource['project_id']][] = $resource;
    }
  }
} catch (PDOException $exception) {
  $schemaError = true;
  $submissions = [];
  $submissionsByProject = [];
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Capstone Submissions</title><link rel="stylesheet" href="../assets/style.css?v=20261112"><link rel="icon" type="image/png" href="../favicon.png?v=20261016"></head>
<body class="dean-module-page dean-submissions-page">
<main class="dean-submissions-shell">
  <h2>Capstone Submissions</h2>
  <?php if ($schemaError): ?><div class="home-alert">Submission storage is not configured. Run <code>sql/migrations/001_student_submissions.sql</code> in the <code>capstone_fuzzy_eval</code> database, then refresh this page.</div><?php endif; ?>
  <div class="table-container">
    <table>
      <thead><tr><th>Submitted Resource</th><th>Project</th><th>Submitted</th><th>Status</th><th>Evaluators</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$submissions): ?><tr><td colspan="6">No capstone submissions found.</td></tr>
      <?php else: foreach ($submissions as $submission): ?>
        <?php $isFinalDefense = ($submission['submission_stage'] ?? 'proposal') === 'final'; ?>
        <tr>
          <td><?php $projectResources = $submissionsByProject[(int) $submission['project_id']] ?? []; if ($projectResources): ?><div style="display:grid;gap:8px;"><?php foreach ($projectResources as $resource): ?><div><?php $resourceStage = ($resource['stage'] ?? 'proposal') === 'final' ? 'Final Defense' : 'Proposal'; ?><small style="display:block;color:#805b3c;font-weight:700;margin-bottom:3px;"><?=esc($resourceStage)?></small><?php if (!empty($resource['original_filename'])): ?><strong><?=esc($resource['original_filename'])?></strong><small style="display:block;color:#687980;margin-top:4px;">PDF submitted by student</small><?php endif; ?><?php if (!empty($resource['submission_url'])): ?><a href="<?=esc($resource['submission_url'])?>" target="_blank" rel="noopener"><strong>Open project resource link</strong></a><small style="display:block;color:#687980;margin-top:4px;">Project resource link</small><?php endif; ?></div><?php endforeach; ?></div><?php else: ?><strong>No file submitted</strong><?php endif; ?></td>
          <td><?=esc($submission['title'])?><small style="display:block;color:#687980;margin-top:4px;">Group: <?=esc($submission['group_name'] ?? 'Not set')?></small><div style="margin-top:8px;"><small style="display:block;color:#805b3c;font-weight:700;">PROJECT RESOURCES</small><?php if (!empty($submission['google_drive_url'])): ?><a href="<?=esc($submission['google_drive_url'])?>" target="_blank" rel="noopener" style="display:block;margin-top:3px;">Google Drive</a><?php endif; ?><?php if (!empty($submission['github_url'])): ?><a href="<?=esc($submission['github_url'])?>" target="_blank" rel="noopener" style="display:block;margin-top:3px;">GitHub</a><?php endif; ?><?php if (!empty($submission['other_project_url'])): ?><a href="<?=esc($submission['other_project_url'])?>" target="_blank" rel="noopener noreferrer" style="display:block;margin-top:3px;">Other project link</a><?php endif; ?><?php if (empty($submission['google_drive_url']) && empty($submission['github_url']) && empty($submission['other_project_url'])): ?><small style="display:block;color:#687980;margin-top:3px;">No project links</small><?php endif; ?></div></td>
          <td><?=esc($submission['submitted_at'] ?? 'Not submitted')?></td>
          <td><?=esc($submission['submission_status'] ?? 'Unknown')?></td>
          <td><?=esc($submission['evaluators'] ?? 'Not assigned')?></td>
          <td>
            <div class="submission-actions">
              <?php if ($submission['submission_id']): ?><button class="btn ghost" type="button" data-preview-submission="<?=esc($submission['submission_id'])?>">View <?= $isFinalDefense ? 'Final PDF' : 'PDF' ?></button><?php endif; ?>
              <?php if ($submission['submission_url']): ?><a class="btn ghost" target="_blank" rel="noopener" href="<?=esc($submission['submission_url'])?>">Open Project Link</a><?php endif; ?>
              <a class="btn primary" href="assign_evaluators.php?project_id=<?=esc($submission['project_id'])?><?= $isFinalDefense ? '&amp;final_submission_id=' . esc($submission['submission_id']) : '' ?>"><?= $isFinalDefense ? 'Assign Final Hearing Evaluators' : 'Assign Evaluators' ?></a>
              <?php if ($isFinalDefense): ?><a class="btn ghost" href="e3_verdict.php?project_id=<?=esc($submission['project_id'])?>">Review Final Defense</a><?php else: ?><a class="btn ghost" href="proposal_verdict.php?project_id=<?=esc($submission['project_id'])?>">Review Proposal</a><?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
  document.querySelectorAll('[data-preview-submission]').forEach(button => {
    button.addEventListener('click', () => {
      window.parent.postMessage({
        type: 'dean:preview-submission',
        submissionId: button.dataset.previewSubmission
      }, window.location.origin);
    });
  });
</script>
</body>
</html>
