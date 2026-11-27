<?php

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/e3.php';

$dashboard = (string) file_get_contents(__DIR__ . '/../students/student_dashboard.php');
foreach ([
    "latest_stage_submission(\$pdo, (int) \$project['id'], 'final')",
    'e3_final_defense_panel_completion(',
    "WHERE project_id = ? AND final_submission_id = ? AND published_at IS NOT NULL",
    '$hasFinalDefenseComputedResult',
    '$hasPendingFinalDefenseEvaluation',
    'Evaluation Completed',
    'Awaiting Evaluation',
    'Awaiting final score',
    'MAMDANI FUZZY SCORE',
    'FUZZY LINGUISTIC RATING',
    'PANEL RECOMMENDATION',
    'Official Verdict Pending',
] as $marker) {
    if (!str_contains($dashboard, $marker)) {
        throw new RuntimeException('Student Overview Final Defense result marker is missing: ' . $marker);
    }
}

foreach ([
    '$hasPendingFinalDefenseEvaluation ? \'Pending\'',
    '$hasPendingFinalDefenseEvaluation ? \'Awaiting Evaluation\'',
    '$hasPendingFinalDefenseEvaluation ? \'Official Verdict Pending\'',
] as $marker) {
    if (!str_contains($dashboard, $marker)) {
        throw new RuntimeException('Incomplete Final Defense Overview state must not fall back to proposal results: ' . $marker);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$criteriaSql = implode(', ', array_map(
    static fn(array $criterion): string => $criterion['key'] . ' REAL NOT NULL',
    e3_criteria()
));
$pdo->exec("CREATE TABLE e3_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL, evaluator_id INTEGER NOT NULL, final_submission_id INTEGER NOT NULL,
    $criteriaSql, total_score REAL NOT NULL, status TEXT NOT NULL
)");
$pdo->exec('CREATE TABLE final_defense_evaluator_assignments (project_id INTEGER, final_submission_id INTEGER, evaluator_id INTEGER)');
$pdo->exec('CREATE TABLE e3_final_verdicts (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER, final_submission_id INTEGER, verdict_label TEXT, published_at TEXT NULL)');
$pdo->exec('INSERT INTO final_defense_evaluator_assignments VALUES (8, 21, 41), (8, 21, 42), (8, 22, 99)');

$keys = array_column(e3_criteria(), 'key');
$columns = implode(',', array_merge(['project_id', 'evaluator_id', 'final_submission_id'], $keys, ['total_score', 'status']));
$placeholders = implode(',', array_fill(0, count($keys) + 5, '?'));
$insert = $pdo->prepare("INSERT INTO e3_evaluations ($columns) VALUES ($placeholders)");
$scores = array_values(array_map(static fn(array $criterion): float => (float) $criterion['maximum'], e3_criteria()));

// One submitted evaluator must remain an incomplete panel and must not produce
// an Overview final result.
$insert->execute(array_merge([8, 41, 21], $scores, [100.0, 'submitted']));
$incomplete = e3_final_defense_panel_completion($pdo, 8, 21);
if ($incomplete['ready'] || $incomplete['submitted_count'] !== 1) {
    throw new RuntimeException('Overview must not expose an incomplete Final Defense panel result.');
}

// A submitted row for a different final submission cannot complete submission 21.
$insert->execute(array_merge([8, 99, 22], $scores, [100.0, 'submitted']));
if (e3_final_defense_panel_completion($pdo, 8, 21)['ready']) {
    throw new RuntimeException('Overview Final Defense completion must use the exact final_submission_id.');
}

$insert->execute(array_merge([8, 42, 21], $scores, [100.0, 'submitted']));
$completed = e3_final_defense_panel_completion($pdo, 8, 21);
if (!$completed['ready'] || $completed['consolidated']['calculated_score'] !== 100.0) {
    throw new RuntimeException('Completed exact Final Defense panel should expose its computed result.');
}
$recommendation = e3_stage_verdict_from_score((float) $completed['consolidated']['calculated_score']);
if (empty($recommendation['fuzzy']['defuzz']) || empty($recommendation['fuzzy']['rating']) || empty($recommendation['label'])) {
    throw new RuntimeException('Overview computed result must use the existing Mamdani Final Defense recommendation.');
}

// Official verdict is intentionally exact-submission scoped and separate from
// the computed panel result.
$pdo->exec("INSERT INTO e3_final_verdicts (project_id, final_submission_id, verdict_label, published_at) VALUES (8, 22, 'Wrong submission', '2026-01-01 00:00:00')");
$official = $pdo->prepare('SELECT verdict_label FROM e3_final_verdicts WHERE project_id = ? AND final_submission_id = ? AND published_at IS NOT NULL ORDER BY id DESC LIMIT 1');
$official->execute([8, 21]);
if ($official->fetchColumn() !== false) {
    throw new RuntimeException('A verdict for another Final Defense submission must not appear on the Overview.');
}
$pdo->exec("INSERT INTO e3_final_verdicts (project_id, final_submission_id, verdict_label, published_at) VALUES (8, 21, 'Passed', '2026-01-02 00:00:00')");
$official->execute([8, 21]);
if ($official->fetchColumn() !== 'Passed') {
    throw new RuntimeException('The Overview must display the Chairperson-confirmed exact Final Defense verdict.');
}

echo "student_overview_final_defense: ok\n";
