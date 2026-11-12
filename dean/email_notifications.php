<?php

if (!defined('FUZZY_EMAIL_NOTIFICATIONS_EMBEDDED')) {
	require_once __DIR__ . '/../config.php';
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	if (empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'dean') {
		http_response_code(403);
		exit('Forbidden');
	}
	header('Location: dashboard.php#email_delivery');
	exit;
}

require_once __DIR__ . '/../php/email_notifications.php';

$emailDeliveryMessage = $_SESSION['email_delivery_flash'] ?? '';
unset($_SESSION['email_delivery_flash']);
$emailDeliverySchemaError = false;
$emailDeliveryStats = ['sent' => 0, 'pending' => 0, 'failed' => 0, 'total' => 0];
$emailDeliveryNotifications = [];
$emailDeliveryRecordCount = 0;
$emailDeliveryPageSize = 50;
$emailDeliveryPage = max(1, (int) ($_GET['email_page'] ?? 1));
$emailDeliverySearch = trim((string) ($_GET['email_search'] ?? ''));
$emailDeliveryStatus = (string) ($_GET['email_status'] ?? 'all');
if (!in_array($emailDeliveryStatus, ['all', 'pending', 'sending', 'sent', 'failed'], true)) {
	$emailDeliveryStatus = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retry_notification'])) {
	if (!csrf_check($_POST['csrf'] ?? '')) {
		http_response_code(400);
		$emailDeliveryMessage = 'The retry request could not be verified. Refresh the page and try again.';
	} else {
		$notificationId = (int) ($_POST['notification_id'] ?? 0);
		if ($notificationId > 0) {
			try {
				$statusStmt = $pdo->prepare('SELECT delivery_status, attempt_count FROM email_notifications WHERE id = ? LIMIT 1');
				$statusStmt->execute([$notificationId]);
				$notificationStatus = $statusStmt->fetch(PDO::FETCH_ASSOC);
				if (
					$notificationStatus
					&& (
						$notificationStatus['delivery_status'] === 'failed'
						|| ($notificationStatus['delivery_status'] === 'pending' && (int) $notificationStatus['attempt_count'] < 5)
					)
				) {
					$sent = fuzzy_email_dispatch($pdo, $notificationId, true);
					$emailDeliveryMessage = $sent
						? 'The mail transport accepted the retry. The delivery status is updated below.'
						: (fuzzy_otp_mail_config() === null
							? 'SMTP is not configured. Add the mail settings before retrying email delivery.'
							: 'The retry was not completed. The message may already be sending or may have reached the attempt limit.');
				} else {
					$emailDeliveryMessage = 'Only pending or failed messages can be retried.';
				}
			} catch (PDOException $exception) {
				error_log('[fuzzy-email] Retry could not access email notification storage.');
				$emailDeliveryMessage = 'Email notification storage is unavailable. Apply the listed database migration, then try again.';
			}
		}
	}
	$_SESSION['email_delivery_flash'] = $emailDeliveryMessage;
	header('Location: dashboard.php#email_delivery');
	exit;
}

