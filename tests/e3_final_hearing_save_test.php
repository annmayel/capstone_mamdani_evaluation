<?php

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/e3.php';

$e3Page = (string) file_get_contents(__DIR__ . '/../evaluator/evaluate_e3.php');
if (!str_contains($e3Page, 'e3_evaluation_history_supports_e3($pdo)')
    || !str_contains($e3Page, 'e3_submit_evaluation($pdo,$projectId,$evaluatorId,(int)$finalSubmission[\'id\']')) {
    throw new RuntimeException('The Final Hearing page must preflight the E3 history schema and use the atomic submission path.');
}
if (e3_evaluation_history_type_supports_e3("enum('project','paper','overall')")
    || !e3_evaluation_history_type_supports_e3("enum('project','paper','overall','e3')")) {
    throw new RuntimeException('E3 history schema preflight did not identify the required migration 022 enum change.');
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$scoreColumns = implode(', ', array_map(
    static fn(array $criterion): string => $criterion['key'] . ' REAL NOT NULL',
    e3_criteria()
));
$pdo->exec("CREATE TABLE e3_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    evaluator_id INTEGER NOT NULL,
    final_submission_id INTEGER NOT NULL,
    $scoreColumns,
    total_score REAL NOT NULL,
    remarks TEXT NULL,
    revisions TEXT NULL,
    status TEXT NOT NULL,
    submitted_at TEXT NULL,
    UNIQUE(project_id, evaluator_id, final_submission_id)
)");
$pdo->exec("CREATE TABLE final_defense_evaluator_assignments (
    project_id INTEGER NOT NULL,
    final_submission_id INTEGER NOT NULL,
    evaluator_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    UNIQUE(final_submission_id, evaluator_id)
)");
$pdo->exec("CREATE TABLE evaluation_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    evaluator_id INTEGER NOT NULL,
    evaluation_type TEXT NOT NULL CHECK(evaluation_type IN ('project','paper','overall')),
    project_score REAL,
    paper_score REAL,
    overall_score REAL,
    overall_rating TEXT
)");
$pdo->exec("INSERT INTO final_defense_evaluator_assignments VALUES (8, 21, 42, 'assigned')");

$scores = [];
foreach (e3_criteria() as $criterion) {
    $scores[$criterion['key']] = (float) $criterion['maximum'];
}
$total = array_sum($scores);

// This reproduces the Railway failure: E3 insert succeeds, but the legacy
// history enum rejects 'e3', so the whole transaction must roll back.
try {
    e3_submit_evaluation($pdo, 8, 42, 21, $scores, $total, 'Ready', 'Approved', 'Passed');
    throw new RuntimeException('Legacy E3 history schema unexpectedly accepted an E3 submission.');
} catch (PDOException $expected) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM e3_evaluations')->fetchColumn() !== 0
        || (string) $pdo->query('SELECT status FROM final_defense_evaluator_assignments')->fetchColumn() !== 'assigned') {
        throw new RuntimeException('A failed E3 history insert must roll back the exact submission and assignment update.');
    }
}

$pdo->exec('DROP TABLE evaluation_history');
$pdo->exec("CREATE TABLE evaluation_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL,
    evaluator_id INTEGER NOT NULL,
    evaluation_type TEXT NOT NULL CHECK(evaluation_type IN ('project','paper','overall','e3')),
    project_score REAL,
    paper_score REAL,
    overall_score REAL,
    overall_rating TEXT
)");

$evaluationId = e3_submit_evaluation($pdo, 8, 42, 21, $scores, $total, 'Ready', 'Approved', 'Passed');
$saved = $pdo->query('SELECT project_id, evaluator_id, final_submission_id, status FROM e3_evaluations WHERE id = ' . (int) $evaluationId)->fetch(PDO::FETCH_ASSOC);
if (!$saved || $saved['project_id'] != 8 || $saved['evaluator_id'] != 42 || $saved['final_submission_id'] != 21 || $saved['status'] !== 'submitted') {
    throw new RuntimeException('E3 save did not retain the exact project, evaluator, and Final Defense submission IDs.');
}
if ((string) $pdo->query('SELECT status FROM final_defense_evaluator_assignments')->fetchColumn() !== 'completed'
    || (string) $pdo->query('SELECT evaluation_type FROM evaluation_history')->fetchColumn() !== 'e3') {
    throw new RuntimeException('Successful E3 submission did not complete the exact assignment and history record.');
}
$panel = e3_consolidated($pdo, 8, 21);
if ($panel['evaluator_count'] !== 1 || $panel['calculated_score'] !== 100.0) {
    throw new RuntimeException('E3 panel completion did not calculate from the exact final submission.');
}

try {
    e3_submit_evaluation($pdo, 8, 42, 21, $scores, $total, null, null, 'Passed');
    throw new RuntimeException('Duplicate submitted E3 evaluation was not prevented.');
} catch (LogicException $expected) {
}

try {
    e3_submit_evaluation($pdo, 8, 99, 21, $scores, $total, null, null, 'Passed');
    throw new RuntimeException('Unauthorized evaluator E3 save was not prevented.');
} catch (RuntimeException $expected) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM e3_evaluations WHERE evaluator_id = 99')->fetchColumn() !== 0) {
        throw new RuntimeException('Unauthorized evaluator E3 record was not rolled back.');
    }
}

echo "e3_final_hearing_save: ok\n";
