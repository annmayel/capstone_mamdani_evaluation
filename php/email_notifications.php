<?php

declare(strict_types=1);

require_once __DIR__ . '/otp_auth.php';
require_once __DIR__ . '/fuzzy_engine.php';

function fuzzy_email_escape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fuzzy_email_portal_url(string $path): string
{
	$baseUrl = rtrim(trim((string) getenv('FUZZY_PORTAL_BASE_URL')), '/');
	if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
		return '';
	}

	$scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
	if (!in_array($scheme, ['http', 'https'], true) || parse_url($baseUrl, PHP_URL_USER) !== null || parse_url($baseUrl, PHP_URL_PASS) !== null) {
		return '';
	}

	return $baseUrl . '/' . ltrim($path, '/');
}

function fuzzy_email_render(string $recipientName, string $heading, string $intro, array $sections, string $portalPath): array
{
	$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
		. '<body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#173b43"><div style="max-width:680px;margin:24px auto;padding:0 14px">'
		. '<div style="padding:20px 24px;background:#0d3037;color:#fff;border-radius:10px 10px 0 0"><strong style="font-size:16px;letter-spacing:1px">CAPSTONE FUZZY</strong><div style="margin-top:5px;color:#d4a47d;font-size:11px">STUDENT PORTAL</div></div>'
		. '<main style="padding:24px;background:#fff;border:1px solid #dce6e5;border-top:0;border-radius:0 0 10px 10px">'
		. '<p style="margin:0 0 8px;color:#b58863;font-size:11px;font-weight:bold;text-transform:uppercase">Portal notification</p>'
		. '<h1 style="margin:0 0 12px;font-size:22px;color:#123b43">' . fuzzy_email_escape($heading) . '</h1>'
		. '<p style="margin:0 0 8px">Hello ' . fuzzy_email_escape($recipientName) . ',</p>'
		. '<p style="margin:0 0 20px;line-height:1.6;color:#526c70">' . nl2br(fuzzy_email_escape($intro)) . '</p>';
	$text = $heading . "\n\nHello " . $recipientName . ",\n\n" . $intro . "\n";

	foreach ($sections as $section) {
		$sectionTitle = (string) ($section['title'] ?? '');
		$html .= '<h2 style="margin:22px 0 8px;padding-bottom:7px;border-bottom:1px solid #e6eceb;font-size:14px;color:#123b43">' . fuzzy_email_escape($sectionTitle) . '</h2>'
			. '<table role="presentation" style="width:100%;border-collapse:collapse">';
		$text .= "\n" . $sectionTitle . "\n";
		foreach (($section['rows'] ?? []) as $row) {
			$label = (string) ($row[0] ?? '');
			$value = (string) ($row[1] ?? '');
			$html .= '<tr><td style="width:38%;padding:8px 10px 8px 0;border-bottom:1px solid #edf1f0;color:#6b8184;font-size:12px;vertical-align:top">' . fuzzy_email_escape($label) . '</td>'
				. '<td style="padding:8px 0;border-bottom:1px solid #edf1f0;color:#214f56;font-size:12px;line-height:1.5;vertical-align:top">' . nl2br(fuzzy_email_escape($value)) . '</td></tr>';
			$text .= $label . ': ' . $value . "\n";
		}
		$html .= '</table>';
	}

	$url = fuzzy_email_portal_url($portalPath);
	if ($url !== '') {
		$html .= '<p style="margin:24px 0 10px"><a href="' . fuzzy_email_escape($url) . '" style="display:inline-block;padding:11px 16px;border-radius:6px;background:#b58863;color:#fff;text-decoration:none;font-weight:bold;font-size:12px">View in Capstone Portal</a></p>';
		$text .= "\nView in Capstone Portal: " . $url . "\n";
	}
	$html .= '<p style="margin:22px 0 0;color:#87999a;font-size:11px;line-height:1.5">This is an automated message from the Capstone Fuzzy Student Portal. Do not reply to this email.</p></main></div></body></html>';

	return [$html, $text];
}