try {
	$stats = $pdo->query(
		"SELECT COUNT(*) AS total,
			SUM(delivery_status = 'sent') AS sent,
			SUM(delivery_status = 'pending') AS pending,
			SUM(delivery_status = 'failed') AS failed
		 FROM email_notifications"
	)->fetch(PDO::FETCH_ASSOC);
	if ($stats) {
		$emailDeliveryStats = array_map('intval', $stats);
	}
	$conditions = [];
	$params = [];
	if ($emailDeliveryStatus !== 'all') {
		$conditions[] = 'e.delivery_status = ?';
		$params[] = $emailDeliveryStatus;
	}
	if ($emailDeliverySearch !== '') {
		$conditions[] = '(e.recipient_email LIKE ? OR e.event_type LIKE ? OR p.title LIKE ?)';
		$searchTerm = '%' . $emailDeliverySearch . '%';
		array_push($params, $searchTerm, $searchTerm, $searchTerm);
	}
	$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
	$countStmt = $pdo->prepare('SELECT COUNT(*) FROM email_notifications e LEFT JOIN projects p ON p.id = e.project_id' . $where);
	$countStmt->execute($params);
	$emailDeliveryRecordCount = (int) $countStmt->fetchColumn();
	$emailDeliveryPageCount = max(1, (int) ceil($emailDeliveryRecordCount / $emailDeliveryPageSize));
	$emailDeliveryPage = min($emailDeliveryPage, $emailDeliveryPageCount);
	$offset = ($emailDeliveryPage - 1) * $emailDeliveryPageSize;
	$listStmt = $pdo->prepare(
		'SELECT e.id, e.event_type, e.recipient_email, e.project_id, p.title AS project_title,
			e.delivery_status, e.attempt_count, e.last_attempt_at, e.last_error, e.created_at, e.sent_at
		 FROM email_notifications e LEFT JOIN projects p ON p.id = e.project_id'
		 . $where . ' ORDER BY e.id DESC LIMIT ? OFFSET ?'
	);
	foreach ($params as $index => $value) {
		$listStmt->bindValue($index + 1, $value, PDO::PARAM_STR);
	}
	$listStmt->bindValue(count($params) + 1, $emailDeliveryPageSize, PDO::PARAM_INT);
	$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
	$listStmt->execute();
	$emailDeliveryNotifications = $listStmt->fetchAll(PDO::FETCH_ASSOC);
	$emailDeliveryPageCount = max(1, (int) ceil($emailDeliveryRecordCount / $emailDeliveryPageSize));
} catch (PDOException $exception) {
	error_log('[fuzzy-email] Email delivery log could not be loaded; verify migration 008 and its database target.');
	$emailDeliverySchemaError = true;
	$emailDeliveryNotifications = [];
}

