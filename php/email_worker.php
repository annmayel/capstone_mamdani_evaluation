<?php

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/email_notifications.php';

$pdo->exec(
	"UPDATE email_notifications
	 SET delivery_status = IF(attempt_count < 5, 'pending', 'failed'),
	     last_error = 'Previous send process ended before recording a result.',
	     next_attempt_at = IF(attempt_count < 5, DATE_ADD(NOW(), INTERVAL 60 SECOND), NULL)
	 WHERE delivery_status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);
$ids = $pdo->query(
	"SELECT id FROM email_notifications
	 WHERE delivery_status = 'pending' AND attempt_count < 5
	   AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
	 ORDER BY COALESCE(next_attempt_at, created_at) ASC, id ASC LIMIT 50"
)->fetchAll(PDO::FETCH_COLUMN);

$sent = 0;
foreach ($ids as $id) {
	if (fuzzy_email_dispatch($pdo, (int) $id)) {
		$sent++;
	}
}

fwrite(STDOUT, 'Processed ' . count($ids) . ' queued email(s); sent ' . $sent . '.' . PHP_EOL);
