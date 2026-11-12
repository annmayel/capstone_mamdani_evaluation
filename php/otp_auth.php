<?php

declare(strict_types=1);

function fuzzy_otp_mail_config(): ?array
{
	$mailerClass = 'PHPMailer\\PHPMailer\\PHPMailer';
	$autoload = __DIR__ . '/vendor/autoload.php';
	if (!is_file($autoload)) {
		return null;
	}
	require_once $autoload;

	$settings = [
		'host' => getenv('FUZZY_SMTP_HOST') ?: '',
		'username' => getenv('FUZZY_SMTP_USERNAME') ?: '',
		'password' => getenv('FUZZY_SMTP_PASSWORD') ?: '',
		'from_email' => getenv('FUZZY_SMTP_FROM_EMAIL') ?: '',
		'from_name' => getenv('FUZZY_SMTP_FROM_NAME') ?: 'Fuzzy Score',
		'port' => (int) (getenv('FUZZY_SMTP_PORT') ?: 587),
		'encryption' => strtolower((string) (getenv('FUZZY_SMTP_ENCRYPTION') ?: 'tls')),
	];

	if (
		!class_exists($mailerClass)
		|| $settings['host'] === ''
		|| $settings['username'] === ''
		|| $settings['password'] === ''
		|| !filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)
		|| $settings['port'] < 1
		|| !in_array($settings['encryption'], ['tls', 'ssl'], true)
	) {
		return null;
	}

	return $settings;
}

function fuzzy_otp_mail_configured(): bool
{
	return fuzzy_otp_mail_config() !== null;
}