$emailDeliverySmtpConfigured = fuzzy_otp_mail_config() !== null;
?>
<section id="section-email_delivery" class="content-section email-delivery" style="display:none;">
	<style>
		.email-delivery .email-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}
		.email-delivery .email-toolbar h1{margin:0 0 6px;color:var(--primary-dark);font-size:26px}
		.email-delivery .email-toolbar p{margin:0;color:var(--text-secondary)}
		.email-delivery .email-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:22px}
		.email-delivery .email-summary-card,.email-delivery .email-log-panel{background:var(--white);border:1px solid var(--border-color);border-radius:10px;box-shadow:0 4px 14px rgba(16,35,42,.04)}
		.email-delivery .email-summary-card{padding:18px 20px}
		.email-delivery .email-summary-card span{display:block;color:var(--text-secondary);font-size:12px;font-weight:600;text-transform:uppercase}
		.email-delivery .email-summary-card strong{display:block;margin-top:8px;color:var(--primary-dark);font-size:27px}
		.email-delivery .email-log-panel{padding:20px}
		.email-delivery .email-log-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}
		.email-delivery .email-log-heading h2{margin:0;color:var(--primary-dark);font-size:18px}
		.email-delivery .email-filters{display:flex;gap:10px;flex-wrap:wrap}
		.email-delivery .email-filters input,.email-delivery .email-filters select{min-height:40px;padding:8px 11px;border:1px solid var(--border-color);border-radius:6px;background:#fff;color:var(--primary-dark);font:inherit}
		.email-delivery .email-filters input{width:min(280px,100%)}
		.email-delivery .email-table-wrap{overflow-x:auto}
		.email-delivery table{width:100%;min-width:920px;border-collapse:collapse}
		.email-delivery th,.email-delivery td{padding:12px 10px;border-bottom:1px solid #e8e2de;text-align:left;vertical-align:top;font-size:12px}
		.email-delivery th{color:var(--text-secondary);font-size:10px;text-transform:uppercase;white-space:nowrap}
		.email-delivery td{color:var(--primary-dark)}
		.email-delivery .email-status{display:inline-block;padding:4px 9px;border-radius:99px;font-size:11px;font-weight:700;text-transform:capitalize;white-space:nowrap}
		.email-delivery .email-status-sent{background:#e4f4ec;color:#14734a}
		.email-delivery .email-status-pending,.email-delivery .email-status-sending{background:#f7efdf;color:#8b5c19}
		.email-delivery .email-status-failed{background:#fbe9e5;color:#a33f32}
		.email-delivery .email-error{max-width:260px;color:#9a4a3c;overflow-wrap:anywhere}
		.email-delivery .email-notice{margin:0 0 16px;padding:12px 14px;border-radius:6px;background:#f2ece5;color:var(--primary-dark)}
		.email-delivery .email-empty{padding:24px;text-align:center;color:var(--text-secondary)}
		.email-delivery .email-pagination{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;color:var(--text-secondary);font-size:12px}
		.email-delivery .email-pagination a{color:var(--primary-dark);font-weight:700;text-decoration:none}
		.email-delivery .email-pagination a[aria-disabled="true"]{opacity:.45;pointer-events:none}
		.email-delivery .email-retry{padding:7px 11px;border:0;border-radius:5px;background:var(--primary-dark);color:#fff;font:inherit;font-weight:600;cursor:pointer}
		.email-delivery .email-retry:disabled{opacity:.55;cursor:not-allowed}
		@media(max-width:900px){.email-delivery .email-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
		@media(max-width:600px){.email-delivery .email-toolbar,.email-delivery .email-log-heading{align-items:stretch;flex-direction:column}.email-delivery .email-toolbar h1{font-size:22px}.email-delivery .email-summary{gap:10px}.email-delivery .email-summary-card{padding:14px}.email-delivery .email-summary-card strong{font-size:23px}.email-delivery .email-log-panel{padding:14px}.email-delivery .email-filters input{width:100%}}
	</style>
	<header class="email-toolbar">
		<div><h1>Email Delivery</h1><p>Monitor system email notifications and review their delivery status.</p></div>
	</header>
	<?php if ($emailDeliverySchemaError): ?>
		<div class="email-notice" role="alert">Email notification storage is not installed or unavailable. Apply <code>sql/migrations/008_email_notifications.sql</code> to the <code>capstone_fuzzy_eval</code> database, then refresh this page.</div>
	<?php endif; ?>
	<?php if (!$emailDeliverySmtpConfigured): ?>
		<div class="email-notice" role="status">SMTP is not configured. Notifications can be reviewed here, but delivery and retries remain unavailable until mail settings are configured.</div>
	<?php endif; ?>
	<?php if ($emailDeliveryMessage !== ''): ?><div class="email-notice" role="status"><?=esc($emailDeliveryMessage)?></div><?php endif; ?>
	<div class="email-summary" aria-label="Email delivery summary">
		<div class="email-summary-card"><span>Sent</span><strong><?=esc($emailDeliveryStats['sent'])?></strong></div>
		<div class="email-summary-card"><span>Pending</span><strong><?=esc($emailDeliveryStats['pending'])?></strong></div>
		<div class="email-summary-card"><span>Failed</span><strong><?=esc($emailDeliveryStats['failed'])?></strong></div>
		<div class="email-summary-card"><span>Total Emails</span><strong><?=esc($emailDeliveryStats['total'])?></strong></div>
	</div>
	<div class="email-log-panel">
		<div class="email-log-heading">
			<h2>Recent email events</h2>
			<form class="email-filters" method="get" action="dashboard.php#email_delivery">
				<input type="hidden" name="email_page" value="1">
				<label class="sr-only" for="email-search">Search email events</label>
				<input id="email-search" name="email_search" type="search" value="<?=esc($emailDeliverySearch)?>" placeholder="Search recipient, event, project..." autocomplete="off">
				<label class="sr-only" for="email-status-filter">Filter by delivery status</label>
				<select id="email-status-filter" name="email_status"><option value="all" <?=$emailDeliveryStatus === 'all' ? 'selected' : ''?>>All statuses</option><option value="sent" <?=$emailDeliveryStatus === 'sent' ? 'selected' : ''?>>Sent</option><option value="pending" <?=$emailDeliveryStatus === 'pending' ? 'selected' : ''?>>Pending</option><option value="sending" <?=$emailDeliveryStatus === 'sending' ? 'selected' : ''?>>Sending</option><option value="failed" <?=$emailDeliveryStatus === 'failed' ? 'selected' : ''?>>Failed</option></select>
				<button class="email-retry" type="submit">Search</button>
			</form>
		</div>
		<div class="email-table-wrap">
			<table>
				<thead><tr><th>Created At</th><th>Event</th><th>Recipient</th><th>Project</th><th>Status</th><th>Attempts</th><th>Last Error</th><th>Action</th></tr></thead>
				<tbody id="email-log-rows">
				<?php foreach ($emailDeliveryNotifications as $notification): ?>
					<?php $deliveryStatus = (string) $notification['delivery_status']; ?>
					<tr data-status="<?=esc($deliveryStatus)?>">
						<td><?=esc($notification['created_at'])?><?php if ($notification['sent_at']): ?><br><small>Accepted <?=esc($notification['sent_at'])?></small><?php endif; ?></td>
						<td><?=esc(str_replace('_', ' ', $notification['event_type']))?></td>
						<td><?=esc($notification['recipient_email'])?></td>
						<td><?=esc($notification['project_title'] ?: ($notification['project_id'] ? 'Project #' . $notification['project_id'] : '—'))?></td>
						<td><span class="email-status email-status-<?=esc($deliveryStatus)?>"><?=esc($deliveryStatus)?></span></td>
						<td><?=esc($notification['attempt_count'])?><?php if ($notification['last_attempt_at']): ?><br><small>Last attempt <?=esc(date('M j, Y g:i A', strtotime($notification['last_attempt_at'])))?></small><?php endif; ?></td>
						<td class="email-error"><?=esc($notification['last_error'] ?? '')?></td>
						<td><?php if ($deliveryStatus === 'failed' || ($deliveryStatus === 'pending' && (int) $notification['attempt_count'] < 5)): ?>
							<form method="post" action="dashboard.php#email_delivery" onsubmit="return confirm('Retry this email delivery now?')"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><input type="hidden" name="notification_id" value="<?=esc($notification['id'])?>"><button class="email-retry" type="submit" name="retry_notification" value="1" <?=!$emailDeliverySmtpConfigured ? 'disabled' : ''?>>Retry</button></form>
						<?php else: ?>—<?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				<tr id="email-no-results" <?=($emailDeliveryNotifications && !$emailDeliverySchemaError) ? 'hidden' : ''?>><td colspan="8" class="email-empty"><?= $emailDeliverySchemaError ? 'Email events could not be loaded.' : 'No email events found.' ?></td></tr>
				<tr id="email-no-matches" hidden><td colspan="8" class="email-empty">No email events match your search and filters.</td></tr>
				</tbody>
			</table>
		</div>
		<?php if (!$emailDeliverySchemaError && $emailDeliveryRecordCount > 0): ?>
			<?php
			$emailPageUrl = static function (int $page) use ($emailDeliverySearch, $emailDeliveryStatus): string {
				return 'dashboard.php?' . http_build_query([
					'email_page' => $page,
					'email_search' => $emailDeliverySearch,
					'email_status' => $emailDeliveryStatus,
				]) . '#email_delivery';
			};
			?>
			<nav class="email-pagination" aria-label="Email event pages">
				<a href="<?=esc($emailPageUrl(max(1, $emailDeliveryPage - 1)))?>" aria-disabled="<?=$emailDeliveryPage <= 1 ? 'true' : 'false'?>">Previous</a>
				<span>Page <?=esc($emailDeliveryPage)?> of <?=esc($emailDeliveryPageCount)?> · <?=esc($emailDeliveryRecordCount)?> events</span>
				<a href="<?=esc($emailPageUrl(min($emailDeliveryPageCount, $emailDeliveryPage + 1)))?>" aria-disabled="<?=$emailDeliveryPage >= $emailDeliveryPageCount ? 'true' : 'false'?>">Next</a>
			</nav>
		<?php endif; ?>
	</div>
	<script>
		(() => {
			const search = document.getElementById('email-search');
			const statusFilter = document.getElementById('email-status-filter');
			const rows = Array.from(document.querySelectorAll('#email-log-rows tr[data-status]'));
			const noResults = document.getElementById('email-no-results');
			const noMatches = document.getElementById('email-no-matches');
			const filterRows = () => {
				const query = search.value.trim().toLowerCase();
				const status = statusFilter.value;
				let visibleRows = 0;
				rows.forEach(row => {
					const matches = (status === 'all' || row.dataset.status === status) && row.textContent.toLowerCase().includes(query);
					row.hidden = !matches;
					if (matches) visibleRows++;
				});
				noResults.hidden = rows.length !== 0;
				noMatches.hidden = rows.length === 0 || visibleRows !== 0;
			};
			search.addEventListener('input', filterRows);
			statusFilter.addEventListener('change', filterRows);
		})();
	</script>
</section>