function fuzzy_email_enqueue(PDO $pdo, array $record): ?int
{
	$email = trim((string) ($record['recipient_email'] ?? ''));
	$recipientUserId = (int) ($record['recipient_user_id'] ?? 0);
	$eventKey = trim((string) ($record['event_key'] ?? ''));
	if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $recipientUserId <= 0 || $eventKey === '') {
		error_log('[fuzzy-email] Notification not queued: recipient email is invalid.');
		return null;
	}

	try {
		$recipientStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
		$recipientStmt->execute([$recipientUserId]);
		$registeredEmail = $recipientStmt->fetchColumn();
		if (!is_string($registeredEmail) || strcasecmp(trim($registeredEmail), $email) !== 0) {
			error_log('[fuzzy-email] Notification not queued: recipient does not match the registered account.');
			return null;
		}

		$key = hash('sha256', $eventKey);
		$stmt = $pdo->prepare(
			'INSERT INTO email_notifications (event_key, event_type, recipient_user_id, recipient_email, project_id, submission_id, evaluation_id, subject, html_body, text_body, delivery_status)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')
			 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
		);
		$stmt->execute([
			$key,
			(string) $record['event_type'],
			(int) $record['recipient_user_id'],
			$email,
			$record['project_id'] ?? null,
			$record['submission_id'] ?? null,
			$record['evaluation_id'] ?? null,
			(string) $record['subject'],
			(string) $record['html_body'],
			(string) $record['text_body'],
		]);
		return (int) $pdo->lastInsertId();
	} catch (PDOException $exception) {
		error_log('[fuzzy-email] Could not queue notification: ' . $exception->getMessage());
		return null;
	}
}

function fuzzy_email_try_dispatch(PDO $pdo, ?int $notificationId): void
{
	if (!$notificationId) {
		return;
	}

	try {
		fuzzy_email_dispatch($pdo, $notificationId);
	} catch (Throwable $exception) {
		error_log('[fuzzy-email] Dispatch failed for notification ' . $notificationId . ': ' . $exception->getMessage());
	}
}

