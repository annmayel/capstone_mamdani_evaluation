<?php

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/e3.php';

$dashboard = (string) file_get_contents(__DIR__ . '/../students/student_dashboard.php');
$finalSection = (string) file_get_contents(__DIR__ . '/../students/final_defense_results_section.php');
foreach ([
    "require __DIR__ . '/final_defense_results_section.php'",
    '$hasFinalDefenseComputedResult',
    '$latestFinalSubmission',
] as $marker) {
    if (!str_contains($dashboard, $marker)) {
        throw new RuntimeException('Evaluation Results page is missing Final Defense routing: ' . $marker);
    }
}
foreach ([
    'CEIT-UR-E3 FINAL HEARING',
    'Final Defense Evaluation Results',
    'MAMDANI FUZZY SCORE',
    'FUZZY LINGUISTIC RATING',
    'PANEL RECOMMENDATION',
    'Official Verdict Pending',
    'Final Hearing Criteria',
    'Final Hearing Evaluator Comments',
    'does not reuse Proposal Evaluation scores',
] as $marker) {
    if (!str_contains($finalSection, $marker)) {
        throw new RuntimeException('Final Defense Evaluation Results marker is missing: ' . $marker);
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
$pdo->exec('INSERT INTO final_defense_evaluator_assignments VALUES (8, 21, 41), (8, 21, 42), (8, 22, 99)');

$keys = array_column(e3_criteria(), 'key');
$columns = implode(',', array_merge(['project_id', 'evaluator_id', 'final_submission_id'], $keys, ['total_score', 'status']));
$placeholders = implode(',', array_fill(0, count($keys) + 5, '?'));
$insert = $pdo->prepare("INSERT INTO e3_evaluations ($columns) VALUES ($placeholders)");
$scoreValues = array_values(array_map(static fn(array $criterion): float => (float) $criterion['maximum'], e3_criteria()));

$insert->execute(array_merge([8, 41, 21], $scoreValues, [100.0, 'submitted']));
if (e3_final_defense_panel_completion($pdo, 8, 21)['ready']) {
    throw new RuntimeException('Incomplete Final Hearing panel must remain pending on Evaluation Results.');
}

$insert->execute(array_merge([8, 99, 22], $scoreValues, [100.0, 'submitted']));
if (e3_final_defense_panel_completion($pdo, 8, 21)['ready']) {
    throw new RuntimeException('Another Final Defense submission must not complete the current result.');
}

$insert->execute(array_merge([8, 42, 21], $scoreValues, [100.0, 'submitted']));
$completed = e3_final_defense_panel_completion($pdo, 8, 21);
if (!$completed['ready'] || $completed['consolidated']['calculated_score'] !== 100.0) {
    throw new RuntimeException('Completed exact Final Hearing panel must expose its own result.');
}
$recommendation = e3_stage_verdict_from_score((float) $completed['consolidated']['calculated_score']);
if (empty($recommendation['fuzzy']['defuzz']) || empty($recommendation['fuzzy']['rating']) || empty($recommendation['label'])) {
    throw new RuntimeException('Final Hearing result must use existing Mamdani output.');
}

echo "evaluation_results_final_defense: ok\n";
