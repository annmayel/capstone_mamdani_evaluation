<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$message = '';
$error = '';
$editProjectId = (int) ($_POST['edit_project_id'] ?? $_GET['edit'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Invalid form request. Please try again.';
    } else {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $groupName = trim($_POST['group_name'] ?? '');
        $members = trim($_POST['members'] ?? '');
        $adviser = trim($_POST['adviser'] ?? '');
        $academicYear = trim($_POST['academic_year'] ?? '');
        $defenseDate = $_POST['defense_date'] ?? null;
        $description = trim($_POST['description'] ?? '');

        if (!$studentId || $code === '' || $title === '') {
            $error = 'Select a student and provide the project code and title.';
        } else {
            $stmt = $pdo->prepare('SELECT id, full_name, email FROM users WHERE id = ? AND role = \'student\' LIMIT 1');
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                $error = 'The selected student account was not found.';
            } else {
                if ($editProjectId > 0) {
                    $stmt = $pdo->prepare('SELECT id, defense_date FROM projects WHERE id = ? AND student_id = ? LIMIT 1');
                    $stmt->execute([$editProjectId, $studentId]);
                } else {
                    $stmt = $pdo->prepare('SELECT id, defense_date FROM projects WHERE student_id = ? LIMIT 1');
                    $stmt->execute([$studentId]);
                }
                $existingProject = $stmt->fetch(PDO::FETCH_ASSOC);
                $existingProjectId = $existingProject['id'] ?? null;
                $projectId = 0;
                $newProject = false;
                if ($editProjectId > 0 && !$existingProjectId) {
                    $error = 'The selected project could not be found for that student.';
                } elseif ($existingProjectId) {
                    $stmt = $pdo->prepare('UPDATE projects SET code=?, title=?, group_name=?, members=?, adviser=?, academic_year=?, defense_date=?, description=? WHERE id=?');
                    $stmt->execute([$code, $title, $groupName, $members, $adviser, $academicYear, $defenseDate ?: null, $description, $existingProjectId]);
                    $projectId = (int) $existingProjectId;
                    $message = 'Project details updated and linked to ' . $student['full_name'] . '.';
                    $newProject = false;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO projects (code,title,group_name,members,adviser,student_id,academic_year,defense_date,description,status) VALUES (?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$code, $title, $groupName, $members, $adviser, $studentId, $academicYear, $defenseDate ?: null, $description, 'Draft']);
                    $projectId = (int) $pdo->lastInsertId();
                    $message = 'Project registered and linked to ' . $student['full_name'] . '.';
                    $newProject = true;
                }
                if ($newProject) {
                    notify_user_once($pdo, $studentId, 'project-registered:' . $projectId . ':' . $studentId, 'Project ready for submission', 'Your capstone project is now registered. Open Proposal Status to upload your PDF.', 'students/student_dashboard.php?view=proposal');
                    require_once __DIR__ . '/../php/email_notifications.php';
                    fuzzy_email_notify($pdo, [
                        'event_key' => 'project_registered:' . $projectId . ':' . $studentId,
                        'event_type' => 'project_registered',
                        'recipient_user_id' => $studentId,
                        'recipient_email' => (string) $student['email'],
                        'recipient_name' => (string) $student['full_name'],
                        'project_id' => $projectId,
                        'subject' => 'Your capstone project is ready for submission',
                        'heading' => 'Project registered',
                        'intro' => 'Your capstone project has been linked to your portal account. You can now submit the proposal and project resources.',
                        'sections' => [[
                            'title' => 'Project details',
                            'rows' => [['Project code', $code], ['Project title', $title], ['Status', 'Draft']],
                        ]],
                        'portal_path' => '/students/student_dashboard.php?view=proposal',
                    ]);
                } elseif ($existingProject && ($existingProject['defense_date'] ?? null) !== ($defenseDate ?: null)) {
                    $oldDefenseDate = $existingProject['defense_date'] ?? null;
                    $newDefenseDate = $defenseDate ?: null;
                    $oldDateLabel = $oldDefenseDate ? date('M j, Y', strtotime($oldDefenseDate)) : 'Not set';
                    $newDateLabel = $newDefenseDate ? date('M j, Y', strtotime($newDefenseDate)) : 'Not set';
                    $eventKey = 'defense-schedule-changed:' . $projectId . ':' . ($oldDefenseDate ?? '') . ':' . ($newDefenseDate ?? '');
                    notify_user_once($pdo, $studentId, $eventKey, 'Defense schedule updated', 'Your capstone defense schedule has changed to ' . $newDateLabel . '.', 'students/student_dashboard.php?view=proposal');
                    require_once __DIR__ . '/../php/email_notifications.php';
                    fuzzy_email_notify($pdo, [
                        'event_key' => $eventKey . ':' . $studentId,
                        'event_type' => 'defense_schedule_changed',
                        'recipient_user_id' => $studentId,
                        'recipient_email' => (string) $student['email'],
                        'recipient_name' => (string) $student['full_name'],
                        'project_id' => $projectId,
                        'subject' => 'Your capstone defense schedule was updated',
                        'heading' => 'Defense schedule updated',
                        'intro' => 'The defense date associated with your capstone project has been changed.',
                        'sections' => [['title' => 'Schedule change', 'rows' => [['Previous date', $oldDateLabel], ['Current date', $newDateLabel], ['Project title', $title]]]],
                        'portal_path' => '/students/student_dashboard.php?view=proposal',
                    ]);
                }
            }
        }
    }
}
$students = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'student' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT p.id, p.code, p.title, p.status, p.group_name, p.academic_year, p.student_id, u.full_name AS student_name FROM projects p LEFT JOIN users u ON u.id = p.student_id ORDER BY p.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$projectForEdit = null;
if ($editProjectId > 0) {
    $editProjectStmt = $pdo->prepare('SELECT id, student_id, code, title, group_name, members, adviser, academic_year, defense_date, description FROM projects WHERE id = ? LIMIT 1');
    $editProjectStmt->execute([$editProjectId]);
    $projectForEdit = $editProjectStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$academicYears = array_values(array_unique(array_filter(array_map(static fn($project) => trim((string) ($project['academic_year'] ?? '')), $projects))));
$projectStatuses = array_values(array_unique(array_filter(array_map(static fn($project) => trim((string) ($project['status'] ?? '')), $projects))));
sort($academicYears);
sort($projectStatuses);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Project Setup</title><link rel="stylesheet" href="../assets/style.css?v=20261112"><link rel="icon" type="image/png" href="../favicon.png?v=20261016"><script defer src="../assets/dean-table.js"></script></head>
<body class="setup-page"><main class="setup-shell"><div class="setup-page-heading"><div><span class="setup-kicker">CAPSTONE WORKFLOW</span><h2>Project Setup</h2><p class="setup-lead">Link a capstone project to a student so they can submit their proposal.</p></div></div>
<?php if ($message): ?><div class="setup-alert" role="status"><?=esc($message)?></div><?php endif; ?><?php if ($error): ?><div class="setup-alert setup-error" role="alert"><?=esc($error)?></div><?php endif; ?>
<section class="setup-card" id="project-form"><h3><?= $projectForEdit ? 'Update student project' : 'Register or update student project' ?></h3><form method="post"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><input type="hidden" name="edit_project_id" value="<?=esc($editProjectId)?>"><div class="setup-grid"><label>Student<select name="student_id" required><option value="">Select student</option><?php foreach ($students as $student): ?><option value="<?=esc($student['id'])?>" <?=((string) ($_POST['student_id'] ?? $projectForEdit['student_id'] ?? '') === (string) $student['id']) ? 'selected' : ''?>><?=esc($student['full_name'])?> (<?=esc($student['email'])?>)</option><?php endforeach; ?></select></label><label>Project Code<input name="code" placeholder="e.g. CC-002" value="<?=esc($_POST['code'] ?? $projectForEdit['code'] ?? '')?>" required></label><label class="wide">Project Title<input name="title" placeholder="Capstone project title" value="<?=esc($_POST['title'] ?? $projectForEdit['title'] ?? '')?>" required></label><label>Group Name<input name="group_name" placeholder="Team name" value="<?=esc($_POST['group_name'] ?? $projectForEdit['group_name'] ?? '')?>"></label><label>Members<input name="members" placeholder="Names separated by semicolons" value="<?=esc($_POST['members'] ?? $projectForEdit['members'] ?? '')?>"></label><label>Adviser<input name="adviser" placeholder="Adviser name" value="<?=esc($_POST['adviser'] ?? $projectForEdit['adviser'] ?? '')?>"></label><label>Academic Year<input name="academic_year" placeholder="2025-2026" value="<?=esc($_POST['academic_year'] ?? $projectForEdit['academic_year'] ?? '')?>"></label><label>Defense Date<input type="date" name="defense_date" value="<?=esc($_POST['defense_date'] ?? $projectForEdit['defense_date'] ?? '')?>"></label><label class="wide">Description<textarea name="description" placeholder="Brief project description"><?=esc($_POST['description'] ?? $projectForEdit['description'] ?? '')?></textarea></label></div><button class="btn primary setup-submit" type="submit"><?= $projectForEdit ? 'Save Project Changes' : 'Register Project' ?></button></form></section>
<section class="setup-card setup-project-list" data-dean-table><div class="setup-table-heading"><div><h3>Registered Projects</h3><p>Search and filter capstone records.</p></div></div><div class="dean-table-toolbar"><label class="dean-search-field">Search<input type="search" data-table-search placeholder="Search title, code, group or student"></label><label>Academic Year<select data-table-filter="academicYear"><option value="">All years</option><?php foreach ($academicYears as $academicYear): ?><option value="<?=esc(strtolower($academicYear))?>"><?=esc($academicYear)?></option><?php endforeach; ?></select></label><label>Status<select data-table-filter="status"><option value="">All statuses</option><?php foreach ($projectStatuses as $projectStatus): ?><option value="<?=esc(strtolower($projectStatus))?>"><?=esc($projectStatus)?></option><?php endforeach; ?></select></label></div><div class="dean-table-scroll"><table class="setup-table dean-data-table"><thead><tr><th>Project</th><th>Group</th><th>Student</th><th>Academic Year</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($projects as $project): ?><tr data-table-row data-academic-year="<?=esc(strtolower((string) $project['academic_year']))?>" data-status="<?=esc(strtolower((string) $project['status']))?>" data-search="<?=esc(strtolower(implode(' ', [$project['code'], $project['title'], $project['group_name'], $project['student_name']])))?>"><td><strong><?=esc($project['code'])?></strong><br><?=esc($project['title'])?></td><td><?=esc($project['group_name'] ?: 'Not set')?></td><td><?=esc($project['student_name'] ?? 'Unlinked')?></td><td><?=esc($project['academic_year'] ?: 'Not set')?></td><td><span class="project-status"><?=esc($project['status'])?></span></td><td><a class="dean-action-button" href="project_setup.php?embedded=1&amp;edit=<?=esc($project['id'])?>#project-form">Edit</a></td></tr><?php endforeach; ?></tbody></table></div><div class="dean-table-footer"><span data-table-count></span><label>Rows<select data-page-size><option value="10">10</option><option value="25">25</option><option value="50">50</option></select></label><nav data-table-pagination aria-label="Project table pages"><button type="button" data-page-prev>Previous</button><span data-page-indicator></span><button type="button" data-page-next>Next</button></nav></div><div class="dean-table-empty" data-table-empty hidden>No projects match the current search and filters.</div></section>
</main></body></html>
