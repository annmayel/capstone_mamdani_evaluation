<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$user = require_verified_adviser($pdo);
$verificationSuccess = (string) ($_SESSION['verification_success'] ?? '');
unset($_SESSION['verification_success']);

$projectStmt = $pdo->prepare(
	'SELECT p.id, p.code, p.title, p.group_name, p.academic_year, p.defense_date, p.description, p.status,
		u.full_name AS student_name
	 FROM projects p
	 LEFT JOIN users u ON u.id = p.student_id AND u.role = \'student\'
	 WHERE p.adviser_user_id = ?
	 ORDER BY p.id DESC'
);
$projectStmt->execute([(int) $user['id']]);
$projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Adviser Dashboard | Capstone Evaluation System</title>
	<link rel="stylesheet" href="../assets/style.css?v=20261114-otp">
	<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="dean-module-page adviser-dashboard">
	<header class="dean-module-heading">
		<div class="adviser-brand-heading">
			<img src="../images_bgnd/CSUCC%20LOGO.png" alt="" aria-hidden="true">
			<div>
				<div class="adviser-system-brand"><strong>CAPSTONE EVALUATION SYSTEM</strong><small>ADVISER PORTAL</small></div>
				<h1>Adviser Dashboard</h1>
				<p>Welcome, <?= esc($user['full_name'] ?? 'Adviser') ?>. This page shows only projects assigned to your Adviser account.</p>
			</div>
		</div>
		<a class="btn ghost" href="../logout.php">Log out</a>
	</header>

	<?php if ($verificationSuccess !== ''): ?><div class="setup-alert" role="status"><?= esc($verificationSuccess) ?></div><?php endif; ?>

	<section class="setup-card setup-project-list">
		<div class="setup-table-heading">
			<div><h2>Assigned Projects</h2><p><?= count($projects) ?> project(s) assigned to you.</p></div>
		</div>
		<?php if ($projects): ?>
			<div class="dean-table-scroll">
				<table class="setup-table dean-data-table">
					<thead><tr><th>Project</th><th>Student</th><th>Group</th><th>Academic Year</th><th>Defense Date</th><th>Status</th></tr></thead>
					<tbody>
						<?php foreach ($projects as $project): ?>
							<tr>
								<td><strong><?= esc($project['code']) ?></strong><br><?= esc($project['title']) ?></td>
								<td><?= esc($project['student_name'] ?? 'Not linked') ?></td>
								<td><?= esc($project['group_name'] ?: 'Not set') ?></td>
								<td><?= esc($project['academic_year'] ?: 'Not set') ?></td>
								<td><?= esc($project['defense_date'] ? date('M j, Y', strtotime($project['defense_date'])) : 'Not set') ?></td>
								<td><span class="project-status"><?= esc($project['status'] ?: 'Not set') ?></span></td>
							</tr>
							<?php if (!empty($project['description'])): ?>
								<tr><td colspan="6"><strong>Description:</strong> <?= esc($project['description']) ?></td></tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else: ?>
			<div class="dean-table-empty">No projects have been assigned to your Adviser account yet. Contact the Dean if you believe this is incorrect.</div>
		<?php endif; ?>
	</section>
</body>
</html>
