<?php

declare(strict_types=1);

$mysqlConfig = [
	'MYSQLHOST' => getenv('MYSQLHOST'),
	'MYSQLPORT' => getenv('MYSQLPORT'),
	'MYSQLUSER' => getenv('MYSQLUSER'),
	'MYSQLPASSWORD' => getenv('MYSQLPASSWORD'),
	'MYSQLDATABASE' => getenv('MYSQLDATABASE'),
];

$hasMysqlConfig = false;
foreach ($mysqlConfig as $value) {
	if ($value !== false) {
		$hasMysqlConfig = true;
		break;
	}
}

$isRailway = getenv('RAILWAY_PROJECT_ID') !== false
	|| getenv('RAILWAY_SERVICE_ID') !== false
	|| getenv('RAILWAY_ENVIRONMENT_NAME') !== false;

if ($hasMysqlConfig || $isRailway) {
	$missingMysqlVariables = [];
	foreach ($mysqlConfig as $name => $value) {
		if ($value === false || $value === '') {
			$missingMysqlVariables[] = $name;
		}
	}

	if ($missingMysqlVariables) {
		error_log('Missing required database environment variables: ' . implode(', ', $missingMysqlVariables));
		http_response_code(500);
		exit('Database configuration is incomplete. Set the required MySQL environment variables.');
	}
}

$dbHost = $mysqlConfig['MYSQLHOST'] !== false ? $mysqlConfig['MYSQLHOST'] : '127.0.0.1';
$dbPort = $mysqlConfig['MYSQLPORT'] !== false ? $mysqlConfig['MYSQLPORT'] : '3306';
$dbName = $mysqlConfig['MYSQLDATABASE'] !== false ? $mysqlConfig['MYSQLDATABASE'] : 'capstone_fuzzy_eval';
$dbUser = $mysqlConfig['MYSQLUSER'] !== false ? $mysqlConfig['MYSQLUSER'] : 'root';
$dbPass = $mysqlConfig['MYSQLPASSWORD'] !== false ? $mysqlConfig['MYSQLPASSWORD'] : '';

$dsn = 'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4';

try {
	$pdo = new PDO($dsn, $dbUser, $dbPass, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
} catch (PDOException $e) {
	error_log('Database connection failed: ' . $e->getMessage());
	http_response_code(500);
	exit('Database connection failed. Check the server configuration and logs.');
}

function esc($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_csucc_email(string $email): bool
{
	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		return false;
	}

	$domain = substr(strrchr($email, '@') ?: '', 1);
	return strtolower($domain) === 'csucc.edu.ph';
}

function csrf_token(): string
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['csrf_token'];
}

function csrf_check($token): bool
{
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}

	return is_string($token) && is_string($_SESSION['csrf_token'] ?? null) && hash_equals($_SESSION['csrf_token'], $token);
}

function notify_user(PDO $pdo, int $userId, string $title, string $message, string $link = ''): void
{
	$stmt = $pdo->prepare('INSERT INTO notifications (user_id, title, message, link, is_read) VALUES (?, ?, ?, ?, 0)');
	$stmt->execute([$userId, $title, $message, $link]);
}

function notify_user_once(PDO $pdo, int $userId, string $eventKey, string $title, string $message, string $link = ''): bool
{
	try {
		$stmt = $pdo->prepare(
			'INSERT INTO notifications (user_id, title, message, link, event_key, is_read)
			 VALUES (?, ?, ?, ?, ?, 0)
			 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
		);
		$stmt->execute([$userId, $title, $message, $link, hash('sha256', $eventKey)]);
		return true;
	} catch (PDOException $exception) {
		error_log('[notifications] Could not record event notification: ' . $exception->getMessage());
		return false;
	}
}

function generate_account_id(PDO $pdo, string $role): string
{
	$prefixMap = [
		'dean' => 'ITCHAIR',
		'evaluator' => 'EVAL',
		'student' => 'STUD',
	];

	$prefix = $prefixMap[$role] ?? 'USER';
	$pattern = $prefix . '-%';

	$stmt = $pdo->prepare('SELECT account_id FROM users WHERE role = ? AND account_id LIKE ? ORDER BY account_id DESC LIMIT 1');
	$stmt->execute([$role, $pattern]);
	$last = $stmt->fetchColumn();

	$next = 1;
	if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
		$next = (int) $matches[1] + 1;
	}

	return $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}
