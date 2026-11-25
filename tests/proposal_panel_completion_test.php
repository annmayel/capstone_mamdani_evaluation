<?php
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (count(proposal_criteria()) !== 11 || array_sum(array_column(proposal_criteria(), 'maximum')) !== 100) {
    throw new RuntimeException('The CEIT-T07A rubric must retain 11 criteria totaling 100 points.');
}
$criteriaColumns = implode(', ', array_map(
    static fn(array $criterion): string => $criterion['key'] . ' REAL',
    proposal_criteria()
));
$pdo->exec('CREATE TABLE evaluator_assignments (project_id INTEGER, evaluator_id INTEGER)');
$pdo->exec('CREATE TABLE proposal_evaluations (id INTEGER PRIMARY KEY, project_id INTEGER, proposal_submission_id INTEGER, evaluator_id INTEGER, status TEXT, total_score REAL, ' . $criteriaColumns . ')');
$pdo->exec('INSERT INTO evaluator_assignments (project_id, evaluator_id) VALUES (5, 10), (5, 11)');

$keys = array_map(static fn(array $criterion): string => $criterion['key'], proposal_criteria());
$columns = implode(', ', array_merge(['project_id', 'proposal_submission_id', 'evaluator_id', 'status', 'total_score'], $keys));
$placeholders = implode(', ', array_fill(0, count($keys) + 5, '?'));
$insert = $pdo->prepare("INSERT INTO proposal_evaluations ($columns) VALUES ($placeholders)");
$insert->execute(array_merge([5, 14, 10, 'submitted', 80], array_fill(0, count($keys), 1)));

$pending = proposal_panel_completion($pdo, 5, 14);
if ($pending['ready'] || $pending['submitted_count'] !== 1 || $pending['assigned_count'] !== 2) {
    throw new RuntimeException('Incomplete assigned panel must remain pending.');
}

$insert->execute(array_merge([5, 14, 11, 'submitted', 90], array_fill(0, count($keys), 2)));
$complete = proposal_panel_completion($pdo, 5, 14);
if (!$complete['ready'] || $complete['submitted_count'] !== 2 || $complete['consolidated']['evaluator_count'] !== 2) {
    throw new RuntimeException('Complete assigned panel was not recognized correctly.');
}

echo "proposal_panel_completion: ok\n";
