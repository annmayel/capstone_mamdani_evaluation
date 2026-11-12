<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') { header('Location: ../index.php'); exit; }
$user = $_SESSION['user'];
$uid = (int) $user['id'];
$view = $_GET['view'] ?? 'overview';
$views = ['overview', 'proposal', 'results', 'history', 'notifications', 'profile'];
if (!in_array($view, $views, true)) $view = 'overview';
$profileErrors = [];
$profileUpdated = isset($_GET['profile_updated']);
$notificationActionError = false;
$notificationActionErrorMessage = 'The request could not be verified. Refresh and try again.';
$notificationFilter = $_GET['filter'] ?? 'all';
if (!in_array($notificationFilter, ['all', 'unread', 'read'], true)) $notificationFilter = 'all';
$notificationSearch = trim((string) ($_GET['search'] ?? ''));
if (function_exists('mb_substr')) {
    $notificationSearch = mb_substr($notificationSearch, 0, 120);
} else {
    $notificationSearch = substr($notificationSearch, 0, 120);
}
$notificationCategory = $_GET['category'] ?? 'all';
if (!in_array($notificationCategory, ['all', 'evaluation', 'project_updates', 'announcements'], true)) $notificationCategory = 'all';
$notificationSort = $_GET['sort'] ?? 'newest';
if (!in_array($notificationSort, ['newest', 'oldest'], true)) $notificationSort = 'newest';
$notificationDate = $_GET['date'] ?? 'all';
if (!in_array($notificationDate, ['all', 'today', 'week', 'month'], true)) $notificationDate = 'all';
$notificationPage = max(1, (int) ($_GET['page'] ?? 1));
$allowedNotificationPageSizes = [5, 10, 15];
$notificationPageSize = (int) ($_GET['per_page'] ?? 5);
if (!in_array($notificationPageSize, $allowedNotificationPageSizes, true)) $notificationPageSize = 5;
$notificationCounts = ['all' => 0, 'unread' => 0, 'read' => 0];
$notificationTotalCount = 0;
$notificationFilteredCount = 0;
$notificationPageCount = 1;
$notificationPageNumbers = [];
$notificationLoadFailed = false;
$notificationCategoryPatterns = [
    'announcements' => '(^|[^[:alnum:]_])(announcement|announcements|news|notice|notices)([^[:alnum:]_]|$)',
    'evaluation' => '(^|[^[:alnum:]_])(evaluation|evaluations|verdict|score|scores|feedback|fuzzy)([^[:alnum:]_]|$)',
    'project_updates' => '(^|[^[:alnum:]_])(project|proposal|submission|submitted|capstone|assigned|assignment|status|group|adviser)([^[:alnum:]_]|$)',
];
$unreadNotificationCount = 0;
$notificationActionSucceeded = isset($_GET['notification_read']);
$notificationUpdatedCount = max(0, (int) ($_GET['updated'] ?? 0));
$studentAccountStmt = $pdo->prepare('SELECT full_name, email, student_id_number, group_name, department FROM users WHERE id = ? AND role = ? LIMIT 1');
$studentAccountStmt->execute([$uid, 'student']);
$studentAccount = $studentAccountStmt->fetch(PDO::FETCH_ASSOC) ?: $user;

if ($view === 'notifications' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_notifications_read') {
    $postedFilter = $_POST['filter'] ?? 'all';
    $notificationFilter = in_array($postedFilter, ['all', 'unread', 'read'], true) ? $postedFilter : 'all';
    $notificationSearch = trim((string) ($_POST['search'] ?? ''));
    $notificationSearch = function_exists('mb_substr') ? mb_substr($notificationSearch, 0, 120) : substr($notificationSearch, 0, 120);
    $postedCategory = $_POST['category'] ?? 'all';
    $notificationCategory = in_array($postedCategory, ['all', 'evaluation', 'project_updates', 'announcements'], true) ? $postedCategory : 'all';
    $postedSort = $_POST['sort'] ?? 'newest';
    $notificationSort = in_array($postedSort, ['newest', 'oldest'], true) ? $postedSort : 'newest';
    $postedDate = $_POST['date'] ?? 'all';
    $notificationDate = in_array($postedDate, ['all', 'today', 'week', 'month'], true) ? $postedDate : 'all';
    $postedPageSize = (int) ($_POST['per_page'] ?? $notificationPageSize);
    $notificationPageSize = in_array($postedPageSize, $allowedNotificationPageSizes, true) ? $postedPageSize : 5;
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $notificationActionError = true;
    } else {
        try {
            $markAllReadStmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
            $markAllReadStmt->execute([$uid]);
            $redirectQuery = http_build_query([
                'view' => 'notifications',
                'filter' => $notificationFilter,
                'search' => $notificationSearch,
                'category' => $notificationCategory,
                'sort' => $notificationSort,
                'date' => $notificationDate,
                'per_page' => $notificationPageSize,
                'page' => 1,
                'notification_read' => 1,
                'updated' => (int) $markAllReadStmt->rowCount(),
            ]);
            header('Location: ?' . $redirectQuery);
            exit;
        } catch (PDOException $exception) {
            error_log('[notifications] Could not mark student notifications as read: ' . $exception->getMessage());
            $notificationActionError = true;
            $notificationActionErrorMessage = 'Notifications could not be updated. Please try again.';
        }
    }
}

if ($view === 'profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileName = trim((string) ($_POST['full_name'] ?? ''));
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $profileErrors[] = 'Your session expired. Refresh the page and try again.';
    } elseif ($profileName === '' || strlen($profileName) > 200) {
        $profileErrors[] = 'Enter your full name (up to 200 characters).';
    } else {
        $updateNameStmt = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ? AND role = ?');
        $updateNameStmt->execute([$profileName, $uid, 'student']);
        $_SESSION['user']['full_name'] = $profileName;
        $studentAccount['full_name'] = $profileName;
        header('Location: ?view=profile&profile_updated=1');
        exit;
    }
}

