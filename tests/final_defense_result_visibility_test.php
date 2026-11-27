<?php

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/e3.php';

$studentPortal = (string) file_get_contents(__DIR__ . '/../students/proposal.php');
foreach ([
    'e3_final_defense_panel_completion($pdo, (int) $project[\'id\'], (int) $finalSubmission[\'id\'])',
    '$finalComputedResultReady = $finalPanel[\'ready\']',
    'Evaluation Complete',
    'Mamdani Fuzzy Logic Output',
    'The official verdict is pending IT Chairperson confirmation.',
    'final-comments-card',
    'final-results-summary',
    'Final Defense Evaluation Completed',
    'final-results-timeline',
    'final-results-breakdown',
    'final-fuzzy-card',
    'final-official-card',
] as $marker) {
    if (!str_contains($studentPortal, $marker)) {
        throw new RuntimeException('Student Portal Final Defense result visibility marker is missing: ' . $marker);
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
$scoreValues = array_fill(0, count($keys), 5.0);
$higherScoreValues = array_values(array_map(static fn(array $criterion): float => (float) $criterion['maximum'], e3_criteria()));
$higherScoreValues[1] = 0.0;
$insert->execute(array_merge([8, 41, 21], $scoreValues, [60.0, 'submitted']));

$incomplete = e3_final_defense_panel_completion($pdo, 8, 21);
if ($incomplete['ready'] || $incomplete['assigned_count'] !== 2 || $incomplete['submitted_count'] !== 1 || $incomplete['consolidated']['calculated_score'] !== 60.0) {
    throw new RuntimeException('Incomplete Final Defense panel must not expose a completed result.');
}

// An unassigned E3 row and a different final submission must not affect the
// exact Final Defense panel result.
$insert->execute(array_merge([8, 77, 21], $scoreValues, [100.0, 'submitted']));
$insert->execute(array_merge([8, 99, 22], $scoreValues, [100.0, 'submitted']));
$insert->execute(array_merge([8, 42, 21], $higherScoreValues, [80.0, 'submitted']));
$complete = e3_final_defense_panel_completion($pdo, 8, 21);
if (!$complete['ready'] || $complete['assigned_count'] !== 2 || $complete['submitted_count'] !== 2
    || $complete['consolidated']['evaluator_count'] !== 2 || $complete['consolidated']['calculated_score'] !== 70.0) {
    throw new RuntimeException('Completed exact Final Defense assignment panel did not produce the expected computed result.');
}
$recommendation = e3_stage_verdict_from_score((float) $complete['consolidated']['calculated_score']);
if (empty($recommendation['fuzzy']['defuzz']) || empty($recommendation['label'])) {
    throw new RuntimeException('Completed Final Defense result did not use the existing Mamdani calculation.');
}

echo "final_defense_result_visibility: ok\n";
