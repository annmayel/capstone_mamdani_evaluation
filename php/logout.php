<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/otp_auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

try {
	fuzzy_otp_invalidate_pending($pdo);
} catch (Throwable $exception) {
	unset($_SESSION['pending_otp']);
}

$_SESSION = [];
$cookie = session_get_cookie_params();
setcookie(session_name(), '', [
	'expires' => time() - 42000,
	'path' => $cookie['path'],
	'domain' => $cookie['domain'],
	'secure' => $cookie['secure'],
	'httponly' => $cookie['httponly'],
	'samesite' => $cookie['samesite'] ?? 'Lax',
]);
session_destroy();

header('Location: index.php?logged_out=1');
exit;
