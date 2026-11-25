<?php

declare(strict_types=1);

function student_account_access_status(array $account): string
{
	if (empty($account['student_approved_at'])) {
		return 'approval_pending';
	}

	return 'approved';
}

function require_approved_student(PDO $pdo): array
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	$user = $_SESSION['user'] ?? null;
	if (!is_array($user) || ($user['role'] ?? null) !== 'student') {
		header('Location: ../index.php');
		exit;
	}

	$stmt = $pdo->prepare('SELECT id, student_approved_at FROM users WHERE id = ? AND role = ? LIMIT 1');
	$stmt->execute([(int) ($user['id'] ?? 0), 'student']);
	$account = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$account) {
		unset($_SESSION['user']);
		header('Location: ../index.php');
		exit;
	}
	if (student_account_access_status($account) === 'approval_pending') {
		unset($_SESSION['user']);
		header('Location: ../index.php?approval_required=1');
		exit;
	}

	$_SESSION['user']['student_approved_at'] = $account['student_approved_at'];
	return $_SESSION['user'];
}