function fuzzy_email_dispatch(PDO $pdo, int $notificationId, bool $manualRetry = false): bool
{
	$settings = fuzzy_otp_mail_config();
	if ($settings === null) {
		$pdo->prepare("UPDATE email_notifications SET last_error = 'SMTP is not configured.', next_attempt_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id = ? AND delivery_status IN ('pending', 'failed')")->execute([$notificationId]);
		return false;
	}

	if ($manualRetry) {
		$pdo->prepare("UPDATE email_notifications SET delivery_status = 'pending', attempt_count = 0, next_attempt_at = NULL WHERE id = ? AND delivery_status = 'failed' AND attempt_count >= 5")->execute([$notificationId]);
	}

	$claim = $pdo->prepare(
		"UPDATE email_notifications SET delivery_status = 'sending', attempt_count = attempt_count + 1,
			last_attempt_at = NOW(), last_error = NULL
		 WHERE id = ? AND delivery_status IN ('pending', 'failed') AND attempt_count < 5
		   AND (? = 1 OR next_attempt_at IS NULL OR next_attempt_at <= NOW())"
	);
	$claim->execute([$notificationId, $manualRetry ? 1 : 0]);
	if ($claim->rowCount() !== 1) {
		return false;
	}

	$stmt = $pdo->prepare('SELECT event_key, recipient_email, subject, html_body, text_body FROM email_notifications WHERE id = ?');
	$stmt->execute([$notificationId]);
	$message = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$message) {
		$pdo->prepare("UPDATE email_notifications SET delivery_status = 'failed', last_error = 'Queued message not found.' WHERE id = ?")->execute([$notificationId]);
		return false;
	}

	$mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
	try {
		$mail = new $mailerClass(true);
		$mail->isSMTP();
		$mail->Host = $settings['host'];
		$mail->SMTPAuth = true;
		$mail->Username = $settings['username'];
		$mail->Password = $settings['password'];
		$mail->Port = $settings['port'];
		$mail->SMTPSecure = $settings['encryption'] === 'ssl'
			? constant($mailerClass . '::ENCRYPTION_SMTPS')
			: constant($mailerClass . '::ENCRYPTION_STARTTLS');
		$mail->Timeout = 10;
		$mail->CharSet = 'UTF-8';
		$mail->isHTML(true);
		$mail->setFrom($settings['from_email'], $settings['from_name']);
		$mail->addAddress((string) $message['recipient_email']);
		$senderDomain = substr(strrchr($settings['from_email'], '@') ?: '', 1) ?: 'localhost';
		$mail->MessageID = '<' . $message['event_key'] . '@' . $senderDomain . '>';
		$mail->Subject = (string) $message['subject'];
		$mail->Body = (string) $message['html_body'];
		$mail->AltBody = (string) $message['text_body'];
		$mail->send();

		$pdo->prepare("UPDATE email_notifications SET delivery_status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")->execute([$notificationId]);
		return true;
	} catch (Throwable $exception) {
		$error = substr(preg_replace('/[\r\n\t]+/', ' ', strip_tags($exception->getMessage())) ?: 'Mail delivery failed.', 0, 1000);
		foreach ([$settings['username'], $settings['password'], $settings['from_email'], (string) $message['recipient_email']] as $sensitiveValue) {
			if ($sensitiveValue !== '') {
				$error = str_ireplace($sensitiveValue, '[redacted]', $error);
			}
		}
		$attemptStmt = $pdo->prepare('SELECT attempt_count FROM email_notifications WHERE id = ?');
		$attemptStmt->execute([$notificationId]);
		$attemptCount = (int) $attemptStmt->fetchColumn();
		$retryDelay = match ($attemptCount) {
			1 => 60,
			2 => 300,
			3 => 900,
			4 => 3600,
			default => null,
		};
		$update = $pdo->prepare(
			"UPDATE email_notifications
			 SET delivery_status = IF(attempt_count < 5, 'pending', 'failed'),
			     last_error = 'Mail delivery failed. Check the restricted server log for details.',
			     next_attempt_at = IF(? IS NULL, NULL, DATE_ADD(NOW(), INTERVAL ? SECOND))
			 WHERE id = ?"
		);
		$update->execute([$retryDelay, $retryDelay, $notificationId]);
		error_log('[fuzzy-email] Delivery failed for notification ' . $notificationId . ' (' . get_class($exception) . '): ' . $error);
		return false;
	}
}

function fuzzy_email_notify(PDO $pdo, array $event): ?int
{
	try {
		[$html, $text] = fuzzy_email_render(
			(string) ($event['recipient_name'] ?? 'User'),
			(string) $event['heading'],
			(string) $event['intro'],
			(array) ($event['sections'] ?? []),
			(string) ($event['portal_path'] ?? '')
		);
		$id = fuzzy_email_enqueue($pdo, [
			'event_key' => (string) $event['event_key'],
			'event_type' => (string) $event['event_type'],
			'recipient_user_id' => (int) $event['recipient_user_id'],
			'recipient_email' => (string) $event['recipient_email'],
			'project_id' => $event['project_id'] ?? null,
			'submission_id' => $event['submission_id'] ?? null,
			'evaluation_id' => $event['evaluation_id'] ?? null,
			'subject' => (string) $event['subject'],
			'html_body' => $html,
			'text_body' => $text,
		]);
		fuzzy_email_try_dispatch($pdo, $id);
		return $id;
	} catch (Throwable $exception) {
		error_log('[fuzzy-email] Could not create notification: ' . $exception->getMessage());
		return null;
	}
}

function fuzzy_email_evaluation_context(PDO $pdo, int $projectId): ?array
{
	$stmt = $pdo->prepare(
		"SELECT p.id, p.title, p.status, p.project_score, p.paper_score, p.overall_score, p.overall_rating,
			u.id AS student_id, u.full_name AS student_name, u.email AS student_email
		 FROM projects p INNER JOIN users u ON u.id = p.student_id AND u.role = 'student'
		 WHERE p.id = ? LIMIT 1"
	);
	$stmt->execute([$projectId]);
	$context = $stmt->fetch(PDO::FETCH_ASSOC);
	return $context ?: null;
}

