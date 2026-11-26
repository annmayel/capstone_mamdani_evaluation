<?php

$root = dirname(__DIR__);
$projects = (string) file_get_contents($root . '/evaluator/projects.php');
$selection = (string) file_get_contents($root . '/evaluator/evaluate_selection.php');
$e3 = (string) file_get_contents($root . '/evaluator/evaluate_e3.php');
$submittedE3View = (string) file_get_contents($root . '/evaluator/view_e3_evaluation.php');

if (!str_contains($projects, 'href="evaluate_selection.php?project_id=')) {
    throw new RuntimeException('Workspace actions must open the evaluation-type selector.');
}
if (str_contains($projects, 'href="evaluate_project.php?project_id=')) {
    throw new RuntimeException('Workspace must not bypass the evaluation-type selector for submitted proposals.');
}
if (!str_contains($selection, "'view_e3_evaluation.php' : 'evaluate_e3.php'")) {
    throw new RuntimeException('The Final Hearing card must use its submitted-evaluation view route.');
}
if (!str_contains($submittedE3View, '$e3SubmittedView = true') || !str_contains($submittedE3View, "require __DIR__ . '/evaluate_e3.php'")) {
    throw new RuntimeException('Submitted E3 view must reuse the protected E3 renderer.');
}
if (!str_contains($e3, "if(\$submittedViewRequested&&(!\$existing||\$existing['status']!=='submitted'))")) {
    throw new RuntimeException('Submitted E3 view must reject missing or unsubmitted evaluation records.');
}

echo "evaluator_evaluation_routing: ok\n";