function fuzzy_otp_send_email(string $recipient, string $code): bool
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
		$mail->Subject = 'Your Fuzzy Score verification code';
		$mail->Body = 'Your verification code is ' . $code . '. It expires in 5 minutes. Never share this code with anyone.';
		$mail->AltBody = $mail->Body;
		return $mail->send();
	} catch (\Throwable $exception) {
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

function fuzzy_otp_begin(PDO $pdo, int $userId, string $email): string
{
	if (!fuzzy_otp_mail_configured()) {
		return 'not_configured';
	}

	$sessionToken = bin2hex(random_bytes(32));
	$code = fuzzy_otp_generate_code();
	$pdo->beginTransaction();
	try {
		$accountLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
		$accountLock->execute([$userId]);
		if (!$accountLock->fetchColumn()) {
			$pdo->commit();
			return 'failed';
		}
		$rateLimit = $pdo->prepare('SELECT COALESCE(SUM(send_count), 0) FROM auth_otp_challenges WHERE user_id = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
		$rateLimit->execute([$userId]);
		if ((int) $rateLimit->fetchColumn() >= 5) {
			$pdo->commit();
			return 'rate_limited';
		}
		$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE user_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
		$invalidate->execute([$userId]);
		$insert = $pdo->prepare('INSERT INTO auth_otp_challenges (user_id, session_binding_hash, code_hash, expires_at, last_sent_at, created_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), UTC_TIMESTAMP(), UTC_TIMESTAMP())');
		$insert->execute([$userId, hash('sha256', $sessionToken), fuzzy_otp_hash_code($code, $sessionToken)]);
		$challengeId = (int) $pdo->lastInsertId();
		$pdo->commit();
	} catch (\Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return 'failed';
	}

	$_SESSION['pending_otp'] = [
		'id' => $challengeId,
		'token' => $sessionToken,
		'email' => $email,
	];
	if (!fuzzy_otp_send_email($email, $code)) {
		fuzzy_otp_invalidate_pending($pdo);
		return 'delivery_failed';
	}

	return 'sent';
}

function fuzzy_otp_pending_row(PDO $pdo, bool $lock = false): ?array
{
	$pending = $_SESSION['pending_otp'] ?? null;
	if (!is_array($pending) || empty($pending['id']) || empty($pending['token'])) {
		return null;
	}

	$sql = 'SELECT *, expires_at <= UTC_TIMESTAMP() AS is_expired, TIMESTAMPDIFF(SECOND, last_sent_at, UTC_TIMESTAMP()) AS seconds_since_send FROM auth_otp_challenges WHERE id = ? AND session_binding_hash = ? AND consumed_at IS NULL AND invalidated_at IS NULL';
	if ($lock) {
		$sql .= ' FOR UPDATE';
	}
	$stmt = $pdo->prepare($sql);
	$stmt->execute([(int) $pending['id'], hash('sha256', (string) $pending['token'])]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

function fuzzy_otp_resend(PDO $pdo): string
{
	if (!fuzzy_otp_mail_configured()) {
		return 'not_configured';
	}

	$pending = $_SESSION['pending_otp'] ?? null;
	if (!is_array($pending) || empty($pending['id']) || empty($pending['token'])) {
		return 'missing';
	}

	$pdo->beginTransaction();
	try {
		$row = fuzzy_otp_pending_row($pdo);
		if (!$row) {
			$pdo->commit();
			return 'missing';
		}
		$accountLock = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
		$accountLock->execute([(int) $row['user_id']]);
		$rateLimit = $pdo->prepare('SELECT COALESCE(SUM(send_count), 0) FROM auth_otp_challenges WHERE user_id = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
		$rateLimit->execute([(int) $row['user_id']]);
		$row = fuzzy_otp_pending_row($pdo, true);
		if (!$row || (int) $row['is_expired'] === 1 || (int) $row['attempt_count'] >= 5) {
			if ($row) {
				$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
				$invalidate->execute([(int) $row['id']]);
			}
			$pdo->commit();
			unset($_SESSION['pending_otp']);
			return 'expired';
		}
		if ((int) $row['send_count'] >= 3 || (int) $rateLimit->fetchColumn() >= 5) {
			$pdo->commit();
			return 'rate_limited';
		}
		if ((int) $row['seconds_since_send'] < 60) {
			$pdo->commit();
			return 'cooldown';
		}

		$code = fuzzy_otp_generate_code();
		$token = (string) $pending['token'];
		$update = $pdo->prepare('UPDATE auth_otp_challenges SET code_hash = ?, expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE), send_count = send_count + 1, last_sent_at = UTC_TIMESTAMP() WHERE id = ?');
		$update->execute([fuzzy_otp_hash_code($code, $token), (int) $row['id']]);
		$pdo->commit();
	} catch (\Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		return 'failed';
	}

	if (!fuzzy_otp_send_email((string) $pending['email'], $code)) {
		fuzzy_otp_invalidate_pending($pdo);
		return 'delivery_failed';
	}
	return 'sent';
}

function fuzzy_otp_verify(PDO $pdo, string $code): array
{
	$pdo->beginTransaction();
	try {
		$row = fuzzy_otp_pending_row($pdo, true);
		if (!$row) {
			$pdo->commit();
			return ['status' => 'missing'];
		}
		if ((int) $row['is_expired'] === 1) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION['pending_otp']);
			return ['status' => 'expired'];
		}
		if ((int) $row['attempt_count'] >= 5) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION['pending_otp']);
			return ['status' => 'locked'];
		}

		$token = (string) $_SESSION['pending_otp']['token'];
		if (!hash_equals((string) $row['code_hash'], fuzzy_otp_hash_code($code, $token))) {
			$attempts = (int) $row['attempt_count'] + 1;
			$update = $pdo->prepare('UPDATE auth_otp_challenges SET attempt_count = ?, invalidated_at = IF(? >= 5, UTC_TIMESTAMP(), invalidated_at) WHERE id = ?');
			$update->execute([$attempts, $attempts, (int) $row['id']]);
			$pdo->commit();
			if ($attempts >= 5) {
				unset($_SESSION['pending_otp']);
				return ['status' => 'locked'];
			}
			return ['status' => 'invalid', 'attempts_left' => 5 - $attempts];
		}

		$userStmt = $pdo->prepare('SELECT id, email, full_name, role, account_id, department, can_evaluate FROM users WHERE id = ? LIMIT 1');
		$userStmt->execute([(int) $row['user_id']]);
		$user = $userStmt->fetch(PDO::FETCH_ASSOC);
		if (!$user || !in_array($user['role'], ['dean', 'evaluator', 'student'], true)) {
			$invalidate = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ?');
			$invalidate->execute([(int) $row['id']]);
			$pdo->commit();
			unset($_SESSION['pending_otp']);
			return ['status' => 'missing'];
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
		return ['status' => 'failed'];
	}

	session_regenerate_id(true);
	unset($_SESSION['pending_otp']);
	$_SESSION['user'] = $user;
	return ['status' => 'verified', 'user' => $user];
}

function fuzzy_otp_invalidate_pending(PDO $pdo): void
{
	$pending = $_SESSION['pending_otp'] ?? null;
	if (is_array($pending) && !empty($pending['id']) && !empty($pending['token'])) {
		$stmt = $pdo->prepare('UPDATE auth_otp_challenges SET invalidated_at = UTC_TIMESTAMP() WHERE id = ? AND session_binding_hash = ? AND consumed_at IS NULL AND invalidated_at IS NULL');
		$stmt->execute([(int) $pending['id'], hash('sha256', (string) $pending['token'])]);
	}
	unset($_SESSION['pending_otp']);
}