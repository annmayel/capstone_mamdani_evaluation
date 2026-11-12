<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$schemaError = false;
try {
  $submissionUrlColumn = $pdo->query("SHOW COLUMNS FROM student_submissions LIKE 'submission_url'")->fetch(PDO::FETCH_ASSOC);
  $submissionUrlSelect = $submissionUrlColumn ? 's.submission_url' : 'NULL AS submission_url';
  $projectLinkColumns = $pdo->query("SHOW COLUMNS FROM projects WHERE Field IN ('google_drive_url', 'github_url')")->fetchAll(PDO::FETCH_COLUMN);
  $driveUrlSelect = in_array('google_drive_url', $projectLinkColumns, true) ? 'p.google_drive_url' : 'NULL AS google_drive_url';
  $githubUrlSelect = in_array('github_url', $projectLinkColumns, true) ? 'p.github_url' : 'NULL AS github_url';
  $stmt = $pdo->query(
    "SELECT p.*, p.id AS project_id, s.id AS submission_id, s.original_filename, {$submissionUrlSelect}, {$driveUrlSelect}, {$githubUrlSelect}, s.created_at AS submitted_at,
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
    $resourceStmt = $pdo->prepare("SELECT ss.id, ss.project_id, ss.original_filename, {$resourceUrlSelect}, ss.created_at, ss.status FROM student_submissions ss WHERE ss.project_id IN ({$placeholders}) ORDER BY ss.created_at DESC, ss.id DESC");
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
        <tr>
          <td><?php $projectResources = $submissionsByProject[(int) $submission['project_id']] ?? []; if ($projectResources): ?><div style="display:grid;gap:8px;"><?php foreach ($projectResources as $resource): ?><div><?php if (!empty($resource['submission_url'])): ?><a href="<?=esc($resource['submission_url'])?>" target="_blank" rel="noopener"><strong><?=esc($resource['submission_url'])?></strong></a><small style="display:block;color:#687980;margin-top:4px;">Project resource link</small><?php else: ?><strong><?=esc($resource['original_filename'] ?? 'Submitted PDF')?></strong><small style="display:block;color:#687980;margin-top:4px;">PDF submitted by student</small><?php endif; ?></div><?php endforeach; ?></div><?php else: ?><strong>No file submitted</strong><?php endif; ?></td>
          <td><?=esc($submission['title'])?><small style="display:block;color:#687980;margin-top:4px;">Group: <?=esc($submission['group_name'] ?? 'Not set')?></small><div style="margin-top:8px;"><small style="display:block;color:#805b3c;font-weight:700;">PROJECT RESOURCES</small><?php if (!empty($submission['google_drive_url'])): ?><a href="<?=esc($submission['google_drive_url'])?>" target="_blank" rel="noopener" style="display:block;margin-top:3px;">Google Drive</a><?php endif; ?><?php if (!empty($submission['github_url'])): ?><a href="<?=esc($submission['github_url'])?>" target="_blank" rel="noopener" style="display:block;margin-top:3px;">GitHub</a><?php endif; ?><?php if (empty($submission['google_drive_url']) && empty($submission['github_url'])): ?><small style="display:block;color:#687980;margin-top:3px;">No project links</small><?php endif; ?></div></td>
          <td><?=esc($submission['submitted_at'] ?? 'Not submitted')?></td>
          <td><?=esc($submission['status'] ?? 'Unknown')?></td>
          <td><?=esc($submission['evaluators'] ?? 'Not assigned')?></td>
          <td>
            <div class="submission-actions">
              <?php if ($submission['submission_url']): ?><a class="btn ghost" target="_blank" rel="noopener" href="<?=esc($submission['submission_url'])?>">Open Link</a><?php elseif ($submission['submission_id']): ?><button class="btn ghost" type="button" data-preview-submission="<?=esc($submission['submission_id'])?>">View PDF</button><?php endif; ?>
              <a class="btn primary" href="assign_evaluators.php?project_id=<?=esc($submission['project_id'])?>">Assign</a>
            </main>
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
