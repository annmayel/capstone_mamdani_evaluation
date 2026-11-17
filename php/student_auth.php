<?php

declare(strict_types=1);

function require_verified_student(PDO $pdo): array
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$user = $_SESSION['user'] ?? null;
	if (!is_array($user) || ($user['role'] ?? null) !== 'student') {
		header('Location: ../index.php');
		exit;
	}

	$stmt = $pdo->prepare('SELECT id, email_verified_at FROM users WHERE id = ? AND role = ? LIMIT 1');
	$stmt->execute([(int) ($user['id'] ?? 0), 'student']);
	$account = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$account || empty($account['email_verified_at'])) {
		unset($_SESSION['user']);
		header('Location: ../index.php?verification_required=1');
		exit;
	}

	$_SESSION['user']['email_verified_at'] = $account['email_verified_at'];
	return $_SESSION['user'];
}
