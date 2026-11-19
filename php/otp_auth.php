<?php

declare(strict_types=1);

function fuzzy_otp_environment_value(string $name): string
{
	$value = getenv($name);
	if ($value === false && isset($_SERVER[$name])) {
		$value = (string) $_SERVER[$name];
	}
	if ($value === false && isset($_ENV[$name])) {
		$value = (string) $_ENV[$name];
	}

	return $value === false ? '' : (string) $value;
}

function fuzzy_otp_mail_config(): ?array
{
	$mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
	$autoload = __DIR__ . '/vendor/autoload.php';
	if (!is_file($autoload)) {
		error_log('[email-otp] SMTP is unavailable: the Composer autoloader is missing.');
		return null;
	}
	require_once $autoload;
	if (!class_exists($mailerClass)) {
		error_log('[email-otp] SMTP is unavailable: PHPMailer is missing from the Composer dependencies.');
		return null;
	}

	$settings = [
		'host' => trim(fuzzy_otp_environment_value('FUZZY_SMTP_HOST')),
		'username' => trim(fuzzy_otp_environment_value('FUZZY_SMTP_USERNAME')),
		'password' => preg_replace('/\s+/', '', fuzzy_otp_environment_value('FUZZY_SMTP_PASSWORD')),
		'from_email' => trim(fuzzy_otp_environment_value('FUZZY_SMTP_FROM_EMAIL')),
		'from_name' => trim(fuzzy_otp_environment_value('FUZZY_SMTP_FROM_NAME')) ?: 'Fuzzy Score',
		'port' => (int) (fuzzy_otp_environment_value('FUZZY_SMTP_PORT') ?: 587),
		'encryption' => strtolower(fuzzy_otp_environment_value('FUZZY_SMTP_ENCRYPTION') ?: 'tls'),
	];

	$invalidSettings = [];
	foreach ([
		'FUZZY_SMTP_HOST' => $settings['host'] !== '',
		'FUZZY_SMTP_USERNAME' => $settings['username'] !== '',
		'FUZZY_SMTP_PASSWORD' => $settings['password'] !== '',
		'FUZZY_SMTP_FROM_EMAIL' => filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL) !== false,
		'FUZZY_SMTP_PORT' => $settings['port'] > 0,
		'FUZZY_SMTP_ENCRYPTION' => in_array($settings['encryption'], ['tls', 'ssl'], true),
	] as $name => $isValid) {
		if (!$isValid) {
			$invalidSettings[] = $name;
		}
	}

	if ($invalidSettings) {
		error_log('[email-otp] SMTP configuration is missing or invalid: ' . implode(', ', $invalidSettings) . '.');
		return null;
	}

	return $settings;
}

function fuzzy_otp_mail_configured(): bool
{
	return fuzzy_otp_mail_config() !== null;
}