function fuzzy_email_component_report(PDO $pdo, int $projectId, int $evaluationId, string $type): void
{
	$context = fuzzy_email_evaluation_context($pdo, $projectId);
	if (!$context) {
		return;
	}

	if ($type === 'system') {
		$stmt = $pdo->prepare('SELECT e.*, u.full_name AS evaluator_name FROM evaluations_project e LEFT JOIN users u ON u.id = e.evaluator_id WHERE e.id = ? AND e.project_id = ? LIMIT 1');
	} else {
		$stmt = $pdo->prepare('SELECT e.*, u.full_name AS evaluator_name FROM evaluations_paper e LEFT JOIN users u ON u.id = e.evaluator_id WHERE e.id = ? AND e.project_id = ? LIMIT 1');
	}
	$stmt->execute([$evaluationId, $projectId]);
	$evaluation = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$evaluation) {
		return;
	}

	$evaluationType = $type === 'system' ? 'System Evaluation' : 'Paper Evaluation';
	$scoreRows = [];
	if ($type === 'system') {
		$labels = [
			'functionality' => 'Functionality',
			'technical_quality' => 'Technical Quality',
			'performance' => 'Performance',
			'usability' => 'Usability',
			'uiux' => 'User Interface / UX',
			'security' => 'Security',
			'innovation' => 'Innovation',
			'relevance' => 'Relevance',
			'reliability' => 'Reliability',
			'completeness' => 'Completeness',
		];
		foreach ($labels as $field => $label) {
			$scoreRows[] = [$label, number_format((float) ($evaluation[$field] ?? 0) * 10, 0) . ' / 100'];
		}
		$scoreRows[] = ['System evaluation score', number_format((float) ($evaluation['total_score'] ?? 0), 2) . ' / 100'];
	} else {
		$chapters = [];
		foreach ($evaluation as $field => $score) {
			if (preg_match('/^chap(\d+)$/', (string) $field, $matches) && $score !== null) {
				$chapters[(int) $matches[1]] = (float) $score;
			}
		}
		ksort($chapters);
		foreach ($chapters as $chapter => $score) {
			$scoreRows[] = ['Chapter ' . $chapter, number_format($score, 2) . ' / 20 (' . number_format($score * 5, 0) . ' / 100 equivalent)'];
		}
		$scoreRows[] = ['Paper evaluation score', number_format((float) ($evaluation['total_score'] ?? 0), 2) . ' / 100'];
	}
	$scoreRows[] = ['Evaluator', (string) ($evaluation['evaluator_name'] ?? 'Evaluator')];
	$scoreRows[] = ['Evaluation date', (string) ($evaluation['created_at'] ?? '')];
	$otherTable = $type === 'system' ? 'evaluations_paper' : 'evaluations_project';
	$otherStmt = $pdo->prepare('SELECT id FROM ' . $otherTable . ' WHERE project_id = ? ORDER BY id DESC LIMIT 1');
	$otherStmt->execute([$projectId]);
	$hasOtherEvaluation = (bool) $otherStmt->fetchColumn();
	$pendingComponent = $type === 'system' ? 'Paper evaluation' : 'System evaluation';
	$scoreRows[] = ['Evaluation status', $hasOtherEvaluation ? 'Both evaluation components are saved.' : $pendingComponent . ' is still pending; no official CEIT-T07B verdict is available yet.'];
	$scoreRows[] = ['Project status', (string) ($context['status'] ?? 'Under evaluation')];
	$scoreRows[] = ['Evaluator remarks', trim((string) ($evaluation['remarks'] ?? '')) ?: 'No evaluator remarks were recorded.'];

	fuzzy_email_notify($pdo, [
		'event_key' => $type . '_evaluation:' . $evaluationId . ':' . (int) $context['student_id'],
		'event_type' => $type . '_evaluation',
		'recipient_user_id' => (int) $context['student_id'],
		'recipient_email' => (string) $context['student_email'],
		'recipient_name' => (string) $context['student_name'],
		'project_id' => $projectId,
		'evaluation_id' => $evaluationId,
		'subject' => $evaluationType . ' results are available',
		'heading' => $evaluationType . ' updated',
		'intro' => 'Your ' . strtolower($evaluationType) . ' has been saved. Review the recorded scores and evaluator remarks below.',
		'sections' => [
			['title' => 'Project', 'rows' => [['Title', (string) $context['title']]]],
			['title' => $evaluationType, 'rows' => $scoreRows],
			['title' => 'Next step', 'rows' => [['Action', 'Sign in to the portal to review the evaluation and any required revisions.']]],
		],
		'portal_path' => '/students/student_dashboard.php?view=results',
	]);
}

