<?php

declare(strict_types=1);

function student_account_access_status(array $account): string
{
	if (empty($account['email_verified_at'])) {
		return 'email_unverified';
	}
	if (empty($account['student_approved_at'])) {
		return 'approval_pending';
	}

	return 'approved';
}

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

	$stmt = $pdo->prepare('SELECT id, email_verified_at, student_approved_at FROM users WHERE id = ? AND role = ? LIMIT 1');
	$stmt->execute([(int) ($user['id'] ?? 0), 'student']);
	$account = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$account || student_account_access_status($account) === 'email_unverified') {
		unset($_SESSION['user']);
		header('Location: ../index.php?verification_required=1');
		exit;
	}
	if (student_account_access_status($account) === 'approval_pending') {
		unset($_SESSION['user']);
		header('Location: ../index.php?approval_required=1');
		exit;
	}

	$_SESSION['user']['email_verified_at'] = $account['email_verified_at'];
	$_SESSION['user']['student_approved_at'] = $account['student_approved_at'];
	return $_SESSION['user'];
}