function fuzzy_otp_send_email(string $recipient, string $code, string $recipientName = ''): bool
{
	$settings = fuzzy_otp_mail_config();
	if ($settings === null) {
		return false;
	}

	$mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
	$mail = new $mailerClass(true);
	try {
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
		$mail->setFrom($settings['from_email'], $settings['from_name']);
		$mail->addAddress($recipient);
		$mail->isHTML(true);
		$mail->Subject = 'Your Capstone Evaluation System verification code';
		$name = htmlspecialchars($recipientName !== '' ? $recipientName : 'there', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$mail->Body = '<!doctype html><html lang="en"><body style="margin:0;background:#f4f6f5;font-family:Arial,sans-serif;color:#173b43">'
			. '<div style="max-width:620px;margin:24px auto;padding:0 14px"><header style="padding:20px 24px;background:#0d3037;color:#fff;border-radius:10px 10px 0 0">'
			. '<strong style="font-size:16px;letter-spacing:1px">CAPSTONE EVALUATION SYSTEM</strong><div style="margin-top:5px;color:#d4a47d;font-size:11px">BSIT CAPSTONE PORTAL</div></header>'
			. '<main style="padding:26px 24px;background:#fff;border:1px solid #dce6e5;border-top:0;border-radius:0 0 10px 10px">'
			. '<h1 style="margin:0 0 12px;font-size:22px;color:#123b43">Verify Your Email</h1>'
			. '<p style="margin:0 0 16px;line-height:1.6">Hello ' . $name . ', use this one-time code to verify your email address:</p>'
			. '<div style="margin:20px 0;padding:16px;border:1px solid #eadfd4;border-radius:8px;background:#fbf8f5;text-align:center;color:#173b43;font-size:30px;font-weight:bold;letter-spacing:8px">' . $code . '</div>'
			. '<p style="margin:0 0 10px;line-height:1.6;color:#526c70">This code expires in 5 minutes.</p>'
			. '<p style="margin:0;line-height:1.6;color:#526c70">For your security, never share this code with anyone. If you did not request it, you can ignore this email.</p>'
			. '</main></div></body></html>';
		$mail->AltBody = "Hello " . ($recipientName !== '' ? $recipientName : 'there') . ",\n\n"
			. "Your Capstone Evaluation System email verification code is: " . $code . "\n"
			. "This code expires in 5 minutes. Never share it with anyone.\n"
			. "If you did not request it, you can ignore this email.";
		return $mail->send();
	} catch (\Throwable $exception) {
		error_log('[email-otp] SMTP delivery failed.');
		return false;
	}
}

function fuzzy_otp_generate_code(): string
{
	return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function fuzzy_otp_hash_code(string $code, string $sessionToken): string
{
	return hash_hmac('sha256', $code, $sessionToken);
}

function fuzzy_otp_mask_email(string $email): string
{
	[$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');
	if ($localPart === '' || $domain === '') {
		return 'your registered email';
	}

	return substr($localPart, 0, 1) . str_repeat('*', min(max(strlen($localPart) - 1, 3), 8)) . '@' . $domain;
}

function fuzzy_otp_begin(PDO $pdo, int $userId, string $email, string $purpose = 'login', string $sessionKey = 'pending_otp'): string
{
	if (!fuzzy_otp_mail_configured()) {
		return 'not_configured';
	}
	if (!in_array($purpose, ['login', 'student_registration', 'adviser_login'], true)) {
		return 'failed';
	}

	$sessionToken = bin2hex(random_bytes(32));
	$code = fuzzy_otp_generate_code();
	$recipientName = '';
	$pdo->beginTransaction();
	try {
		$accountLock = $pdo->prepare('SELECT id, email, full_name, role, email_verified_at FROM users WHERE id = ? FOR UPDATE');
		$accountLock->execute([$userId]);
		$account = $accountLock->fetch(PDO::FETCH_ASSOC);
		if (!$account) {
			$pdo->commit();
			return 'failed';
		}
		$accountEmail = strtolower(trim((string) $account['email']));
		if (!hash_equals($accountEmail, strtolower(trim($email))) || !filter_var($accountEmail, FILTER_VALIDATE_EMAIL)) {
			$pdo->commit();
			return 'invalid_email';
		}
		if (
			($purpose === 'student_registration' && ($account['role'] !== 'student' || $account['email_verified_at'] !== null))
			|| ($purpose === 'adviser_login' && ($account['role'] !== 'adviser' || $account['email_verified_at'] !== null))
		) {
			$pdo->commit();
			return 'not_pending';
		}
		$recipientName = (string) $account['full_name'];
		$rateLimit = $pdo->prepare('SELECT COALESCE(SUM(send_count), 0) FROM auth_otp_challenges WHERE user_id = ? AND last_sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
		$rateLimit->execute([$userId]);
		if ((int) $rateLimit->fetchColumn() >= 5) {
			$pdo->commit();
			return 'rate_limited';
		}
		$cooldown = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, MAX(last_sent_at), UTC_TIMESTAMP()) FROM auth_otp_challenges WHERE user_id = ?');
		$cooldown->execute([$userId]);
		$secondsSinceLastSend = $cooldown->fetchColumn();
		if ($secondsSinceLastSend !== null && $secondsSinceLastSend !== false && (int) $secondsSinceLastSend < 60) {
			$pdo->commit();
			return 'cooldown';
		}
		$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE user_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
		$invalidate->execute([$userId]);
		$insert = $pdo->prepare('INSERT INTO auth_otp_challenges (user_id, purpose, session_binding_hash, code_hash, expires_at, last_sent_at, created_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), UTC_TIMESTAMP(), UTC_TIMESTAMP())');
		$insert->execute([$userId, $purpose, hash('sha256', $sessionToken), fuzzy_otp_hash_code($code, $sessionToken)]);
		$challengeId = (int) $pdo->lastInsertId();
		$pdo->commit();
	} catch (\Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		error_log('[email-otp] Challenge creation failed.');
		return 'failed';
	}

	$_SESSION[$sessionKey] = [
		'id' => $challengeId,
		'user_id' => $userId,
		'token' => $sessionToken,
		'email' => $accountEmail,
		'name' => $recipientName,
		'purpose' => $purpose,
	];
	if (!fuzzy_otp_send_email($accountEmail, $code, $recipientName)) {
		fuzzy_otp_invalidate_pending($pdo, $sessionKey);
		return 'delivery_failed';
	}

	return 'sent';
}

function fuzzy_otp_pending_row(PDO $pdo, bool $lock = false, string $sessionKey = 'pending_otp'): ?array
{
	$pending = $_SESSION[$sessionKey] ?? null;
	if (!is_array($pending) || empty($pending['id']) || empty($pending['token']) || empty($pending['purpose'])) {
		return null;
	}

	$sql = 'SELECT *, expires_at <= UTC_TIMESTAMP() AS is_expired, TIMESTAMPDIFF(SECOND, last_sent_at, UTC_TIMESTAMP()) AS seconds_since_send, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at) AS seconds_until_expiry FROM auth_otp_challenges WHERE id = ? AND purpose = ? AND session_binding_hash = ? AND consumed_at IS NULL AND invalidated_at IS NULL';
	if ($lock) {
		$sql .= ' FOR UPDATE';
	}
	$stmt = $pdo->prepare($sql);
	$stmt->execute([(int) $pending['id'], (string) $pending['purpose'], hash('sha256', (string) $pending['token'])]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

function fuzzy_otp_resend(PDO $pdo, string $sessionKey = 'pending_otp', ?string $expectedPurpose = null): string
{
	if (!fuzzy_otp_mail_configured()) {
		return 'not_configured';
	}

	$pending = $_SESSION[$sessionKey] ?? null;
	if (!is_array($pending) || empty($pending['id']) || empty($pending['token']) || empty($pending['purpose'])) {
		return 'missing';
	}
	if ($expectedPurpose !== null && $pending['purpose'] !== $expectedPurpose) {
		return 'missing';
	}

	$restartExpiredChallenge = false;
	$pdo->beginTransaction();
	try {
		$row = fuzzy_otp_pending_row($pdo, false, $sessionKey);
		if (!$row) {
			$pdo->commit();
			return 'missing';
		}
		$accountLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
		$accountLock->execute([(int) $row['user_id']]);
		$rateLimit = $pdo->prepare('SELECT COALESCE(SUM(send_count), 0) FROM auth_otp_challenges WHERE user_id = ? AND last_sent_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
		$rateLimit->execute([(int) $row['user_id']]);
		$row = fuzzy_otp_pending_row($pdo, true, $sessionKey);
		if (!$row) {
			$pdo->commit();
			return 'missing';
		}
		if ((int) $row['is_expired'] === 1) {
			if ($row) {
				$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
				$invalidate->execute([(int) $row['id']]);
			}
			$restartExpiredChallenge = true;
		} elseif ((int) $row['attempt_count'] >= 5) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION[$sessionKey]);
			return 'locked';
		}
		if ((int) $row['send_count'] >= 3 || (int) $rateLimit->fetchColumn() >= 5) {
			$pdo->commit();
			return 'rate_limited';
		}
		if (!$restartExpiredChallenge && (int) $row['seconds_since_send'] < 60) {
			$pdo->commit();
			return 'cooldown';
		}

		if (!$restartExpiredChallenge) {
			$code = fuzzy_otp_generate_code();
			$token = (string) $pending['token'];
			$update = $pdo->prepare('UPDATE auth_otp_challenges SET code_hash = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), send_count = send_count + 1, last_sent_at = UTC_TIMESTAMP() WHERE id = ?');
			$update->execute([fuzzy_otp_hash_code($code, $token), (int) $row['id']]);
		}
		$pdo->commit();
	} catch (\Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		error_log('[email-otp] Challenge resend failed.');
		return 'failed';
	}

	if ($restartExpiredChallenge) {
		return fuzzy_otp_begin(
			$pdo,
			(int) $pending['user_id'],
			(string) $pending['email'],
			(string) $pending['purpose'],
			$sessionKey
		);
	}
	if (!fuzzy_otp_send_email((string) $pending['email'], $code, (string) ($pending['name'] ?? ''))) {
		fuzzy_otp_invalidate_pending($pdo, $sessionKey);
		return 'delivery_failed';
	}
	return 'sent';
}

function fuzzy_otp_verify(PDO $pdo, string $code, string $sessionKey = 'pending_otp', ?string $expectedPurpose = null): array
{
	$pdo->beginTransaction();
	try {
		$row = fuzzy_otp_pending_row($pdo, true, $sessionKey);
		if (!$row) {
			$pdo->commit();
			return ['status' => 'missing'];
		}
		if ($expectedPurpose !== null && $row['purpose'] !== $expectedPurpose) {
			$pdo->commit();
			return ['status' => 'missing'];
		}
		if ((int) $row['is_expired'] === 1) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION[$sessionKey]);
			return ['status' => 'expired'];
		}
		if ((int) $row['attempt_count'] >= 5) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION[$sessionKey]);
			return ['status' => 'locked'];
		}

		$pending = $_SESSION[$sessionKey];
		$token = (string) $pending['token'];
		if (!hash_equals((string) $row['code_hash'], fuzzy_otp_hash_code($code, $token))) {
			$attempts = (int) $row['attempt_count'] + 1;
			$update = $pdo->prepare('UPDATE auth_otp_challenges SET attempt_count = ?, invalidated_at = IF(? >= 5, UTC_TIMESTAMP(), invalidated_at) WHERE id = ?');
			$update->execute([$attempts, $attempts, (int) $row['id']]);
			$pdo->commit();
			if ($attempts >= 5) {
				unset($_SESSION[$sessionKey]);
				return ['status' => 'locked'];
			}
			return ['status' => 'invalid', 'attempts_left' => 5 - $attempts];
		}

		$userStmt = $pdo->prepare('SELECT id, email, full_name, role, account_id, department, can_evaluate, student_id_number, group_name, employee_id_number, email_verified_at, student_approved_at FROM users WHERE id = ? LIMIT 1');
		$userStmt->execute([(int) $row['user_id']]);
		$user = $userStmt->fetch(PDO::FETCH_ASSOC);
		$purpose = (string) $row['purpose'];
		if (
			!$user
			|| ($purpose === 'student_registration' && $user['role'] !== 'student')
			|| ($purpose === 'adviser_login' && $user['role'] !== 'adviser')
			|| ($purpose === 'login' && !in_array($user['role'], ['dean', 'evaluator', 'student', 'adviser'], true))
			|| !hash_equals(
				strtolower(trim((string) $user['email'])),
				strtolower(trim((string) ($pending['email'] ?? '')))
			)
		) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION[$sessionKey]);
			return ['status' => 'missing'];
		}

		if (in_array($purpose, ['student_registration', 'adviser_login'], true) && empty($user['email_verified_at'])) {
			$verifyAccount = $pdo->prepare('UPDATE users SET email_verified_at = UTC_TIMESTAMP() WHERE id = ? AND email_verified_at IS NULL');
			$verifyAccount->execute([(int) $user['id']]);
			if ($verifyAccount->rowCount() !== 1) {
				$pdo->rollBack();
				return ['status' => 'missing'];
			}
			$user['email_verified_at'] = gmdate('Y-m-d H:i:s');
		}

		$consume = $pdo->prepare('UPDATE auth_otp_challenges SET consumed_at = UTC_TIMESTAMP() WHERE id = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
		$consume->execute([(int) $row['id']]);
		if ($consume->rowCount() !== 1) {
			$pdo->rollBack();
			return ['status' => 'missing'];
		}
		$pdo->commit();
	} catch (\Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		error_log('[email-otp] Challenge verification failed.');
		return ['status' => 'failed'];
	}

	session_regenerate_id(true);
	unset($_SESSION[$sessionKey]);
	if ($purpose === 'student_registration' && empty($user['student_approved_at'])) {
		unset($_SESSION['user']);
		return ['status' => 'awaiting_approval', 'user' => $user];
	}
	$_SESSION['user'] = $user;
	return ['status' => 'verified', 'user' => $user];
}

function fuzzy_otp_invalidate_pending(PDO $pdo, string $sessionKey = 'pending_otp'): void
{
	$pending = $_SESSION[$sessionKey] ?? null;
	if (is_array($pending) && !empty($pending['id']) && !empty($pending['token'])) {
		$stmt = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ? AND session_binding_hash = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
		$stmt->execute([(int) $pending['id'], hash('sha256', (string) $pending['token'])]);
	}
	unset($_SESSION[$sessionKey]);
}