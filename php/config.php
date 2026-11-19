<?php

declare(strict_types=1);

$readEnvironmentValue = static function (string $name) {
    $value = getenv($name);

    if ($value !== false) {
        return $value;
    }

    if (isset($_SERVER[$name])) {
        return (string) $_SERVER[$name];
    }

    if (isset($_ENV[$name])) {
        return (string) $_ENV[$name];
    }

    return false;
};

$mysqlConfig = [];

foreach (['MYSQLHOST', 'MYSQLPORT', 'MYSQLUSER', 'MYSQLPASSWORD', 'MYSQLDATABASE'] as $name) {
    $value = $readEnvironmentValue($name);
    $mysqlConfig[$name] = $value;
}

$isRailway = false;

foreach (['RAILWAY_PROJECT_ID', 'RAILWAY_SERVICE_ID', 'RAILWAY_ENVIRONMENT_NAME'] as $name) {
    $value = $readEnvironmentValue($name);

    if ($value !== false && $value !== '') {
        $isRailway = true;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| DATABASE ENVIRONMENT DETECTION
|--------------------------------------------------------------------------
*/

$hasMysqlConfig = false;

foreach ($mysqlConfig as $value) {
    if ($value !== false && $value !== '') {
        $hasMysqlConfig = true;
        break;
    }
}

if ($isRailway || $hasMysqlConfig) {

    $missingVariables = [];

    foreach ($mysqlConfig as $name => $value) {
        if ($value === false || $value === '') {
            $missingVariables[] = $name;
        }
    }

    if (!empty($missingVariables)) {
        error_log(
            'Missing MySQL variables: ' .
            implode(', ', $missingVariables)
        );

        http_response_code(500);
        exit(
            'Database configuration is incomplete. Missing required MySQL environment variables: ' .
            implode(', ', $missingVariables) .
            ($isRailway
                ? '. Add references from the Railway MySQL service to the PHP app service.'
                : '. Set all required MySQL environment variables for the active database environment.')
        );
    }

    $dbHost = $mysqlConfig['MYSQLHOST'];
    $dbPort = $mysqlConfig['MYSQLPORT'];
    $dbUser = $mysqlConfig['MYSQLUSER'];
    $dbPass = $mysqlConfig['MYSQLPASSWORD'];
    $dbName = $mysqlConfig['MYSQLDATABASE'];

} else {

    // LOCAL XAMPP DATABASE SETTINGS

    $dbHost = '127.0.0.1';
    $dbPort = '3306';
    $dbUser = 'root';
    $dbPass = '';
    $dbName = 'capstone_fuzzy_eval';
}

/*
|--------------------------------------------------------------------------
| PDO DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

try {

    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL SCHEMA AUTO-MIGRATION
    |--------------------------------------------------------------------------
    |
    | Older Railway/XAMPP databases may not have the email-OTP or student
    | approval columns yet. Add the columns the application queries, keeping
    | startup backward-compatible until the SQL migrations are run manually.
    */

    try {
        $userEmailVerifiedColumn = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'email_verified_at'")
            ->fetch(PDO::FETCH_ASSOC);

        if (!$userEmailVerifiedColumn) {
            $pdo->exec(
                "ALTER TABLE `users`
                 ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `email`"
            );
            $pdo->exec(
                "UPDATE `users`
                 SET `email_verified_at` = UTC_TIMESTAMP()
                 WHERE `role` = 'student'"
            );
        }

        $studentApprovalColumn = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'student_approved_at'")
            ->fetch(PDO::FETCH_ASSOC);

        if (!$studentApprovalColumn) {
            $pdo->exec(
                "ALTER TABLE `users`
                 ADD COLUMN `student_approved_at` DATETIME DEFAULT NULL AFTER `email_verified_at`"
            );
        }

        $pdo->exec(
            "ALTER TABLE `users`
             MODIFY COLUMN `role`
             ENUM('dean','evaluator','student','adviser') NOT NULL"
        );

        $otpTable = $pdo->query("SHOW TABLES LIKE 'auth_otp_challenges'")
            ->fetchColumn();

        if ($otpTable !== false) {
            $otpPurposeColumn = $pdo->query("SHOW COLUMNS FROM `auth_otp_challenges` LIKE 'purpose'")
                ->fetch(PDO::FETCH_ASSOC);

            if (!$otpPurposeColumn) {
                $pdo->exec(
                    "ALTER TABLE `auth_otp_challenges`
                     ADD COLUMN `purpose` VARCHAR(32) NOT NULL DEFAULT 'login' AFTER `user_id`"
                );
            }
        }

        $projectsTable = $pdo->query("SHOW TABLES LIKE 'projects'")
            ->fetchColumn();

        if ($projectsTable !== false) {
            $adviserUserColumn = $pdo->query("SHOW COLUMNS FROM `projects` LIKE 'adviser_user_id'")
                ->fetch(PDO::FETCH_ASSOC);

            if (!$adviserUserColumn) {
                $pdo->exec(
                    "ALTER TABLE `projects`
                     ADD COLUMN `adviser_user_id` INT(11) DEFAULT NULL AFTER `adviser`"
                );
            }
        }
    } catch (PDOException $schemaError) {
        error_log('[SCHEMA MIGRATION ERROR] ' . $schemaError->getMessage());
    }

} catch (PDOException $e) {

    error_log('[DATABASE ERROR] ' . $e->getMessage());

    http_response_code(500);

    exit(
        'Database connection failed. ' .
        'Please check the database configuration.'
    );
}

/*
|--------------------------------------------------------------------------
| HTML ESCAPING
|--------------------------------------------------------------------------
*/

function esc($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| EMAIL VALIDATION
|--------------------------------------------------------------------------
*/

function is_csucc_email(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $domain = substr(
        strrchr($email, '@') ?: '',
        1
    );

    return strtolower($domain) === 'csucc.edu.ph';
}

/*
|--------------------------------------------------------------------------
| CSRF PROTECTION
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['csrf_token'];
}

function csrf_check($token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return
        is_string($token) &&
        is_string($_SESSION['csrf_token'] ?? null) &&
        hash_equals($_SESSION['csrf_token'], $token);
}

/*
|--------------------------------------------------------------------------
| USER NOTIFICATIONS
|--------------------------------------------------------------------------
*/

function notify_user(
    PDO $pdo,
    int $userId,
    string $title,
    string $message,
    string $link = ''
): void {

    $stmt = $pdo->prepare(
        'INSERT INTO notifications
        (user_id, title, message, link, is_read)
        VALUES (?, ?, ?, ?, 0)'
    );

    $stmt->execute([
        $userId,
        $title,
        $message,
        $link
    ]);
}

/*
|--------------------------------------------------------------------------
| PREVENT DUPLICATE NOTIFICATIONS
|--------------------------------------------------------------------------
*/

function notify_user_once(
    PDO $pdo,
    int $userId,
    string $eventKey,
    string $title,
    string $message,
    string $link = ''
): bool {

    try {

        $stmt = $pdo->prepare(
            'INSERT INTO notifications
            (user_id, title, message, link, event_key, is_read)
            VALUES (?, ?, ?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );

        $stmt->execute([
            $userId,
            $title,
            $message,
            $link,
            hash('sha256', $eventKey)
        ]);

        return true;

    } catch (PDOException $exception) {

        error_log(
            '[NOTIFICATIONS ERROR] ' .
            $exception->getMessage()
        );

        return false;
    }
}

/*
|--------------------------------------------------------------------------
| GENERATE ACCOUNT ID
|--------------------------------------------------------------------------
*/

function generate_account_id(
    PDO $pdo,
    string $role
): string {

    $prefixMap = [
        'dean'      => 'ITCHAIR',
        'evaluator' => 'EVAL',
        'student'   => 'STUD',
        'adviser'   => 'ADV',
    ];

    $prefix = $prefixMap[$role] ?? 'USER';

    $pattern = $prefix . '-%';

    $stmt = $pdo->prepare(
        'SELECT account_id
         FROM users
         WHERE role = ?
         AND account_id LIKE ?
         ORDER BY account_id DESC
         LIMIT 1'
    );

    $stmt->execute([
        $role,
        $pattern
    ]);

    $last = $stmt->fetchColumn();

    $next = 1;

    if (
        is_string($last) &&
        preg_match('/(\d+)$/', $last, $matches)
    ) {
        $next = (int) $matches[1] + 1;
    }

    return $prefix . '-' .
        str_pad(
            (string) $next,
            4,
            '0',
            STR_PAD_LEFT
        );
}
