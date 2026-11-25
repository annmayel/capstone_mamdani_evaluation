<?php
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$criteria = proposal_criteria();
$criteriaColumns = implode(', ', array_map(
    static fn(array $criterion): string => $criterion['key'] . ' REAL',
    $criteria
));
$pdo->exec('CREATE TABLE evaluator_assignments (project_id INTEGER, evaluator_id INTEGER)');
$pdo->exec('CREATE TABLE student_submissions (id INTEGER PRIMARY KEY, project_id INTEGER, stage TEXT, stored_filename TEXT)');
$pdo->exec('CREATE TABLE proposal_evaluations (id INTEGER PRIMARY KEY, project_id INTEGER, proposal_submission_id INTEGER, evaluator_id INTEGER, status TEXT, submitted_at TEXT, ' . $criteriaColumns . ')');
$pdo->exec("INSERT INTO evaluator_assignments VALUES (5, 10), (5, 11)");
$pdo->exec("INSERT INTO student_submissions VALUES (14, 5, 'proposal', 'scored-proposal.pdf'), (15, 5, 'proposal', 'newer-revision.pdf')");

$scoreColumns = array_column($criteria, 'key');
$columns = implode(', ', array_merge(['project_id', 'proposal_submission_id', 'evaluator_id', 'status', 'submitted_at'], $scoreColumns));
$placeholders = implode(', ', array_fill(0, count($scoreColumns) + 5, '?'));
$insert = $pdo->prepare("INSERT INTO proposal_evaluations ($columns) VALUES ($placeholders)");
$insert->execute(array_merge([5, 14, 10, 'submitted', '2026-11-26 10:00:00'], array_fill(0, count($scoreColumns), 8)));
$insert->execute(array_merge([5, 14, 11, 'submitted', '2026-11-26 10:01:00'], array_fill(0, count($scoreColumns), 9)));

$stmt = $pdo->prepare(
    "SELECT pe.*, s.id AS saved_submission_id, s.stored_filename
     FROM proposal_evaluations pe
     INNER JOIN student_submissions s
       ON s.id = pe.proposal_submission_id AND s.project_id = pe.project_id AND s.stage = 'proposal'
     WHERE pe.project_id = ? AND pe.evaluator_id = ? AND pe.status = 'submitted'
     ORDER BY pe.submitted_at DESC, pe.id DESC"
);
$stmt->execute([5, 10]);
$evaluation = $stmt->fetch(PDO::FETCH_ASSOC);
$complete = $evaluation && count(array_filter(
    $scoreColumns,
    static fn(string $column): bool => array_key_exists($column, $evaluation) && $evaluation[$column] !== null
)) === count($scoreColumns);
if (!$complete || (int) $evaluation['saved_submission_id'] !== 14) {
    throw new RuntimeException('Workspace did not match the evaluator\'s submitted proposal sheet.');
}
$panel = proposal_panel_completion($pdo, 5, 14);
if (!$panel['ready']) {
    throw new RuntimeException('Completed assigned panel was not identified.');
}

echo "evaluator_workspace_submission_match: ok\n";