if ($view === 'proposal') {
    require __DIR__ . '/proposal.php';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM projects WHERE student_id = ? LIMIT 1');
$stmt->execute([$uid]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);
$project = $project ?: null;
$submissions = [];
$latestSubmission = null;
$submissionTableAvailable = true;
try {
    $stmt = $pdo->prepare('SELECT * FROM student_submissions WHERE student_id = ? ORDER BY id DESC');
    $stmt->execute([$uid]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $latestSubmission = $submissions[0] ?? null;
} catch (PDOException $exception) {
    $submissionTableAvailable = false;
}
$assignedEvaluators = [];
if ($project) {
    $stmt = $pdo->prepare('SELECT u.full_name, u.department, a.status FROM evaluator_assignments a INNER JOIN users u ON u.id = a.evaluator_id WHERE a.project_id = ? ORDER BY u.full_name');
    $stmt->execute([(int) $project['id']]);
    $assignedEvaluators = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$projectEvaluation = null;
$paperEvaluation = null;
$projectFeedback = [];
$paperFeedback = [];
$feedbackRows = [];
$verdictStrengths = [];
$verdictRevisions = [];
$notifications = [];
$criteria = [
    'functionality' => 'Functionality', 'performance' => 'Performance',
    'uiux' => 'UI / UX', 'innovation' => 'Innovation', 'reliability' => 'Reliability',
    'technical_quality' => 'Technical Quality', 'usability' => 'Usability',
    'security' => 'Security', 'relevance' => 'Relevance', 'completeness' => 'Completeness'
];
if ($project) {
    $stmt = $pdo->prepare('SELECT * FROM evaluations_project WHERE project_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $project['id']]); $projectEvaluation = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT * FROM evaluations_paper WHERE project_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int) $project['id']]); $paperEvaluation = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT remarks, evaluator_id, created_at, total_score FROM evaluations_project WHERE project_id = ? AND remarks <> "" ORDER BY id DESC');
    $stmt->execute([(int) $project['id']]); $projectFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT remarks, evaluator_id, created_at, total_score FROM evaluations_paper WHERE project_id = ? AND remarks <> "" ORDER BY id DESC');
    $stmt->execute([(int) $project['id']]); $paperFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
    try {
        $stmt = $pdo->prepare('SELECT evaluation_type, remarks, strengths, weaknesses, recommendations, required_revisions, evaluator_id, created_at FROM evaluation_feedback WHERE project_id = ? AND (remarks <> "" OR strengths <> "" OR weaknesses <> "" OR recommendations <> "" OR required_revisions <> "") ORDER BY id DESC');
        $stmt->execute([(int) $project['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $evaluationType = ucfirst((string) $item['evaluation_type']);
            foreach (['strengths', 'weaknesses', 'recommendations', 'required_revisions'] as $field) {
                $feedbackText = trim((string) ($item[$field] ?? ''));
                if ($feedbackText !== '') {
                    $feedbackDetail = ['type' => $evaluationType, 'text' => $feedbackText];
                    if ($field === 'strengths') {
                        $verdictStrengths[] = $feedbackDetail;
                    } else {
                        $verdictRevisions[] = $feedbackDetail;
                    }
                }
            }
            $item['remarks'] = trim(implode(' ', array_filter([
                $item['remarks'],
                $item['strengths'],
                $item['weaknesses'],
                $item['recommendations'],
                $item['required_revisions'],
            ], static fn($text) => trim((string) $text) !== '')));
            $item['type'] = ucfirst((string) $item['evaluation_type']) . ' feedback';
            $feedbackRows[] = $item;
        }
    } catch (PDOException $exception) {
        error_log('[student dashboard] Could not load structured evaluation feedback: ' . $exception->getMessage());
        $feedbackRows = [];
    }
}
try {
    if ($view === 'notifications') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) AS all_count, COALESCE(SUM(is_read = 0), 0) AS unread_count, COALESCE(SUM(is_read <> 0), 0) AS read_count FROM notifications WHERE user_id = ?');
        $countStmt->execute([$uid]);
        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $notificationCounts = [
            'all' => (int) ($counts['all_count'] ?? 0),
            'unread' => (int) ($counts['unread_count'] ?? 0),
            'read' => (int) ($counts['read_count'] ?? 0),
        ];
        $notificationTotalCount = $notificationCounts['all'];
        $unreadNotificationCount = $notificationCounts['unread'];

        $notificationRowsSql = "(SELECT owned_notifications.*, CASE
            WHEN LOWER(CONCAT_WS(' ', COALESCE(owned_notifications.title, ''), COALESCE(owned_notifications.message, ''))) REGEXP ? THEN 'announcements'
            WHEN LOWER(CONCAT_WS(' ', COALESCE(owned_notifications.title, ''), COALESCE(owned_notifications.message, ''))) REGEXP ? THEN 'evaluation'
            WHEN LOWER(CONCAT_WS(' ', COALESCE(owned_notifications.title, ''), COALESCE(owned_notifications.message, ''))) REGEXP ? THEN 'project_updates'
            ELSE 'project_updates'
            END AS inferred_category
            FROM (SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ?) owned_notifications)";
        $notificationQueryParams = [
            $notificationCategoryPatterns['announcements'],
            $notificationCategoryPatterns['evaluation'],
            $notificationCategoryPatterns['project_updates'],
            $uid,
        ];
        $notificationConditions = [];
        $notificationFilterParams = [];
        if ($notificationFilter === 'unread') {
            $notificationConditions[] = 'notification_rows.is_read = 0';
        } elseif ($notificationFilter === 'read') {
            $notificationConditions[] = 'notification_rows.is_read <> 0';
        }
        if ($notificationCategory !== 'all') {
            $notificationConditions[] = 'notification_rows.inferred_category = ?';
            $notificationFilterParams[] = $notificationCategory;
        }
        if ($notificationDate !== 'all') {
            $today = new DateTimeImmutable('today');
            $dateStart = $today;
            $dateEnd = $today->modify('+1 day');
            if ($notificationDate === 'week') {
                $dateStart = $today->modify('monday this week');
                $dateEnd = $dateStart->modify('+1 week');
            } elseif ($notificationDate === 'month') {
                $dateStart = $today->modify('first day of this month');
                $dateEnd = $dateStart->modify('+1 month');
            }
            $notificationConditions[] = 'notification_rows.created_at >= ? AND notification_rows.created_at < ?';
            $notificationFilterParams[] = $dateStart->format('Y-m-d H:i:s');
            $notificationFilterParams[] = $dateEnd->format('Y-m-d H:i:s');
        }
        if ($notificationSearch !== '') {
            $searchTerm = function_exists('mb_strtolower') ? mb_strtolower($notificationSearch, 'UTF-8') : strtolower($notificationSearch);
            $searchTerm = strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $notificationConditions[] = "LOWER(CONCAT_WS(' ', notification_rows.title, notification_rows.message)) LIKE ? ESCAPE '!'";
            $notificationFilterParams[] = '%' . $searchTerm . '%';
        }
        $notificationWhereSql = $notificationConditions ? ' WHERE ' . implode(' AND ', $notificationConditions) : '';
        $filteredCountStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $notificationRowsSql . ' AS notification_rows' . $notificationWhereSql);
        $filteredCountStmt->execute(array_merge($notificationQueryParams, $notificationFilterParams));
        $notificationFilteredCount = (int) $filteredCountStmt->fetchColumn();

        $notificationPageCount = max(1, (int) ceil($notificationFilteredCount / $notificationPageSize));
        $notificationPage = min($notificationPage, $notificationPageCount);
        $notificationPageWindowStart = max(1, $notificationPage - 2);
        $notificationPageWindowEnd = min($notificationPageCount, $notificationPage + 2);
        if ($notificationPageWindowStart > 1) {
            $notificationPageNumbers[] = 1;
            if ($notificationPageWindowStart > 2) $notificationPageNumbers[] = null;
        }
        for ($pageNumber = $notificationPageWindowStart; $pageNumber <= $notificationPageWindowEnd; $pageNumber++) {
            $notificationPageNumbers[] = $pageNumber;
        }
        if ($notificationPageWindowEnd < $notificationPageCount) {
            if ($notificationPageWindowEnd < $notificationPageCount - 1) $notificationPageNumbers[] = null;
            $notificationPageNumbers[] = $notificationPageCount;
        }
        $notificationOffset = ($notificationPage - 1) * $notificationPageSize;
        $notificationOrder = $notificationSort === 'oldest' ? 'ASC' : 'DESC';
        $notificationPageStmt = $pdo->prepare('SELECT notification_rows.* FROM ' . $notificationRowsSql . ' AS notification_rows' . $notificationWhereSql . ' ORDER BY notification_rows.created_at ' . $notificationOrder . ', notification_rows.id ' . $notificationOrder . ' LIMIT ' . $notificationPageSize . ' OFFSET ' . $notificationOffset);
        $notificationPageStmt->execute(array_merge($notificationQueryParams, $notificationFilterParams));
        $notifications = $notificationPageStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20');
        $stmt->execute([$uid]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unreadCountStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $unreadCountStmt->execute([$uid]);
        $unreadNotificationCount = (int) $unreadCountStmt->fetchColumn();
    }
} catch (PDOException $exception) {
    error_log('[notifications] Could not load student notifications: ' . $exception->getMessage());
    $notifications = [];
    $notificationLoadFailed = $view === 'notifications';
}
if ($view !== 'notifications' && !$notifications) {
    if ($latestSubmission) {
        $notifications[] = [
            'id' => null,
            'title' => 'Proposal submission recorded',
            'message' => 'Your document was submitted and is now marked ' . ucfirst(str_replace('_', ' ', $latestSubmission['status'])) . '.',
            'link' => 'students/student_dashboard.php?view=proposal',
            'is_read' => 1,
            'created_at' => $latestSubmission['created_at'],
        ];
    }
    foreach ($assignedEvaluators as $assignedEvaluator) {
        $notifications[] = [
            'id' => null,
            'title' => 'Evaluator assigned',
            'message' => ($assignedEvaluator['full_name'] ?? 'An evaluator') . ' is assigned to your capstone project.',
            'link' => 'students/student_dashboard.php?view=proposal',
            'is_read' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
}
$panelScores = $project ? fuzzy_consolidated_scores($pdo, (int) $project['id']) : [
    'project_score' => null,
    'paper_score' => null,
    'project_evaluator_count' => 0,
    'paper_evaluator_count' => 0,
    'final_score' => null,
];
$projectScore = $panelScores['project_score'];
$paperScore = $panelScores['paper_score'];
$overallScore = $panelScores['final_score'];
$hasProjectEvaluation = $panelScores['project_evaluator_count'] > 0;
$hasPaperEvaluation = $panelScores['paper_evaluator_count'] > 0;
$hasFinalResult = $panelScores['final_score'] !== null;
$officialVerdict = $hasFinalResult ? official_verdict_from_score($overallScore) : null;
$rating = $officialVerdict['label'] ?? 'Not evaluated';
$ratingDescription = $officialVerdict['description'] ?? '';
$ratingNextStep = $officialVerdict['next_step'] ?? '';
$evaluationStatus = $hasFinalResult ? 'Complete' : (($hasProjectEvaluation || $hasPaperEvaluation) ? 'In progress' : 'Awaiting evaluation');
$highestCriteriaScores = [];
if ($projectEvaluation) {
    foreach ($criteria as $key => $label) {
        $criterionScore = $projectEvaluation[$key] ?? null;
        if ($criterionScore !== null && $criterionScore !== '' && (float) $criterionScore > 0) {
            $highestCriteriaScores[] = ['label' => $label, 'score' => (float) $criterionScore];
        }
    }
    usort($highestCriteriaScores, static fn($a, $b) => $b['score'] <=> $a['score']);
    $highestCriteriaScores = array_slice($highestCriteriaScores, 0, 3);
}
$notificationTitles = array_column($notifications, 'title');
if ($view !== 'notifications' && $projectEvaluation && !in_array('Project evaluation updated', $notificationTitles, true)) {
    $notifications[] = [
        'id' => null,
        'title' => 'Project evaluation updated',
        'message' => 'Your project evaluation score is now available in Evaluation Results.',
        'link' => 'students/student_dashboard.php?view=results',
        'is_read' => 1,
        'created_at' => $projectEvaluation['created_at'] ?? date('Y-m-d H:i:s'),
    ];
}
if ($view !== 'notifications' && $paperEvaluation && $projectEvaluation && !in_array('Final CEIT-T07B verdict available', $notificationTitles, true)) {
    $notifications[] = [
        'id' => null,
        'title' => 'Final CEIT-T07B verdict available',
        'message' => 'The panel-average project and paper scores are complete. View your official verdict and fuzzy analysis.',
        'link' => 'students/student_dashboard.php?view=results',
        'is_read' => 1,
        'created_at' => $paperEvaluation['created_at'] ?? date('Y-m-d H:i:s'),
    ];
}
if ($view !== 'notifications') {
    usort($notifications, static fn($a, $b) => strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? ''));
}
$notificationCategoryFor = static function (array $notification): string {
    $text = strtolower(implode(' ', [
        (string) ($notification['title'] ?? ''),
        (string) ($notification['message'] ?? ''),
    ]));
    foreach ($notificationCategoryPatterns as $category => $pattern) {
        if (preg_match('/' . $pattern . '/i', $text)) {
            return $category;
        }
    }
    return 'project_updates';
};
$notificationGroups = [];
$today = new DateTimeImmutable('today');
$yesterday = $today->modify('-1 day');
$weekStart = $today->modify('monday this week');
$monthStart = $today->modify('first day of this month');
foreach ($notifications as $notification) {
    $createdAt = strtotime((string) ($notification['created_at'] ?? ''));
    if ($createdAt === false) {
        $groupKey = 'older';
        $groupLabel = 'Earlier';
    } else {
        $createdDate = (new DateTimeImmutable('@' . $createdAt))->setTimezone($today->getTimezone())->format('Y-m-d');
        if ($createdDate === $today->format('Y-m-d')) {
            $groupKey = 'today';
            $groupLabel = 'Today';
        } elseif ($createdDate === $yesterday->format('Y-m-d')) {
            $groupKey = 'yesterday';
            $groupLabel = 'Yesterday';
        } elseif ($createdAt >= $weekStart->getTimestamp()) {
            $groupKey = 'week';
            $groupLabel = 'Earlier this week';
        } elseif ($createdAt >= $monthStart->getTimestamp()) {
            $groupKey = 'month';
            $groupLabel = 'Earlier this month';
        } else {
            $groupKey = 'older';
            $groupLabel = 'Older';
        }
    }
    if (!isset($notificationGroups[$groupKey])) {
        $notificationGroups[$groupKey] = ['label' => $groupLabel, 'items' => []];
    }
    $notificationGroups[$groupKey]['items'][] = $notification;
}
$notificationEmptyState = 'filters';
if ($notificationTotalCount === 0) {
    $notificationEmptyState = 'none';
} elseif (
    $notificationFilteredCount === 0
    && $notificationFilter === 'unread'
    && $notificationCategory === 'all'
    && $notificationDate === 'all'
    && $notificationSearch === ''
) {
    $notificationEmptyState = 'unread';
} elseif (
    $notificationFilteredCount === 0
    && $notificationFilter === 'read'
    && $notificationCategory === 'all'
    && $notificationDate === 'all'
    && $notificationSearch === ''
) {
    $notificationEmptyState = 'read';
} elseif (
    $notificationFilteredCount === 0
    && $notificationSearch !== ''
    && $notificationFilter === 'all'
    && $notificationCategory === 'all'
    && $notificationDate === 'all'
) {
    $notificationEmptyState = 'search';
}
$fuzzyTrace = null;
if ($project && $hasFinalResult) {
    $fuzzyTrace = build_fuzzy_trace($projectScore, $paperScore, $pdo);
    $overallScore = (float) $fuzzyTrace['final_score'];
    $officialVerdict = $fuzzyTrace['verdict'];
    $rating = (string) $officialVerdict['label'];
    $ratingDescription = (string) $officialVerdict['description'];
    $ratingNextStep = (string) $officialVerdict['next_step'];
}
$status = $project['status'] ?? ($latestSubmission['status'] ?? 'No proposal submitted');
$statusLower = strtolower($status);
$statusClass = str_contains($statusLower, 'evaluat') ? 'success' : (str_contains($statusLower, 'revision') ? 'warning' : 'info');
$projectActionLabel = 'Submit Project';
$projectActionView = 'proposal';
if ($latestSubmission) {
    if (str_contains($statusLower, 'draft')) {
        $projectActionLabel = 'Continue Submission';
    } elseif ($hasFinalResult) {
        $projectActionLabel = 'View Final Result';
        $projectActionView = 'results';
    } elseif ($hasProjectEvaluation || $hasPaperEvaluation || str_contains($statusLower, 'evaluat')) {
        $projectActionLabel = 'View Evaluation';
        $projectActionView = 'results';
    } else {
        $projectActionLabel = 'View Submission';
    }
}
$displayName = $studentAccount['full_name'] ?? 'Student';
$initial = strtoupper(substr($displayName, 0, 1));
$completion = min(($latestSubmission ? 30 : ($project ? 15 : 0)) + ($hasProjectEvaluation ? 35 : 0) + ($hasPaperEvaluation ? 25 : 0) + ($hasFinalResult ? 10 : 0), 100);
$formatDate = static fn($date) => $date ? date('M d, Y', strtotime($date)) : 'Not set';
$scoreLabel = static fn($score, $available = true) => !$available || $score === null ? '--' : number_format((float) $score, 2);
$scorePercentLabel = static fn($score, $available = true) => !$available || $score === null ? '--' : number_format((float) $score, 2) . '%';
$feedback = array_merge(array_map(static function ($item) { $item['type'] = 'System evaluation'; return $item; }, $projectFeedback), array_map(static function ($item) { $item['type'] = 'Paper evaluation'; return $item; }, $paperFeedback), $feedbackRows);
usort($feedback, static fn($a, $b) => strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? ''));
$feedback = array_slice($feedback, 0, 4);
$historyRecords = [];
$historySubmissionCount = count($submissions);
$historyEvaluationCount = 0;
$historyVerdictCount = 0;
$historySearch = trim((string) ($_GET['history_search'] ?? ''));
$historySearch = function_exists('mb_substr') ? mb_substr($historySearch, 0, 120) : substr($historySearch, 0, 120);
$historyType = (string) ($_GET['history_type'] ?? 'all');
if (!in_array($historyType, ['all', 'submissions', 'evaluations', 'verdicts'], true)) $historyType = 'all';
$historyStatus = (string) ($_GET['history_status'] ?? 'all');
$historyStatus = function_exists('mb_substr') ? mb_substr($historyStatus, 0, 120) : substr($historyStatus, 0, 120);
$historyDateInput = static function ($value): string {
    $value = (string) $value;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return '';
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : '';
};
$historyFrom = $historyDateInput($_GET['history_from'] ?? '');
$historyTo = $historyDateInput($_GET['history_to'] ?? '');
$historyPage = max(1, (int) ($_GET['history_page'] ?? 1));
$historyStatuses = [];
$historyFilteredRecords = [];
$historyPageRecords = [];
$historyPageCount = 1;
$historyAddRecord = static function (array $record) use (&$historyRecords): void {
    $searchParts = [$record['title'], $record['description'], $record['status_label']];
    foreach ($record['details'] as $detail) {
        $searchParts[] = $detail['label'];
        $searchParts[] = $detail['value'];
    }
    $record['search_text'] = implode(' ', array_map('strval', $searchParts));
    $record['timestamp_value'] = strtotime($record['created_at']);
    $historyRecords[] = $record;
};
if ($view === 'history') {
    foreach ($submissions as $submission) {
        $submissionStatus = (string) ($submission['status'] ?? '');
        $submissionName = (string) ($submission['original_filename'] ?? 'Submitted file');
        $submissionDetails = [
            ['label' => 'Submission ID', 'value' => (string) ($submission['id'] ?? '')],
            ['label' => 'Filename', 'value' => $submissionName],
            ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', $submissionStatus))],
        ];
        if (!empty($submission['mime_type'])) $submissionDetails[] = ['label' => 'File type', 'value' => (string) $submission['mime_type']];
        if (isset($submission['file_size'])) $submissionDetails[] = ['label' => 'File size', 'value' => number_format((int) $submission['file_size']) . ' bytes'];
        $historyAddRecord([
            'type' => 'submissions',
            'kind' => 'submission',
            'sort_id' => (int) ($submission['id'] ?? 0),
            'title' => 'Submission: ' . $submissionName,
            'description' => 'A project file was submitted' . (!empty($project['title']) ? ' for ' . $project['title'] : '') . '.',
            'created_at' => (string) ($submission['created_at'] ?? ''),
            'status_value' => $submissionStatus,
            'status_label' => ucfirst(str_replace('_', ' ', $submissionStatus)),
            'details' => $submissionDetails,
        ]);
    }

    $historyEvaluationRows = [];
    if ($project) {
        $historyProjectId = (int) $project['id'];
        $historyProjectStmt = $pdo->prepare('SELECT e.id, e.evaluator_id, u.full_name AS evaluator_name, e.total_score, e.remarks, e.created_at, e.functionality, e.technical_quality, e.performance, e.usability, e.uiux, e.security, e.innovation, e.relevance, e.reliability, e.completeness FROM evaluations_project e LEFT JOIN users u ON u.id = e.evaluator_id INNER JOIN projects p ON p.id = e.project_id WHERE p.id = ? AND p.student_id = ? ORDER BY e.created_at DESC, e.id DESC');
        $historyProjectStmt->execute([$historyProjectId, $uid]);
        foreach ($historyProjectStmt->fetchAll(PDO::FETCH_ASSOC) as $evaluation) {
            $historyEvaluationRows[] = ['type' => 'project', 'row' => $evaluation];
        }
        $historyPaperStmt = $pdo->prepare('SELECT e.id, e.evaluator_id, u.full_name AS evaluator_name, e.total_score, e.remarks, e.created_at, e.chap1, e.chap2, e.chap3, e.chap4, e.chap5 FROM evaluations_paper e LEFT JOIN users u ON u.id = e.evaluator_id INNER JOIN projects p ON p.id = e.project_id WHERE p.id = ? AND p.student_id = ? ORDER BY e.created_at DESC, e.id DESC');
        $historyPaperStmt->execute([$historyProjectId, $uid]);
        foreach ($historyPaperStmt->fetchAll(PDO::FETCH_ASSOC) as $evaluation) {
            $historyEvaluationRows[] = ['type' => 'paper', 'row' => $evaluation];
        }
    }

    foreach ($historyEvaluationRows as $historyEvaluation) {
        $evaluationType = $historyEvaluation['type'];
        $evaluation = $historyEvaluation['row'];
        $evaluationName = $evaluationType === 'project' ? 'System evaluation' : 'Paper evaluation';
        $evaluatorName = trim((string) ($evaluation['evaluator_name'] ?? ''));
        $details = [
            ['label' => 'Evaluation ID', 'value' => (string) $evaluation['id']],
            ['label' => 'Evaluator', 'value' => $evaluatorName !== '' ? $evaluatorName : 'Evaluator #' . (int) $evaluation['evaluator_id']],
        ];
        if ($evaluation['total_score'] !== null && $evaluation['total_score'] !== '') {
            $details[] = ['label' => 'Score', 'value' => number_format((float) $evaluation['total_score'], 2) . ' / 100'];
        }
        if ($evaluationType === 'project') {
            foreach ($criteria as $criterionKey => $criterionLabel) {
                if (array_key_exists($criterionKey, $evaluation) && $evaluation[$criterionKey] !== null) {
                    $details[] = ['label' => $criterionLabel, 'value' => (string) $evaluation[$criterionKey]];
                }
            }
        } else {
            for ($chapter = 1; $chapter <= 5; $chapter++) {
                $chapterKey = 'chap' . $chapter;
                if (array_key_exists($chapterKey, $evaluation) && $evaluation[$chapterKey] !== null) {
                    $details[] = ['label' => 'Chapter ' . $chapter, 'value' => (string) $evaluation[$chapterKey]];
                }
            }
        }
        if (trim((string) ($evaluation['remarks'] ?? '')) !== '') {
            $details[] = ['label' => 'Evaluator remarks', 'value' => (string) $evaluation['remarks']];
        }
        $historyAddRecord([
            'type' => 'evaluations',
            'kind' => 'evaluation',
            'sort_id' => (int) $evaluation['id'],
            'title' => $evaluationName,
            'description' => ($evaluatorName !== '' ? 'Evaluated by ' . $evaluatorName : 'Evaluator #' . (int) $evaluation['evaluator_id'] . ' submitted an evaluation') . '.',
            'created_at' => (string) $evaluation['created_at'],
            'status_value' => 'evaluated',
            'status_label' => 'Evaluated',
            'details' => $details,
        ]);
        $historyEvaluationCount++;
    }

    foreach ($feedbackRows as $feedbackRow) {
        $feedbackType = strtolower((string) ($feedbackRow['evaluation_type'] ?? ''));
        $feedbackDetails = [];
        foreach ([
            'strengths' => 'Strengths',
            'weaknesses' => 'Areas to improve',
            'recommendations' => 'Recommendations',
            'required_revisions' => 'Required revisions',
            'remarks' => 'Evaluator remarks',
        ] as $feedbackField => $feedbackLabel) {
            $feedbackText = trim((string) ($feedbackRow[$feedbackField] ?? ''));
            if ($feedbackText !== '') $feedbackDetails[] = ['label' => $feedbackLabel, 'value' => $feedbackText];
        }
        $feedbackEvaluator = trim((string) ($feedbackRow['evaluator_name'] ?? ''));
        if ($feedbackEvaluator === '') $feedbackEvaluator = 'Evaluator #' . (int) ($feedbackRow['evaluator_id'] ?? 0);
        $historyAddRecord([
            'type' => 'evaluations',
            'kind' => 'evaluation',
            'sort_id' => (int) ($feedbackRow['id'] ?? 0),
            'title' => ucfirst($feedbackType) . ' evaluation feedback',
            'description' => 'Feedback recorded by ' . $feedbackEvaluator . '.',
            'created_at' => (string) ($feedbackRow['created_at'] ?? ''),
            'status_value' => 'feedback_recorded',
            'status_label' => 'Feedback recorded',
            'details' => $feedbackDetails,
        ]);
        $historyEvaluationCount++;
    }

    $historyLatestEvaluationTimestamp = 0;
    foreach ($historyEvaluationRows as $historyEvaluation) {
        $evaluationTimestamp = strtotime((string) ($historyEvaluation['row']['created_at'] ?? ''));
        if ($evaluationTimestamp !== false) $historyLatestEvaluationTimestamp = max($historyLatestEvaluationTimestamp, $evaluationTimestamp);
    }
    if ($project && $hasProjectEvaluation && $hasPaperEvaluation && $hasFinalResult && $historyLatestEvaluationTimestamp > 0) {
        $historyVerdictCount = 1;
        $historyAddRecord([
            'type' => 'verdicts',
            'kind' => 'verdict',
            'sort_id' => 0,
            'title' => 'Derived CEIT-T07B verdict',
            'description' => 'Derived from persisted project and paper evaluation rows; no separate verdict record is stored.',
            'created_at' => date('Y-m-d H:i:s', $historyLatestEvaluationTimestamp),
            'status_value' => (string) $rating,
            'status_label' => 'Derived: ' . (string) $rating,
            'details' => [
                ['label' => 'Project panel average', 'value' => number_format((float) $projectScore, 2) . ' / 100'],
                ['label' => 'Paper panel average', 'value' => number_format((float) $paperScore, 2) . ' / 100'],
                ['label' => 'Derived average score', 'value' => number_format((float) $overallScore, 2) . ' / 100'],
                ['label' => 'Derived CEIT-T07B classification', 'value' => (string) $rating],
            ],
        ]);
    }

    foreach ($historyRecords as $historyRecord) {
        if (!in_array($historyRecord['status_value'], $historyStatuses, true)) $historyStatuses[] = $historyRecord['status_value'];
    }
    sort($historyStatuses, SORT_NATURAL | SORT_FLAG_CASE);
    if ($historyStatus !== 'all' && !in_array($historyStatus, $historyStatuses, true)) $historyStatus = 'all';
    $historyFilteredRecords = array_values(array_filter($historyRecords, static function (array $record) use ($historySearch, $historyType, $historyStatus, $historyFrom, $historyTo): bool {
        if ($historyType !== 'all' && $record['type'] !== $historyType) return false;
        if ($historyStatus !== 'all' && $record['status_value'] !== $historyStatus) return false;
        $recordDate = substr($record['created_at'], 0, 10);
        if ($historyFrom !== '' && $recordDate < $historyFrom) return false;
        if ($historyTo !== '' && $recordDate > $historyTo) return false;
        if ($historySearch !== '') {
            $matched = function_exists('mb_stripos')
                ? mb_stripos($record['search_text'], $historySearch) !== false
                : stripos($record['search_text'], $historySearch) !== false;
            if (!$matched) return false;
        }
        return true;
    }));
    usort($historyFilteredRecords, static function (array $first, array $second): int {
        return ($second['timestamp_value'] <=> $first['timestamp_value']) ?: ($second['sort_id'] <=> $first['sort_id']);
    });
    $historyPageCount = max(1, (int) ceil(count($historyFilteredRecords) / 10));
    $historyPage = min($historyPage, $historyPageCount);
    $historyPageRecords = array_slice($historyFilteredRecords, ($historyPage - 1) * 10, 10);
}
$steps = [['Proposal submitted', $latestSubmission ? $formatDate($latestSubmission['created_at']) : 'Not submitted', (bool) $latestSubmission], ['System evaluation', $hasProjectEvaluation ? 'Panel average available' : 'Waiting for evaluator', $hasProjectEvaluation], ['Paper evaluation', $hasPaperEvaluation ? 'Panel average available' : 'Waiting for evaluator', $hasPaperEvaluation], ['Official decision', $hasFinalResult ? $rating : 'Awaiting both panel averages', $hasFinalResult]];
$uploadMessages = ['uploaded' => 'Proposal submitted successfully.', 'invalid_pdf' => 'Only valid PDF files up to 10 MB are accepted.', 'upload_failed' => 'The proposal upload failed. Please try again.', 'storage_failed' => 'The proposal could not be saved. Please contact the administrator.', 'save_failed' => 'The proposal could not be recorded. Please contact the administrator.', 'no_project' => 'A project record is required before submitting a proposal.', 'invalid_request' => 'The submission request could not be verified.'];
$uploadMessageKey = isset($_GET['uploaded']) ? 'uploaded' : ($_GET['error'] ?? '');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=esc(ucfirst($view))?> - BSIT Capstone Portal</title><link rel="stylesheet" href="/Fuzzy_Score/assets/style.css?v=20261102-notification-pagination"><link rel="icon" type="image/png" href="../favicon.png?v=20261016"></head>
<body class="student-portal"><div class="student-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="student-main"><header class="student-topbar"><div><span class="eyebrow">BSIT CAPSTONE PROJECT</span><strong>Student Portal</strong></div><div class="student-account"><span class="student-avatar"><?=esc($initial)?></span><span><strong><?=esc($displayName)?></strong><small>Student</small></span></div></header><div class="student-content">
<?php if ($view === 'overview'): ?><div class="student-page-heading"><div><h1>Student Dashboard</h1><p>Track your BSIT capstone project and fuzzy evaluation progress.</p></div><span class="status-pill <?=$statusClass?>">● <?=esc($status)?></span></div>
<section class="project-banner"><div><span class="banner-kicker">CURRENT CAPSTONE PROJECT</span><h2><?=esc($project['title'] ?? 'No capstone project assigned yet')?></h2><p><?=esc($project['code'] ?? 'Awaiting project registration')?> · <?=esc($project['academic_year'] ?? 'Academic year not set')?></p><a class="btn primary" href="?view=<?=$projectActionView?>"><?=esc($projectActionLabel)?></a></div><div class="banner-score"><small class="banner-result-label">FINAL VERDICT</small><strong class="banner-verdict"><?=esc($rating)?></strong><span class="banner-score-value"><?php if ($hasFinalResult): ?><?=number_format($overallScore, 2)?> <em>/ 100</em><?php else: ?>Awaiting final score<?php endif; ?></span></div></section>
<section class="portal-panel verdict-description-card" aria-labelledby="verdict-description-title">
  <div class="verdict-description-heading">
    <span class="verdict-description-icon" aria-hidden="true">▤</span>
    <div>
      <span class="eyebrow">RESULT EXPLANATION</span>
      <h2 id="verdict-description-title">Final Verdict Description</h2>
      <?php if ($hasFinalResult): ?>
        <p><?=esc($ratingDescription)?></p>
      <?php elseif ($hasProjectEvaluation || $hasPaperEvaluation): ?>
        <p>Your result is provisional while the panel's project and paper evaluations are completed. The official verdict will appear when both component averages are available.</p>
      <?php else: ?>
        <p>A final verdict will appear after your project and paper evaluations have been recorded.</p>
      <?php endif; ?>
    </div>
  </div>
  <div class="verdict-summary-grid">
    <div class="verdict-summary-item"><small>FINAL AVERAGE SCORE</small><strong><?=$hasFinalResult ? number_format($overallScore, 2) . ' / 100' : 'Pending'?></strong></div>
    <div class="verdict-summary-item"><small>ROUNDED CLASSIFICATION SCORE</small><strong><?=$hasFinalResult ? (int) $officialVerdict['rounded_score'] . ' / 100' : 'Pending'?></strong></div>
    <div class="verdict-summary-item"><small>VERDICT</small><strong><?=esc($rating)?></strong></div>
    <div class="verdict-summary-item"><small>RECOMMENDED NEXT STEP</small><strong><?=esc($ratingNextStep ?: 'Pending')?></strong></div>
    <div class="verdict-summary-item"><small>EVALUATION STATUS</small><strong><?=esc($evaluationStatus)?></strong></div>
    <div class="verdict-summary-item"><small>PROJECT STATUS</small><strong><?=esc($status)?></strong></div>
  </div>
  <div class="verdict-support-grid">
    <section class="verdict-support-section">
      <h3>Strengths and higher-scoring areas</h3>
      <?php if ($verdictStrengths || $highestCriteriaScores): ?>
        <?php if ($verdictStrengths): ?><ul><?php foreach ($verdictStrengths as $item): ?><li><span><?=esc($item['type'])?> feedback</span><?=nl2br(esc($item['text']))?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if ($highestCriteriaScores): ?><ul class="verdict-score-list"><?php foreach ($highestCriteriaScores as $item): ?><li><span><?=esc($item['label'])?></span><strong><?=number_format($item['score'], 2)?> / 10</strong></li><?php endforeach; ?></ul><p class="verdict-context-note">These are the highest recorded project-criterion scores, shown as score comparisons rather than additional panel findings.</p><?php endif; ?>
      <?php else: ?><p class="verdict-empty-note">No specific strengths have been recorded in evaluator feedback yet.</p><?php endif; ?>
    </section>
    <section class="verdict-support-section">
      <h3>Required revisions and recommendations</h3>
      <?php if ($verdictRevisions): ?><ul><?php foreach ($verdictRevisions as $item): ?><li><span><?=esc($item['type'])?> feedback</span><?=nl2br(esc($item['text']))?></li><?php endforeach; ?></ul><?php else: ?><p class="verdict-empty-note">No specific revisions or recommendations have been recorded yet.</p><?php endif; ?>
    </section>
  </div>
  <div class="verdict-remarks-grid">
    <section class="verdict-remarks-section">
      <h3>Project evaluation remarks</h3>
      <?php if ($projectFeedback): ?><?php foreach (array_slice($projectFeedback, 0, 3) as $item): ?><blockquote><?=nl2br(esc($item['remarks']))?><small><?=esc($formatDate($item['created_at']))?></small></blockquote><?php endforeach; ?><?php else: ?><p class="verdict-empty-note">No additional project remarks have been provided yet.</p><?php endif; ?>
    </section>
    <section class="verdict-remarks-section">
      <h3>Technical paper evaluation remarks</h3>
      <?php if ($paperFeedback): ?><?php foreach (array_slice($paperFeedback, 0, 3) as $item): ?><blockquote><?=nl2br(esc($item['remarks']))?><small><?=esc($formatDate($item['created_at']))?></small></blockquote><?php endforeach; ?><?php else: ?><p class="verdict-empty-note">No additional paper remarks have been provided yet.</p><?php endif; ?>
    </section>
  </div>
</section>
<section class="student-stat-grid"><div class="student-stat"><span class="stat-icon teal">◈</span><small>SYSTEM SCORE</small><strong><?=$scorePercentLabel($projectScore, $hasProjectEvaluation)?><em>/100%</em></strong><span><?=$hasProjectEvaluation ? 'Evaluated' : 'Awaiting evaluation'?></span></div><div class="student-stat"><span class="stat-icon blue">▤</span><small>PAPER SCORE</small><strong><?=$scorePercentLabel($paperScore, $hasPaperEvaluation)?><em>/100%</em></strong><span><?=$hasPaperEvaluation ? 'Evaluated' : 'Awaiting evaluation'?></span></div><div class="student-stat"><span class="stat-icon violet">◉</span><small>PROJECT PROGRESS</small><strong><?=$completion?>%</strong><span>Submission completion</span></div></section>
<div class="student-two-col"><section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">PROJECT TRACKER</span><h3>Proposal Timeline</h3></div><a href="?view=proposal">View details →</a></div><div class="timeline"><?php foreach ($steps as $step): ?><div class="timeline-item <?= $step[2] ? 'done' : '' ?>"><span class="timeline-dot"><?= $step[2] ? '✓' : '○' ?></span><div><strong><?=esc($step[0])?></strong><small><?=esc($step[1])?></small></div></div><?php endforeach; ?></div></section><section class="portal-panel project-summary"><div class="panel-heading"><div><span class="eyebrow">CAPSTONE DETAILS</span><h3>Project Information</h3></div></div><dl><dt>Group</dt><dd><?=esc($studentAccount['group_name'] ?? $project['group_name'] ?? 'Not assigned')?></dd><dt>Adviser</dt><dd><?=esc($project['adviser'] ?? 'Not assigned')?></dd><dt>Defense date</dt><dd><?=esc($formatDate($project['defense_date'] ?? null))?></dd><dt>Description</dt><dd><?=esc($project['description'] ?? 'No project description yet.')?></dd></dl></section></div>
<section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">LATEST FEEDBACK</span><h3>Evaluator Comments</h3></div><a href="?view=results">See all results →</a></div><?php if ($feedback): ?><div class="feedback-list"><?php foreach ($feedback as $item): ?><article class="feedback-item"><span class="feedback-avatar"><?=esc(strtoupper(substr($item['type'], 0, 1)))?></span><div><strong><?=esc($item['type'])?></strong><small><?=esc($formatDate($item['created_at']))?></small><p><?=esc($item['remarks'])?></p></div></article><?php endforeach; ?></div><?php else: ?><div class="empty-state">No evaluator feedback has been recorded yet.</div><?php endif; ?></section>
<?php elseif ($view === 'proposal'): ?><div class="student-page-heading"><div><h1>Proposal Status</h1><p>Follow your project from submission to final defense.</p></div></div><section class="project-banner compact"><div><span class="banner-kicker">CURRENT STATUS</span><h2><?=esc($status)?></h2><p><?=esc($project['title'] ?? 'No project assigned')?></p></div><span class="status-pill <?=$statusClass?>">● <?=esc($status)?></span></section><section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">YOUR PROJECT PIPELINE</span><h3>Proposal Timeline</h3></div></div><div class="timeline timeline-wide"><?php foreach ($steps as $step): ?><div class="timeline-item <?= $step[2] ? 'done' : '' ?>"><span class="timeline-dot"><?= $step[2] ? '✓' : '○' ?></span><div><strong><?=esc($step[0])?></strong><small><?=esc($step[1])?></small></div></div><?php endforeach; ?></div></section><section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">PROJECT INFORMATION</span><h3>Registered Proposal</h3></div></div><div class="detail-grid"><div><small>Project code</small><strong><?=esc($project['code'] ?? '--')?></strong></div><div><small>Academic year</small><strong><?=esc($project['academic_year'] ?? '--')?></strong></div><div><small>Group name</small><strong><?=esc($project['group_name'] ?? '--')?></strong></div><div><small>Defense date</small><strong><?=esc($formatDate($project['defense_date'] ?? null))?></strong></div></div><p class="project-description"><?=esc($project['description'] ?? 'Your adviser has not registered a proposal for this account yet.')?></p></section>
<?php elseif ($view === 'results'): ?><div class="student-page-heading"><div><h1>Evaluation Results</h1><p>Your panel-average system and paper scores, official CEIT-T07B verdict, and separate verdict-based analysis.</p></div><span class="status-pill success">◈ <?=$hasFinalResult ? number_format($overallScore, 2) : 'Pending'?> / 100</span></div><section class="result-hero"><div><span class="banner-kicker">OFFICIAL CEIT-T07B FINAL AVERAGE</span><strong><?=$hasFinalResult ? number_format($overallScore, 2) : 'Pending'?><em>/100</em></strong><p>Classification score: <?=$hasFinalResult ? (int) $officialVerdict['rounded_score'] . ' / 100 — ' : ''?><?=esc($rating)?><?php if ($ratingDescription !== ''): ?> — <?=esc($ratingDescription)?><?php endif; ?></p><?php if ($hasFinalResult): ?><p><strong>Recommended next step:</strong> <?=esc($ratingNextStep)?></p><?php endif; ?></div><div class="fuzzy-note">The official score is the unweighted arithmetic mean of the consolidated project and paper scores. Verdict-rule analysis is shown separately and does not determine the official verdict.</div></section><div class="student-two-col results-grid"><section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">SYSTEM EVALUATION</span><h3>Criteria Performance</h3></div><strong class="panel-score"><?=$scorePercentLabel($projectScore)?> <small>/100%</small></strong></div><?php if ($projectEvaluation): ?><div class="criteria-grid"><?php foreach ($criteria as $key => $label): $value = (float) ($projectEvaluation[$key] ?? 0) * 10; ?><div class="result-score-item"><span><?=esc($label)?></span><strong><?=number_format($value, 0)?>%</strong></div><?php endforeach; ?></div><?php else: ?><div class="empty-state">System criteria have not been evaluated yet.</div><?php endif; ?></section><section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">PAPER EVALUATION</span><h3>Chapter Breakdown</h3></div><strong class="panel-score"><?=$scorePercentLabel($paperScore)?> <small>/100%</small></strong></div><?php if ($paperEvaluation): ?><div class="chapter-score-list"><?php for ($i = 1; $i <= 5; $i++): $value = (float) ($paperEvaluation['chap'.$i] ?? 0) * 5; ?><div class="result-score-item"><span>Chapter <?=$i?></span><strong><?=number_format($value, 0)?>%</strong></div><?php endfor; ?></div><?php else: ?><div class="empty-state">Paper chapters have not been evaluated yet.</div><?php endif; ?></section></div><?php if ($feedback): ?><section class="portal-panel"><div class="panel-heading"><div><span class="eyebrow">EVALUATOR COMMENTS</span><h3>Feedback for Improvement</h3></div></div><div class="feedback-list"><?php foreach ($feedback as $item): ?><article class="feedback-item"><span class="feedback-avatar">✦</span><div><strong><?=esc($item['type'])?></strong><small><?=esc($formatDate($item['created_at']))?></small><p><?=esc($item['remarks'])?></p></div></article><?php endforeach; ?></div></section><?php endif; ?>
<?php if ($fuzzyTrace): ?><section class="portal-panel" style="margin-top:24px;"><div class="panel-heading"><div><span class="eyebrow">VERDICT RULE ANALYSIS</span><h3>Verdict Rule Analysis Trace</h3></div></div><div class="detail-grid"><div><small>Official average score</small><strong><?=number_format($fuzzyTrace['final_score'], 2)?> / 100</strong></div><div><small>Rounded classification score</small><strong><?=esc($fuzzyTrace['rounded_score'])?> / 100</strong></div><div><small>Representative-score analysis output</small><strong><?=esc($fuzzyTrace['defuzz'])?> / 100</strong></div><div><small>Official CEIT-T07B verdict</small><strong><?=esc($fuzzyTrace['rating'])?></strong></div></div><?php foreach ($fuzzyTrace['inputMemberships'] ?? [] as $var => $membership): ?><div style="margin-top:18px;"><h4><?=esc($var)?> Membership Values</h4><div class="score-bars"><?php foreach ($membership as $label => $value): ?><div class="score-row"><div><span><?=esc($label)?></span><strong><?=number_format((float)$value, 3)?></strong></div><i><b style="width: <?=min(100, (float)$value * 100)?>%"></b></i></div><?php endforeach; ?></div></div><?php endforeach; ?><?php if (!empty($fuzzyTrace['firedRules'])): ?><div style="margin-top:18px;"><h4>Matching Verdict Rule</h4><ul style="margin:0; padding-left:20px;"><?php foreach ($fuzzyTrace['firedRules'] as $rule): ?><li><strong>Rule #<?=esc($rule['id'])?></strong> — <?=esc($rule['rule_text'])?> <span style="color:#0f172a;">(strength <?=number_format((float)($rule['strength'] ?? 0), 3)?>)</span></li><?php endforeach; ?></ul></div><?php endif; ?></section><?php endif; ?><?php elseif ($view === 'history'): ?>
<div class="student-history-page">
  <div class="student-page-heading history-page-heading">
    <div><span class="eyebrow">YOUR PROJECT RECORD</span><h1>Submission History</h1><p>Review recorded submissions, persisted evaluations, and your derived CEIT-T07B verdict.</p></div>
  </div>
  <section class="history-summary-grid" aria-label="History summary">
    <article class="history-summary"><span class="history-summary-icon submission" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M7 3.75h7l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V5.25a1.5 1.5 0 0 1 1-1.5Z"/><path d="M14 4v4h4M12 17V11m-2.5 2.5L12 11l2.5 2.5"/></svg></span><div><small>TOTAL SUBMISSIONS</small><strong><?=number_format($historySubmissionCount)?></strong><p>Files recorded to your account</p></div></article>
    <article class="history-summary"><span class="history-summary-icon evaluation" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="5" y="4.5" width="14" height="17" rx="2"/><path d="M9 4.5V3h6v1.5M8.5 12l2.3 2.3 4.7-4.8"/></svg></span><div><small>TOTAL EVALUATIONS</small><strong><?=number_format($historyEvaluationCount)?></strong><p>Persisted scores and feedback</p></div></article>
    <article class="history-summary"><span class="history-summary-icon verdict" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M8 4h8v3.5a4 4 0 0 1-8 0V4Zm0 2H4v1a4 4 0 0 0 4 4m8-5h4v1a4 4 0 0 1-4 4m-4 0v5m-4 0h8m-9 4h10"/></svg></span><div><small>FINAL VERDICTS</small><strong><?=number_format($historyVerdictCount)?></strong><p>Derived from stored project and paper evaluation rows</p></div></article>
  </section>
  <section class="history-filter-panel" aria-label="Filter history records">
    <form method="get" action="">
      <input type="hidden" name="view" value="history">
      <label class="history-search-field" for="history-search"><span>Search history</span><input id="history-search" type="search" name="history_search" value="<?=esc($historySearch)?>" placeholder="Title, comments, or details" maxlength="120"></label>
      <label for="history-type"><span>Type</span><select id="history-type" name="history_type"><option value="all" <?=$historyType === 'all' ? 'selected' : ''?>>All</option><option value="submissions" <?=$historyType === 'submissions' ? 'selected' : ''?>>Submissions</option><option value="evaluations" <?=$historyType === 'evaluations' ? 'selected' : ''?>>Evaluations</option><option value="verdicts" <?=$historyType === 'verdicts' ? 'selected' : ''?>>Verdicts</option></select></label>
      <label for="history-status"><span>Status</span><select id="history-status" name="history_status"><option value="all">All statuses</option><?php foreach ($historyStatuses as $historyStatusOption): ?><option value="<?=esc($historyStatusOption)?>" <?=$historyStatus === $historyStatusOption ? 'selected' : ''?>><?=esc(ucfirst(str_replace('_', ' ', $historyStatusOption)))?></option><?php endforeach; ?></select></label>
      <label for="history-from"><span>From</span><input id="history-from" type="date" name="history_from" value="<?=esc($historyFrom)?>"></label>
      <label for="history-to"><span>To</span><input id="history-to" type="date" name="history_to" value="<?=esc($historyTo)?>"></label>
      <div class="history-filter-actions"><a href="?view=history">Clear filters</a><button type="submit">Apply filters</button></div>
    </form>
  </section>
  <section class="history-timeline-panel" aria-label="History timeline">
    <?php if (!$historyFilteredRecords): ?>
      <p class="history-empty" role="status">No history records found.</p>
    <?php else: ?>
      <div class="history-timeline">
        <?php foreach ($historyPageRecords as $historyRecord): ?>
          <article class="history-record <?=esc($historyRecord['kind'])?>">
            <span class="history-marker" aria-hidden="true"><?php if ($historyRecord['kind'] === 'submission'): ?>&#8593;<?php elseif ($historyRecord['kind'] === 'verdict'): ?>&#9733;<?php else: ?>&#9672;<?php endif; ?></span>
            <div class="history-record-content">
              <div class="history-record-heading"><div><h2><?=esc($historyRecord['title'])?></h2><p><?=esc($historyRecord['description'])?></p></div><span class="history-record-status"><?=esc($historyRecord['status_label'])?></span></div>
              <div class="history-record-meta"><time datetime="<?=esc(date('c', $historyRecord['timestamp_value']))?>"><?=esc(date('M j, Y, g:i A', $historyRecord['timestamp_value']))?></time><span><?=esc(ucfirst($historyRecord['type']))?></span></div>
              <?php if ($historyRecord['details']): ?><details class="history-record-details"><summary>View details</summary><dl><?php foreach ($historyRecord['details'] as $historyDetail): ?><div><dt><?=esc($historyDetail['label'])?></dt><dd><?=nl2br(esc($historyDetail['value']))?></dd></div><?php endforeach; ?></dl></details><?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if (count($historyFilteredRecords) > 10): $historyRangeStart = (($historyPage - 1) * 10) + 1; $historyRangeEnd = min($historyPage * 10, count($historyFilteredRecords)); $historyPaginationQuery = ['view' => 'history', 'history_search' => $historySearch, 'history_type' => $historyType, 'history_status' => $historyStatus, 'history_from' => $historyFrom, 'history_to' => $historyTo]; ?>
        <nav class="history-pagination" aria-label="History pages">
          <span class="history-pagination-count">Showing <?=number_format($historyRangeStart)?>&ndash;<?=number_format($historyRangeEnd)?> of <?=number_format(count($historyFilteredRecords))?> records</span>
          <div class="history-pagination-links">
            <?php if ($historyPage > 1): ?><a href="?<?=esc(http_build_query($historyPaginationQuery + ['history_page' => $historyPage - 1]))?>" aria-label="Previous page">&larr;</a><?php else: ?><span aria-disabled="true" aria-label="Previous page">&larr;</span><?php endif; ?>
            <?php for ($pageNumber = 1; $pageNumber <= $historyPageCount; $pageNumber++): ?><?php if ($pageNumber === $historyPage): ?><span class="current" aria-current="page"><?=number_format($pageNumber)?></span><?php else: ?><a href="?<?=esc(http_build_query($historyPaginationQuery + ['history_page' => $pageNumber]))?>"><?=number_format($pageNumber)?></a><?php endif; ?><?php endfor; ?>
            <?php if ($historyPage < $historyPageCount): ?><a href="?<?=esc(http_build_query($historyPaginationQuery + ['history_page' => $historyPage + 1]))?>" aria-label="Next page">&rarr;</a><?php else: ?><span aria-disabled="true" aria-label="Next page">&rarr;</span><?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>
</div>
<?php elseif ($view === 'notifications'): ?>
  <div class="student-page-heading notification-page-heading">
    <div class="notification-heading-info">
      <span class="notification-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"/></svg></span>
      <div><h1>Notifications</h1><p>Stay updated with your BSIT capstone project activities.</p></div>
    </div>
    <?php if ($unreadNotificationCount > 0): ?>
      <form method="post" action="?view=notifications" class="notification-bulk-form">
        <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
        <input type="hidden" name="filter" value="<?=esc($notificationFilter)?>">
        <input type="hidden" name="search" value="<?=esc($notificationSearch)?>">
        <input type="hidden" name="category" value="<?=esc($notificationCategory)?>">
        <input type="hidden" name="sort" value="<?=esc($notificationSort)?>">
        <input type="hidden" name="date" value="<?=esc($notificationDate)?>">
        <input type="hidden" name="per_page" value="<?=esc($notificationPageSize)?>">
        <input type="hidden" name="action" value="mark_all_notifications_read">
        <button class="btn primary notification-bulk-button" type="submit"><span aria-hidden="true">✓</span> Mark All as Read</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if ($notificationActionSucceeded && !$notificationLoadFailed): ?>
    <div class="notification-flash success" role="status"><?= $notificationUpdatedCount > 0 ? 'All unread notifications have been marked as read.' : 'There were no unread notifications left to mark as read.' ?></div>
  <?php elseif ($notificationActionError): ?>
    <div class="notification-flash error" role="alert"><?=esc($notificationActionErrorMessage)?></div>
  <?php endif; ?>
  <section class="notification-center" aria-label="Student notifications">
    <?php if ($notificationLoadFailed): ?>
      <div class="notification-error" role="alert">
        <span class="notification-error-mark" aria-hidden="true">!</span>
        <div><h2>Notifications couldn’t be loaded</h2><p>Your inbox is temporarily unavailable. Refresh the page to try again.</p></div>
      </div>
    <?php else: ?>
    <form method="get" action="student_dashboard.php" class="notification-toolbar">
      <input type="hidden" name="view" value="notifications">
      <input type="hidden" name="per_page" value="<?=esc($notificationPageSize)?>">
      <button class="notification-visually-hidden" type="submit" name="filter" value="<?=esc($notificationFilter)?>" tabindex="-1" aria-hidden="true">Search notifications</button>
      <div class="notification-search-row">
        <label class="notification-search" for="notification-search">
          <span class="notification-search-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></svg></span>
          <span class="notification-visually-hidden">Search notifications</span>
          <input id="notification-search" name="search" type="search" maxlength="120" value="<?=esc($notificationSearch)?>" placeholder="Search notifications...">
        </label>
      </div>
      <div class="notification-toolbar-row">
        <div class="notification-status-tabs" role="group" aria-label="Notification status">
          <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $filterKey => $filterLabel): ?>
            <button type="submit" name="filter" value="<?=esc($filterKey)?>" class="notification-status-tab <?=$notificationFilter === $filterKey ? 'active' : ''?>" <?=$notificationFilter === $filterKey ? 'aria-pressed="true"' : 'aria-pressed="false"'?>>
              <span class="notification-status-icon" aria-hidden="true">
                <?php if ($filterKey === 'all'): ?><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                <?php elseif ($filterKey === 'unread'): ?><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
                <?php else: ?><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16.5 9"/></svg><?php endif; ?>
              </span>
              <span><?=esc($filterLabel)?></span><span class="notification-tab-count"><?= (int) $notificationCounts[$filterKey] ?></span>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="notification-filter-controls">
          <label class="notification-select-label">
            <span class="notification-filter-label"><svg viewBox="0 0 24 24" focusable="false"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg>Notification Category</span>
            <select name="category">
              <option value="all" <?=$notificationCategory === 'all' ? 'selected' : ''?>>All Types</option>
              <option value="evaluation" <?=$notificationCategory === 'evaluation' ? 'selected' : ''?>>Evaluation</option>
              <option value="project_updates" <?=$notificationCategory === 'project_updates' ? 'selected' : ''?>>Project Updates</option>
              <option value="announcements" <?=$notificationCategory === 'announcements' ? 'selected' : ''?>>Announcements</option>
            </select>
          </label>
          <label class="notification-select-label">
            <span class="notification-filter-label"><svg viewBox="0 0 24 24" focusable="false"><path d="M7 3v18m0 0-3-3m3 3 3-3M17 21V3m0 0-3 3m3-3 3 3"/></svg>Sort By</span>
            <select name="sort">
              <option value="newest" <?=$notificationSort === 'newest' ? 'selected' : ''?>>Newest First</option>
              <option value="oldest" <?=$notificationSort === 'oldest' ? 'selected' : ''?>>Oldest First</option>
            </select>
          </label>
          <label class="notification-select-label">
            <span class="notification-filter-label"><svg viewBox="0 0 24 24" focusable="false"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>Date Filter</span>
            <select name="date">
              <option value="all" <?=$notificationDate === 'all' ? 'selected' : ''?>>All Dates</option>
              <option value="today" <?=$notificationDate === 'today' ? 'selected' : ''?>>Today</option>
              <option value="week" <?=$notificationDate === 'week' ? 'selected' : ''?>>This Week</option>
              <option value="month" <?=$notificationDate === 'month' ? 'selected' : ''?>>This Month</option>
            </select>
          </label>
          <a class="notification-clear-button" href="?view=notifications&amp;search=&amp;filter=all&amp;category=all&amp;sort=newest&amp;date=all&amp;per_page=<?=esc($notificationPageSize)?>&amp;page=1"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M3 11a9 9 0 1 1 2.6 6.4M3 4v7h7"/></svg><span>Clear Filters</span></a>
          <button class="notification-apply-button" type="submit" name="filter" value="<?=esc($notificationFilter)?>"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5Z"/></svg><span>Apply Filters</span></button>
        </div>
      </div>
      <p class="notification-count-scope">
        Counts above include your full inbox, independent of filters.
      </p>
    </form>
    <section class="portal-panel notification-list" aria-label="<?=esc($notificationFilter === 'all' ? 'All notifications' : ucfirst($notificationFilter) . ' notifications')?>">
      <?php if ($notificationFilteredCount === 0): ?>
        <div class="notification-empty" role="status">
          <span class="notification-empty-icon" aria-hidden="true"><?= $notificationEmptyState === 'unread' ? '✓' : '⌕' ?></span>
          <h2>
            <?php if ($notificationEmptyState === 'none'): ?>No notifications yet
            <?php elseif ($notificationEmptyState === 'unread'): ?>You’re all caught up
            <?php elseif ($notificationEmptyState === 'read'): ?>No read notifications
            <?php elseif ($notificationEmptyState === 'search'): ?>No search results
            <?php else: ?>No notifications match these filters
            <?php endif; ?>
          </h2>
          <p>
            <?php if ($notificationEmptyState === 'none'): ?>Updates about your capstone project will appear here.
            <?php elseif ($notificationEmptyState === 'unread'): ?>There are no unread notifications to review.
            <?php elseif ($notificationEmptyState === 'read'): ?>Notifications you’ve read will appear here.
            <?php elseif ($notificationEmptyState === 'search'): ?>Try another title or description keyword.
            <?php else: ?>Try adjusting your search, category, or date range to see more notifications.
            <?php endif; ?>
          </p>
        </div>
      <?php else: ?>
        <?php foreach ($notificationGroups as $group): ?>
          <section class="notification-date-group" aria-label="<?=esc($group['label'])?> notifications">
            <div class="notification-group-heading">
              <div class="notification-group-label"><span aria-hidden="true">▦</span><h2><?=esc($group['label'])?></h2></div>
              <span class="notification-group-count"><?=count($group['items'])?> <?=count($group['items']) === 1 ? 'notification' : 'notifications'?></span>
            </div>
            <?php foreach ($group['items'] as $notification):
              $isUnread = empty($notification['is_read']);
              $category = $notification['inferred_category'] ?? $notificationCategoryFor($notification);
              $categoryLabel = ['evaluation' => 'Evaluation', 'project_updates' => 'Project Updates', 'announcements' => 'Announcements'][$category] ?? 'Project Updates';
              $createdTimestamp = strtotime((string) ($notification['created_at'] ?? ''));
              $createdIso = $createdTimestamp === false ? '' : date('c', $createdTimestamp);
            ?>
              <article class="notification-card <?=$isUnread ? 'unread' : 'read'?> category-<?=esc($category)?>">
                <span class="notification-icon category-<?=esc($category)?>" aria-hidden="true">
                  <?php if ($category === 'evaluation'): ?><svg viewBox="0 0 24 24" focusable="false"><path d="m2 9 10-5 10 5-10 5L2 9Zm4 2.2V16c2.5 2.2 9.5 2.2 12 0v-4.8M22 9v6"/></svg>
                  <?php elseif ($category === 'announcements'): ?><svg viewBox="0 0 24 24" focusable="false"><path d="M3 11v2a2 2 0 0 0 2 2h2l9 5V4l-9 5H5a2 2 0 0 0-2 2Zm4 4 2 5m9-12a4 4 0 0 1 0 6m2-9a8 8 0 0 1 0 12"/></svg>
                  <?php else: ?><svg viewBox="0 0 24 24" focusable="false"><path d="M4 5h6l2 2h8v12H4zM8 11h8m-8 4h6"/></svg><?php endif; ?>
                </span>
                <div class="notification-copy">
                  <div class="notification-title-line">
                    <h3><?=esc($notification['title'])?></h3>
                    <span class="notification-category category-<?=esc($category)?>"><?=esc($categoryLabel)?></span>
                  </div>
                  <p><?=esc($notification['message'])?></p>
                  <div class="notification-card-meta">
                    <time datetime="<?=esc($createdIso)?>"><?=esc($formatDate($notification['created_at']))?></time>
                    <span class="notification-read-state <?=$isUnread ? 'unread' : 'read'?>"><?=$isUnread ? 'Unread' : 'Read'?></span>
                  </div>
                </div>
                <?php if (!empty($notification['link'])): ?><a class="notification-details" href="../<?=esc($notification['link'])?>">View Details <span aria-hidden="true">→</span></a><?php endif; ?>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php
      $notificationPageStart = $notificationFilteredCount > 0 ? (($notificationPage - 1) * $notificationPageSize) + 1 : 0;
      $notificationPageEnd = min($notificationPage * $notificationPageSize, $notificationFilteredCount);
      $notificationPageQuery = [
          'view' => 'notifications',
          'filter' => $notificationFilter,
          'search' => $notificationSearch,
          'category' => $notificationCategory,
          'sort' => $notificationSort,
          'date' => $notificationDate,
          'per_page' => $notificationPageSize,
      ];
    ?>
    <nav class="notification-pagination" aria-label="Notification pages">
      <span class="notification-pagination-info">Showing <?=number_format($notificationPageStart)?>–<?=number_format($notificationPageEnd)?> of <?=number_format($notificationFilteredCount)?> notifications</span>
      <form method="get" action="student_dashboard.php" class="notification-page-size-form">
        <input type="hidden" name="view" value="notifications">
        <input type="hidden" name="filter" value="<?=esc($notificationFilter)?>">
        <input type="hidden" name="search" value="<?=esc($notificationSearch)?>">
        <input type="hidden" name="category" value="<?=esc($notificationCategory)?>">
        <input type="hidden" name="sort" value="<?=esc($notificationSort)?>">
        <input type="hidden" name="date" value="<?=esc($notificationDate)?>">
        <input type="hidden" name="page" value="1">
        <label for="notification-page-size">Items per page</label>
        <select id="notification-page-size" name="per_page" onchange="this.form.submit()">
          <?php foreach ($allowedNotificationPageSizes as $pageSizeOption): ?>
            <option value="<?=esc($pageSizeOption)?>" <?=$notificationPageSize === $pageSizeOption ? 'selected' : ''?>><?=esc($pageSizeOption)?> per page</option>
          <?php endforeach; ?>
        </select>
        <button type="submit">Update</button>
      </form>
      <?php if ($notificationFilteredCount > 0): ?>
        <div class="notification-pagination-controls">
          <?php if ($notificationPage > 1): ?>
            <a class="notification-page-button" aria-label="Previous page" href="?<?=esc(http_build_query($notificationPageQuery + ['page' => $notificationPage - 1]))?>">←</a>
          <?php else: ?>
            <span class="notification-page-button is-disabled" aria-label="Previous page" aria-disabled="true">←</span>
          <?php endif; ?>
          <?php foreach ($notificationPageNumbers as $pageNumber): ?>
            <?php if ($pageNumber === null): ?>
              <span class="notification-page-ellipsis" aria-hidden="true">…</span>
            <?php elseif ($pageNumber === $notificationPage): ?>
              <span class="notification-page-button is-active" aria-current="page"><?=number_format($pageNumber)?></span>
            <?php else: ?>
              <a class="notification-page-button" href="?<?=esc(http_build_query($notificationPageQuery + ['page' => $pageNumber]))?>"><?=number_format($pageNumber)?></a>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($notificationPage < $notificationPageCount): ?>
            <a class="notification-page-button" aria-label="Next page" href="?<?=esc(http_build_query($notificationPageQuery + ['page' => $notificationPage + 1]))?>">→</a>
          <?php else: ?>
            <span class="notification-page-button is-disabled" aria-label="Next page" aria-disabled="true">→</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="student-profile-page" aria-labelledby="student-profile-title">
    <header class="student-profile-heading">
      <div>
        <p class="student-profile-eyebrow">ACCOUNT</p>
        <h1 id="student-profile-title">My Profile</h1>
        <p>Manage your personal and academic information.</p>
      </div>
    </header>

    <section class="student-profile-summary" aria-label="Profile summary">
      <span class="student-profile-avatar" aria-hidden="true"><?=esc($initial)?></span>
      <div class="student-profile-summary-copy">
        <h2><?=esc($displayName)?></h2>
        <p><?=esc($studentAccount['email'] ?? '')?></p>
        <span class="student-profile-role">BSIT Student</span>
      </div>
      <a class="student-profile-edit" href="#profile-full-name">Edit profile <span aria-hidden="true">↗</span></a>
    </section>

    <section class="student-profile-section" aria-labelledby="student-personal-title">
      <div class="student-profile-section-heading">
        <div>
          <p class="student-profile-eyebrow">PERSONAL DETAILS</p>
          <h2 id="student-personal-title">Personal Information</h2>
        </div>
        <span class="student-profile-section-mark" aria-hidden="true">01</span>
      </div>
      <?php if ($profileUpdated): ?>
        <div class="register-field-feedback is-valid" role="status">Your full name has been updated.</div>
      <?php endif; ?>
      <?php foreach ($profileErrors as $profileError): ?>
        <div class="register-field-feedback is-invalid" role="alert"><?=esc($profileError)?></div>
      <?php endforeach; ?>
      <div class="student-profile-personal-grid">
        <form method="post" class="student-profile-form">
          <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
          <label for="profile-full-name">Full name</label>
          <input id="profile-full-name" name="full_name" type="text" maxlength="200" value="<?=esc($_POST['full_name'] ?? $studentAccount['full_name'] ?? '')?>" required>
          <button class="btn primary" type="submit">Save full name</button>
        </form>
        <div class="student-profile-email">
          <span>Email address</span>
          <strong><?=esc($studentAccount['email'] ?? '')?></strong>
          <small>Your email address is managed by your account.</small>
        </div>
      </div>
    </section>

    <section class="student-profile-section" aria-labelledby="student-academic-title">
      <div class="student-profile-section-heading">
        <div>
          <p class="student-profile-eyebrow">STUDENT RECORD</p>
          <h2 id="student-academic-title">Academic Information</h2>
        </div>
        <span class="student-profile-section-mark" aria-hidden="true">02</span>
      </div>
      <div class="student-profile-academic-grid">
        <article class="student-profile-detail">
          <span class="student-profile-detail-icon" aria-hidden="true">R</span>
          <div><small>Role</small><strong>BSIT Student</strong></div>
        </article>
        <article class="student-profile-detail">
          <span class="student-profile-detail-icon" aria-hidden="true">ID</span>
          <div><small>Student ID Number</small><strong><?=esc($studentAccount['student_id_number'] ?? 'Not set')?></strong></div>
        </article>
        <article class="student-profile-detail">
          <span class="student-profile-detail-icon" aria-hidden="true">D</span>
          <div><small>Department</small><strong><?=esc($studentAccount['department'] ?? 'Not set')?></strong></div>
        </article>
        <article class="student-profile-detail">
          <span class="student-profile-detail-icon" aria-hidden="true">G</span>
          <div><small>Project Group</small><strong><?=esc($studentAccount['group_name'] ?? $project['group_name'] ?? 'Not set')?></strong></div>
        </article>
      </div>
    </section>
  </section>
<?php endif; ?>
</div></main></div></body></html>
