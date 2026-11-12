# Email notification setup

The portal uses the already-installed PHPMailer package and the same SMTP settings as login OTP mail. Email delivery is server-side. Notifications are queued in `email_notifications`, attempted after the related portal event is saved, and retried from the outbox. SMTP delivery has not been verified until a valid test mailbox is configured and a test event is received.

## Install the outbox table

Run `sql/migrations/008_email_notifications.sql` against the `capstone_fuzzy_eval` database. Fresh database imports from `sql/fuzzy_eval.sql` include the same table.

For a database that already has the outbox table, also run `sql/migrations/011_email_notification_retry_backoff.sql` and `sql/migrations/012_notification_event_idempotency.sql` in order. These additive migrations install retry timestamps/indexing and idempotent in-app event keys. Migration 011 and 012 may be rerun safely.

## Configure SMTP

Set these environment variables for the PHP/Apache process, then restart Apache:

```text
FUZZY_SMTP_HOST=smtp.example.com
FUZZY_SMTP_PORT=587
FUZZY_SMTP_ENCRYPTION=tls
FUZZY_SMTP_USERNAME=mailer@example.com
FUZZY_SMTP_PASSWORD=replace-with-a-secret
FUZZY_SMTP_FROM_EMAIL=mailer@example.com
FUZZY_SMTP_FROM_NAME=Capstone Fuzzy Student Portal
FUZZY_PORTAL_BASE_URL=https://portal.example.edu
```

Do not put real credentials in this project or commit them. On a local XAMPP installation, configure process/system environment variables outside the web root and restart Apache so PHP can read them. `FUZZY_PORTAL_BASE_URL` should point to the portal's public base URL (for example `http://localhost/Fuzzy_Score` for local testing); email links are omitted when it is not a valid HTTP(S) URL.

For Gmail, use `smtp.gmail.com`, port `587`, encryption `tls`, the sender Gmail address, and a Google App Password when the account supports it. Do not use the normal Gmail password.

## Sending and retrying

Event handling queues delivery after saving the portal event. Unique email and in-app event keys prevent normal repeated requests from queuing the same event twice, and a stable message ID is used for email retries. SMTP is not an exactly-once transport: a disconnect after the receiving server accepts a message but before it confirms acceptance can still lead to a duplicate if retried. SMTP failures are recorded and do not roll back submissions or evaluations. To retry pending/failed messages, a dean can open the Email Delivery section of the dean dashboard. Search, status filters, and pagination operate on the server; retry is protected by the dean role, CSRF validation, and a confirmation prompt. Manual retry resets an exhausted attempt count; automatic retries use increasing delays (one minute, five minutes, fifteen minutes, then one hour) and stop after five attempts.

For background recovery of queued events and interrupted sends, schedule this command with Windows Task Scheduler to run every minute. Configure the task action with:

```text
Program/script: C:\xampp\php\php.exe
Add arguments:  C:\xampp\htdocs\Fuzzy_Score\php\email_worker.php
```

The worker is CLI-only and processes up to 50 due pending messages per run. It also recovers sends that have been stuck for more than 15 minutes. Its automatic attempts are capped at five; a dean retry resets the count for that message.

Students can view their own in-app notifications from the student dashboard, see the unread count, and mark individual notifications as read. Notification updates are scoped to the signed-in student's account.

## Current event coverage and limitations

- Account registration confirmation, new project setup, defense-date changes, evaluator assignment, proposal receipt, and dean submission alerts.
- System and paper evaluation reports using saved scores and remarks.
- Final fuzzy report only after both evaluation records exist; it uses the official verdict classified from the saved average score.
- The current portal does not implement account approval/blocking, password reset/change, submission approval/rejection/revision actions, preference management, chapter-level remarks, or configurable evaluation weights. No emails are generated for workflows that do not exist.

After configuring SMTP, submit a test proposal using a controlled mailbox, save a system evaluation, save a paper evaluation, and confirm each message and the final report in the mailbox. Also inspect the dean email-delivery log for status and retry behavior. Do not treat queued messages as proof of successful email delivery.
