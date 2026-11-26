<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/email_notifications.php';
require_once __DIR__ . '/../php/project_links.php';
require_once __DIR__ . '/../php/student_auth.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/proposal_revisions.php';
$user = require_approved_student($pdo);
$studentId = (int) $user['id'];
$redirect = 'student_dashboard.php?view=proposal';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
    header('Location: ' . $redirect . '&error=invalid_request');
    exit;
}

$projectStmt = $pdo->prepare('SELECT id FROM projects WHERE student_id = ? LIMIT 1');
$projectStmt->execute([$studentId]);
$projectId = $projectStmt->fetchColumn();

if (!$projectId) {
    header('Location: ' . $redirect . '&error=no_project');
    exit;
}

$stage = (string) ($_POST['stage'] ?? 'proposal');
if (!in_array($stage, ['proposal', 'final'], true)) {
    header('Location: ' . $redirect . '&error=invalid_request');
    exit;
}
$projectStmt = $pdo->prepare('SELECT * FROM projects WHERE id = ? AND student_id = ? LIMIT 1');
$projectStmt->execute([(int) $projectId, $studentId]);
$project = $projectStmt->fetch(PDO::FETCH_ASSOC);
$latestProposal = latest_stage_submission($pdo, (int) $projectId, 'proposal');
$latestStageSubmission = latest_stage_submission($pdo, (int) $projectId, $stage);
$majorRevisionUpload = false;
$previousRevisionCycle = null;
$revisionUploadState = 'not_required';
if ($stage === 'proposal' && $latestProposal && proposal_evaluation_schema_available($pdo)) {
    $revisionSchemaReady = proposal_revision_schema_available($pdo);
    $previousRevisionCycle = $revisionSchemaReady ? proposal_revision_cycle($pdo, (int) $projectId) : null;
    $previousRevisionSummary = $previousRevisionCycle
        ? proposal_revision_review_summary($pdo, (int) $previousRevisionCycle['id'])
        : null;
    $originalSubmissionId = $previousRevisionCycle
        ? (int) $previousRevisionCycle['original_submission_id']
        : (int) $latestProposal['id'];
    $panel = proposal_panel_completion($pdo, (int) $projectId, $originalSubmissionId);
    $originalMajorRevision = $panel['ready'] && proposal_is_major_revision($panel['consolidated']['recommendation']);
    $revisionUploadState = proposal_revision_upload_state(
        $originalMajorRevision,
        $revisionSchemaReady,
        $previousRevisionCycle,
        $previousRevisionSummary
    );
    $majorRevisionUpload = $revisionUploadState === 'allowed';
    if ($revisionUploadState === 'schema_required') {
        header('Location: ' . $redirect . '&error=revision_schema_required');
        exit;
    }
    if (in_array($revisionUploadState, ['pending', 'accepted'], true)) {
        header('Location: ' . $redirect . '&error=revision_' . $revisionUploadState);
        exit;
    }
}
if ($stage === 'final' && !proposal_allows_final_submission($project, $latestProposal)) {
    try {
        if (proposal_revision_sync_accepted_submission($pdo, (int) $projectId, (int) ($latestProposal['id'] ?? 0))) {
            $projectStmt->execute([(int) $projectId, $studentId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $exception) {
        error_log('[student final defense] Accepted revision synchronization failed: ' . $exception->getMessage());
    }
    if (!proposal_allows_final_submission($project, $latestProposal)) {
        header('Location: ' . $redirect . '&error=final_locked');
        exit;
    }
}
if (!$majorRevisionUpload && $latestStageSubmission && !in_array($latestStageSubmission['status'], ['revision_required', 'revision_requested'], true)
    && !empty($latestStageSubmission['stored_filename'])) {
    header('Location: ' . $redirect . '&error=submission_locked');
    exit;
}

$file = $_FILES['proposal'] ?? null;
$projectTitle = trim((string) ($_POST['project_title'] ?? ''));
$googleDriveUrl = trim((string) ($_POST['google_drive_url'] ?? ''));
$githubUrl = trim((string) ($_POST['github_url'] ?? ''));
$otherProjectUrl = trim((string) ($_POST['other_project_url'] ?? ''));
$legacySubmissionUrl = trim((string) ($_POST['submission_url'] ?? ''));
$finalProjectResourceUrl = trim((string) ($_POST['project_resource_url'] ?? ''));
$hasUpload = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
$destination = null;
$existingProjectTitle = trim((string) ($project['title'] ?? ''));

$resourceLinks = array_filter([$googleDriveUrl, $githubUrl, $otherProjectUrl, $legacySubmissionUrl]);
if (!$hasUpload && ($stage !== 'proposal' || !empty($_POST['stage']) || !$resourceLinks)) {
    header('Location: ' . $redirect . '&error=missing_submission');
    exit;
}

if ($projectTitle === '') {
    $projectTitle = $existingProjectTitle;
}
if ($projectTitle === '') {
    header('Location: ' . $redirect . '&error=missing_project_title');
    exit;
}

if ($googleDriveUrl && !fuzzy_project_link_is_valid($googleDriveUrl, ['drive.google.com', 'docs.google.com'])) {
    header('Location: ' . $redirect . '&error=invalid_drive_link');
    exit;
}
if ($githubUrl && !fuzzy_project_link_is_valid($githubUrl, ['github.com', 'gist.github.com', 'raw.githubusercontent.com'])) {
    header('Location: ' . $redirect . '&error=invalid_github_link');
    exit;
}
if ($otherProjectUrl && !fuzzy_project_link_is_valid($otherProjectUrl)) {
    header('Location: ' . $redirect . '&error=invalid_other_link');
    exit;
}
if ($legacySubmissionUrl && !fuzzy_project_link_is_valid($legacySubmissionUrl, ['drive.google.com', 'docs.google.com', 'github.com', 'gist.github.com', 'raw.githubusercontent.com'])) {
    header('Location: ' . $redirect . '&error=invalid_link');
    exit;
}
if ($finalProjectResourceUrl && !fuzzy_project_link_is_valid($finalProjectResourceUrl)) {
    header('Location: ' . $redirect . '&error=invalid_project_resource_link');
    exit;
}

if ($hasUpload) {
    $maxSize = 10 * 1024 * 1024;
    $originalName = basename((string) $file['name']);
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($file['size'] <= 0 || $file['size'] > $maxSize || $extension !== 'pdf') {
        header('Location: ' . $redirect . '&error=invalid_pdf');
        exit;
    }

    $handle = fopen($file['tmp_name'], 'rb');
    $signature = $handle ? fread($handle, 5) : '';
    if ($handle) fclose($handle);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if ($signature !== '%PDF-' || $mime !== 'application/pdf') {
        header('Location: ' . $redirect . '&error=invalid_pdf');
        exit;
    }


    $storage = submission_storage_path();
    if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
        header('Location: ' . $redirect . '&error=storage_failed');
        exit;
    }

    $storedName = 'submission_' . bin2hex(random_bytes(16)) . '.pdf';
    $destination = $storage . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        header('Location: ' . $redirect . '&error=storage_failed');
        exit;
    }
} else {
    $originalName = 'External project link';
    $storedName = '';
    $mime = 'text/uri-list';
    $fileSize = 0;
}

$revisionPanel = [];
$revisionCycleId = null;
$submissionPersisted = false;
try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM student_submissions LIKE 'submission_url'")->fetch(PDO::FETCH_ASSOC);
    if (!$columnCheck) {
        $pdo->exec('ALTER TABLE student_submissions ADD COLUMN submission_url VARCHAR(1000) DEFAULT NULL AFTER stored_filename');
    }
    $projectColumns = $pdo->query("SHOW COLUMNS FROM projects WHERE Field IN ('google_drive_url', 'github_url', 'other_project_url')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('google_drive_url', $projectColumns, true)) $pdo->exec('ALTER TABLE projects ADD COLUMN google_drive_url VARCHAR(1000) DEFAULT NULL AFTER description');
    if (!in_array('github_url', $projectColumns, true)) $pdo->exec('ALTER TABLE projects ADD COLUMN github_url VARCHAR(1000) DEFAULT NULL AFTER google_drive_url');
    if (!in_array('other_project_url', $projectColumns, true)) $pdo->exec('ALTER TABLE projects ADD COLUMN other_project_url VARCHAR(1000) DEFAULT NULL AFTER github_url');

    $pdo->beginTransaction();
    $projectLock = $pdo->prepare('SELECT id FROM projects WHERE id=? AND student_id=? FOR UPDATE');
    $projectLock->execute([(int) $projectId, $studentId]);
    if (!$projectLock->fetchColumn()) throw new RuntimeException('The project is no longer available for this submission.');
    if ($majorRevisionUpload) {
        $lockedCycle = proposal_revision_cycle($pdo, (int) $projectId);
        if ($lockedCycle && (!$previousRevisionCycle || (int) $lockedCycle['id'] !== (int) $previousRevisionCycle['id'])) {
            throw new RuntimeException('A revised proposal is already awaiting panel review.');
        }
    }
    if ($stage === 'proposal' && array_key_exists('project_title', $_POST)) {
        $pdo->prepare('UPDATE projects SET title = ?, google_drive_url = ?, github_url = ?, other_project_url = ? WHERE id = ? AND student_id = ?')->execute([
            $projectTitle,
            array_key_exists('google_drive_url', $_POST) ? ($googleDriveUrl ?: null) : ($project['google_drive_url'] ?? null),
            array_key_exists('github_url', $_POST) ? ($githubUrl ?: null) : ($project['github_url'] ?? null),
            array_key_exists('other_project_url', $_POST) ? ($otherProjectUrl ?: null) : ($project['other_project_url'] ?? null),
            (int) $projectId,
            $studentId,
        ]);
    }
    $stmt = $pdo->prepare('INSERT INTO student_submissions (student_id, project_id, stage, original_filename, stored_filename, submission_url, mime_type, file_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $submissionUrl = $stage === 'final'
        ? ($finalProjectResourceUrl ?: null)
        : ($hasUpload ? null : ($legacySubmissionUrl ?: null));
    $stmt->execute([$studentId, (int) $projectId, $stage, $originalName, $storedName, $submissionUrl, $mime, $hasUpload ? (int) $file['size'] : $fileSize, 'submitted']);
    $submissionId = (int) $pdo->lastInsertId();
    if ($stage === 'proposal') {
        $pdo->prepare('UPDATE projects SET proposal_revision_accepted_submission_id = NULL WHERE id = ?')->execute([(int) $projectId]);
        $pdo->prepare("UPDATE projects SET status = 'Submitted' WHERE id = ? AND student_id = ? AND status IN ('Draft', 'No proposal submitted')")->execute([(int) $projectId, $studentId]);
        if ($majorRevisionUpload) {
            $originalSubmissionId = $previousRevisionCycle ? (int) $previousRevisionCycle['original_submission_id'] : (int) $latestProposal['id'];
            $assignedStmt = $pdo->prepare("SELECT DISTINCT pe.evaluator_id,u.full_name,u.email FROM proposal_evaluations pe INNER JOIN users u ON u.id=pe.evaluator_id AND u.role='evaluator' WHERE pe.project_id=? AND pe.proposal_submission_id=? AND pe.status='submitted'");
            $assignedStmt->execute([(int) $projectId, $originalSubmissionId]);
            $revisionPanel = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$revisionPanel) throw new RuntimeException('The original assigned panel could not be identified for this revision.');
            $numberStmt = $pdo->prepare('SELECT COALESCE(MAX(revision_number),0)+1 FROM proposal_revision_cycles WHERE project_id=?');
            $numberStmt->execute([(int) $projectId]);
            $revisionNumber = (int) $numberStmt->fetchColumn();
            $cycleStmt = $pdo->prepare('INSERT INTO proposal_revision_cycles (project_id,original_submission_id,revision_submission_id,revision_number) VALUES (?,?,?,?)');
            $cycleStmt->execute([(int) $projectId, $originalSubmissionId, $submissionId, $revisionNumber]);
            $revisionCycleId = (int) $pdo->lastInsertId();
            foreach ($revisionPanel as $evaluator) {
                $pdo->prepare('INSERT INTO proposal_revision_reviews (revision_cycle_id,evaluator_id) VALUES (?,?)')->execute([$revisionCycleId, (int) $evaluator['evaluator_id']]);
            }
        }
    }
    $pdo->commit();
    $submissionPersisted = true;

    try {
    if ($revisionCycleId !== null) {
        foreach ($revisionPanel as $evaluator) {
            notify_user_once($pdo, (int) $evaluator['evaluator_id'], 'proposal-revision:' . $revisionCycleId . ':' . (int) $evaluator['evaluator_id'], 'Revised proposal ready for review', 'A student submitted a revised proposal that requires your panel decision.', 'evaluator/review_proposal_revision.php?cycle_id=' . $revisionCycleId);
        }
    }
    $stageName = $stage === 'proposal' ? 'Proposal' : 'Final Defense';
    notify_user_once($pdo, $studentId, $stage . '-submitted:' . $submissionId . ':' . $studentId, $stageName . ' submission recorded', 'Your ' . strtolower($stageName) . ' was submitted successfully and is now awaiting review.', 'students/student_dashboard.php?view=proposal');
    $studentStmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        fuzzy_email_notify($pdo, [
            'event_key' => $stage . '_submission_received:' . $submissionId . ':' . $studentId,
            'event_type' => 'submission_received',
            'recipient_user_id' => $studentId,
            'recipient_email' => (string) $student['email'],
            'recipient_name' => (string) $student['full_name'],
            'project_id' => (int) $projectId,
            'submission_id' => $submissionId,
            'subject' => 'Your ' . strtolower($stageName) . ' was received',
            'heading' => $stageName . ' received',
            'intro' => 'Your ' . strtolower($stageName) . ' was saved successfully. You will receive another notification when its review status changes.',
            'sections' => [[
                'title' => 'Submission details',
                'rows' => [['Submission reference', '#' . $submissionId], ['Stage', $stageName], ['Project title', $projectTitle], ['Submitted item', $originalName], ['Submitted at', date('M j, Y g:i A')], ['Status', 'Submitted']],
            ]],
            'portal_path' => '/students/student_dashboard.php?view=proposal',
        ]);
    }
    if (!$majorRevisionUpload) {
        $deans = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'dean'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($deans as $dean) {
            notify_user_once($pdo, (int) $dean['id'], 'submission-review:' . $submissionId . ':' . (int) $dean['id'], 'New capstone submission', 'A student submitted a ' . strtolower($stageName) . '. Review it and assign an evaluator.', 'dean/dashboard.php#submissions');
            fuzzy_email_notify($pdo, [
                'event_key' => 'submission_for_review:' . $submissionId . ':' . (int) $dean['id'],
                'event_type' => 'submission_for_review',
                'recipient_user_id' => (int) $dean['id'],
                'recipient_email' => (string) $dean['email'],
                'recipient_name' => (string) $dean['full_name'],
                'project_id' => (int) $projectId,
                'submission_id' => $submissionId,
                'subject' => 'A new ' . strtolower($stageName) . ' is awaiting review',
                'heading' => 'New ' . strtolower($stageName),
                'intro' => 'A student submitted a ' . strtolower($stageName) . '. Review the submission and assign an evaluator.',
                'sections' => [['title' => 'Submission details', 'rows' => [['Submission reference', '#' . $submissionId], ['Stage', $stageName], ['Project title', $projectTitle], ['Submitted item', $originalName], ['Status', 'Submitted']]]],
                'portal_path' => '/dean/dashboard.php#submissions',
            ]);
        }
    }
    } catch (Throwable $notificationException) {
        error_log('[proposal submission notifications] ' . $notificationException->getMessage());
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[proposal submission] ' . $exception->getMessage());
    if (!$submissionPersisted && $destination) @unlink($destination);
    header('Location: ' . $redirect . '&error=save_failed');
    exit;
}

header('Location: ' . $redirect . '&uploaded=' . rawurlencode($stage));
exit;
