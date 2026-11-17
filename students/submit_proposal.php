<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/email_notifications.php';
require_once __DIR__ . '/../php/project_links.php';
require_once __DIR__ . '/../php/student_auth.php';
$user = require_verified_student($pdo);
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

$file = $_FILES['proposal'] ?? null;
$projectTitle = trim((string) ($_POST['project_title'] ?? ''));
$googleDriveUrl = trim((string) ($_POST['google_drive_url'] ?? ''));
$githubUrl = trim((string) ($_POST['github_url'] ?? ''));
$otherProjectUrl = trim((string) ($_POST['other_project_url'] ?? ''));
$legacySubmissionUrl = trim((string) ($_POST['submission_url'] ?? ''));
$hasUpload = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
$destination = null;
$existingTitleStmt = $pdo->prepare('SELECT title FROM projects WHERE id = ? AND student_id = ? LIMIT 1');
$existingTitleStmt->execute([(int) $projectId, $studentId]);
$existingProjectTitle = trim((string) $existingTitleStmt->fetchColumn());

$resourceLinks = array_filter([$googleDriveUrl, $githubUrl, $otherProjectUrl, $legacySubmissionUrl]);
if (!$hasUpload && !$resourceLinks) {
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


    $storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'submissions';
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

try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM student_submissions LIKE 'submission_url'")->fetch(PDO::FETCH_ASSOC);
    if (!$columnCheck) {
        $pdo->exec('ALTER TABLE student_submissions ADD COLUMN submission_url VARCHAR(1000) DEFAULT NULL AFTER stored_filename');
    }
    $projectColumns = $pdo->query("SHOW COLUMNS FROM projects WHERE Field IN ('google_drive_url', 'github_url', 'other_project_url')")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('google_drive_url', $projectColumns, true)) $pdo->exec('ALTER TABLE projects ADD COLUMN google_drive_url VARCHAR(1000) DEFAULT NULL AFTER description');
    if (!in_array('github_url', $projectColumns, true)) $pdo->exec('ALTER TABLE projects ADD COLUMN github_url VARCHAR(1000) DEFAULT NULL AFTER google_drive_url');
    if (!in_array('other_project_url', $projectColumns, true)) $pdo->exec('ALTER TABLE projects ADD COLUMN other_project_url VARCHAR(1000) DEFAULT NULL AFTER github_url');
    $pdo->prepare('UPDATE projects SET title = ?, google_drive_url = ?, github_url = ?, other_project_url = ? WHERE id = ? AND student_id = ?')->execute([$projectTitle, $googleDriveUrl ?: null, $githubUrl ?: null, $otherProjectUrl ?: null, (int) $projectId, $studentId]);
    $stmt = $pdo->prepare('INSERT INTO student_submissions (student_id, project_id, original_filename, stored_filename, submission_url, mime_type, file_size, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$studentId, (int) $projectId, $originalName, $storedName, $hasUpload ? null : ($legacySubmissionUrl ?: null), $mime, $hasUpload ? (int) $file['size'] : $fileSize, 'submitted']);
    $submissionId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE projects SET status = 'Submitted' WHERE id = ? AND student_id = ? AND status IN ('Draft', 'No proposal submitted')")->execute([(int) $projectId, $studentId]);
    notify_user_once($pdo, $studentId, 'proposal-submitted:' . $submissionId . ':' . $studentId, 'Proposal submission recorded', 'Your proposal was submitted successfully and is now awaiting review.', 'students/student_dashboard.php?view=proposal');
    $studentStmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $studentStmt->execute([$studentId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
    if ($student) {
        fuzzy_email_notify($pdo, [
            'event_key' => 'submission_received:' . $submissionId . ':' . $studentId,
            'event_type' => 'submission_received',
            'recipient_user_id' => $studentId,
            'recipient_email' => (string) $student['email'],
            'recipient_name' => (string) $student['full_name'],
            'project_id' => (int) $projectId,
            'submission_id' => $submissionId,
            'subject' => 'Your capstone submission was received',
            'heading' => 'Submission received',
            'intro' => 'Your project submission was saved successfully. You will receive another notification when its review status changes.',
            'sections' => [[
                'title' => 'Submission details',
                'rows' => [['Submission reference', '#' . $submissionId], ['Project title', $projectTitle], ['Submitted item', $originalName], ['Submitted at', date('M j, Y g:i A')], ['Status', 'Submitted']],
            ]],
            'portal_path' => '/students/student_dashboard.php?view=proposal',
        ]);
    }
    $deans = $pdo->query("SELECT id, full_name, email FROM users WHERE role = 'dean'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($deans as $dean) {
        notify_user_once($pdo, (int) $dean['id'], 'submission-review:' . $submissionId . ':' . (int) $dean['id'], 'New capstone submission', 'A student submitted a capstone proposal. Review it and assign an evaluator.', 'dean/dashboard.php#submissions');
        fuzzy_email_notify($pdo, [
            'event_key' => 'submission_for_review:' . $submissionId . ':' . (int) $dean['id'],
            'event_type' => 'submission_for_review',
            'recipient_user_id' => (int) $dean['id'],
            'recipient_email' => (string) $dean['email'],
            'recipient_name' => (string) $dean['full_name'],
            'project_id' => (int) $projectId,
            'submission_id' => $submissionId,
            'subject' => 'A new capstone submission is awaiting review',
            'heading' => 'New capstone submission',
            'intro' => 'A student submitted a capstone proposal. Review the submission and assign an evaluator.',
            'sections' => [['title' => 'Submission details', 'rows' => [['Submission reference', '#' . $submissionId], ['Project title', $projectTitle], ['Submitted item', $originalName], ['Status', 'Submitted']]]],
            'portal_path' => '/dean/dashboard.php#submissions',
        ]);
    }
} catch (Throwable $exception) {
    error_log('[proposal submission] ' . $exception->getMessage());
    if ($destination) @unlink($destination);
    header('Location: ' . $redirect . '&error=save_failed');
    exit;
}

header('Location: ' . $redirect . '&uploaded=1');
exit;