function fuzzy_email_try_component_report(PDO $pdo, int $projectId, int $evaluationId, string $type): void
{
	try {
		fuzzy_email_component_report($pdo, $projectId, $evaluationId, $type);
	} catch (Throwable $exception) {
		error_log('[fuzzy-email] Could not prepare ' . $type . ' report: ' . $exception->getMessage());
	}
}

function fuzzy_email_final_report(PDO $pdo, int $projectId, array $fuzzyResult): void
{
	$context = fuzzy_email_evaluation_context($pdo, $projectId);
	if (!$context) {
		return;
	}

	$projectStmt = $pdo->prepare('SELECT * FROM evaluations_project WHERE project_id = ? ORDER BY id DESC LIMIT 1');
	$projectStmt->execute([$projectId]);
	$projectEvaluation = $projectStmt->fetch(PDO::FETCH_ASSOC);
	$paperStmt = $pdo->prepare('SELECT * FROM evaluations_paper WHERE project_id = ? ORDER BY id DESC LIMIT 1');
	$paperStmt->execute([$projectId]);
	$paperEvaluation = $paperStmt->fetch(PDO::FETCH_ASSOC);
	if (!$projectEvaluation || !$paperEvaluation) {
		return;
	}

	$systemRows = [['System evaluation score', number_format((float) $context['project_score'], 2) . ' / 100']];
	$systemLabels = [
		'functionality' => 'Functionality',
		'technical_quality' => 'Technical Quality',
		'performance' => 'Performance',
		'usability' => 'Usability',
		'uiux' => 'User Interface / UX',
		'security' => 'Security',
		'innovation' => 'Innovation',
		'relevance' => 'Relevance',
		'reliability' => 'Reliability',
		'completeness' => 'Completeness',
	];
	foreach ($systemLabels as $field => $label) {
		$systemRows[] = [$label, number_format((float) ($projectEvaluation[$field] ?? 0) * 10, 0) . ' / 100'];
	}
	$paperRows = [['Paper evaluation score', number_format((float) $context['paper_score'], 2) . ' / 100']];
	$chapters = [];
	foreach ($paperEvaluation as $field => $score) {
		if (preg_match('/^chap(\d+)$/', (string) $field, $matches) && $score !== null) {
			$chapters[(int) $matches[1]] = (float) $score;
		}
	}
	ksort($chapters);
	foreach ($chapters as $chapter => $score) {
		$paperRows[] = ['Chapter ' . $chapter, number_format($score, 2) . ' / 20 (' . number_format($score * 5, 0) . ' / 100 equivalent)'];
	}
	$systemEvaluatorStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
	$systemEvaluatorStmt->execute([(int) $projectEvaluation['evaluator_id']]);
	$systemRows[] = ['System evaluator', (string) ($systemEvaluatorStmt->fetchColumn() ?: 'Evaluator')];
	$systemRows[] = ['System evaluation date', (string) ($projectEvaluation['created_at'] ?? '')];
	$paperRows[] = ['Paper evaluator remarks', trim((string) ($paperEvaluation['remarks'] ?? '')) ?: 'No evaluator remarks were recorded.'];
	$systemRows[] = ['System evaluator remarks', trim((string) ($projectEvaluation['remarks'] ?? '')) ?: 'No evaluator remarks were recorded.'];
	$paperEvaluatorStmt = $pdo->prepare('SELECT full_name FROM users WHERE id = ? LIMIT 1');
	$paperEvaluatorStmt->execute([(int) $paperEvaluation['evaluator_id']]);
	$paperRows[] = ['Paper evaluator', (string) ($paperEvaluatorStmt->fetchColumn() ?: 'Evaluator')];
	$paperRows[] = ['Paper evaluation date', (string) ($paperEvaluation['created_at'] ?? '')];
	$generatedAt = max(strtotime((string) $projectEvaluation['created_at']), strtotime((string) $paperEvaluation['created_at']));
	$officialVerdict = official_verdict_from_score((float) $fuzzyResult['final_score']);

	$fuzzyRows = [
		['Official final average score', number_format((float) $fuzzyResult['final_score'], 2) . ' / 100'],
		['Rounded classification score', (string) $officialVerdict['rounded_score'] . ' / 100'],
		['Official CEIT-T07B verdict', $officialVerdict['label']],
		['Verdict description', $officialVerdict['description']],
		['Recommended next step', $officialVerdict['next_step']],
		['Internal Mamdani defuzzified output (analysis only)', number_format((float) $fuzzyResult['defuzz'], 2) . ' / 100'],
		['Score formula', 'Unweighted arithmetic mean of the panel-average system and paper scores; equal component weighting requires institutional confirmation.'],
		['Final evaluation status', 'Complete; both panel-average components are available'],
		['Result generated', date('M j, Y g:i A', $generatedAt)],
	];
	$degrees = (array) ($fuzzyResult['degrees'] ?? []);
	if ($degrees) {
		$degreeRows = [];
		foreach ($degrees as $label => $degree) {
			$degreeRows[] = [(string) $label, number_format((float) $degree, 3)];
		}
		$fuzzyRows[] = ['Fuzzy output membership values', implode('; ', array_map(static fn($row) => $row[0] . ': ' . $row[1], $degreeRows))];
	}

	$projectEvaluationId = (int) $projectEvaluation['id'];
	$paperEvaluationId = (int) $paperEvaluation['id'];
	fuzzy_email_notify($pdo, [
		'event_key' => 'final_fuzzy_result:' . $projectEvaluationId . ':' . $paperEvaluationId . ':' . (int) $context['student_id'],
		'event_type' => 'final_fuzzy_result',
		'recipient_user_id' => (int) $context['student_id'],
		'recipient_email' => (string) $context['student_email'],
		'recipient_name' => (string) $context['student_name'],
		'project_id' => $projectId,
		'evaluation_id' => max($projectEvaluationId, $paperEvaluationId),
		'subject' => 'Your final capstone evaluation results are available',
		'heading' => 'Final capstone evaluation report',
		'intro' => 'Both evaluation components have been saved. This report uses the final score and rating already stored by the portal fuzzy engine.',
		'sections' => [
			['title' => 'Project', 'rows' => [['Title', (string) $context['title']], ['Project status', (string) ($context['status'] ?? '')]]],
			['title' => 'System Evaluation', 'rows' => $systemRows],
			['title' => 'Paper Evaluation', 'rows' => $paperRows],
			['title' => 'Official CEIT-T07B Verdict and Fuzzy Analysis', 'rows' => $fuzzyRows],
		],
		'portal_path' => '/students/student_dashboard.php?view=results',
	]);
}

function fuzzy_email_try_final_report(PDO $pdo, int $projectId, array $fuzzyResult): void
{
	try {
		fuzzy_email_final_report($pdo, $projectId, $fuzzyResult);
	} catch (Throwable $exception) {
		error_log('[fuzzy-email] Could not prepare final evaluation report: ' . $exception->getMessage());
	}
}
