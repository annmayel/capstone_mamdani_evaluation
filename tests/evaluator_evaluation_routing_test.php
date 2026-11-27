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
if (!str_contains($selection, 'ORDER BY pe.submitted_at DESC, pe.id DESC')
    || !str_contains($selection, '$proposalHistorical = true')
    || !str_contains($selection, 'View Historical Evaluation')) {
    throw new RuntimeException('Completed proposal evaluations must remain historical after a later revision upload.');
}
if (!str_contains($selection, "'Not Assigned'")) {
    throw new RuntimeException('Final Hearing-only evaluators must not receive a proposal evaluation task.');
}
if (!str_contains($selection, '$currentProposalSubmission = latest_stored_pdf_stage_submission')
    || !str_contains($selection, 'proposal_allows_final_submission($project, $currentProposalSubmission)')) {
    throw new RuntimeException('Final Hearing eligibility must use the current accepted proposal submission, not the historical score-sheet submission.');
}
if (!str_contains($projects, "? 'assigned' : 'not_assigned'")) {
    throw new RuntimeException('Workspace must not label final-defense-only assignments as proposal assignments.');
}
if (!str_contains($submittedE3View, '$e3SubmittedView = true') || !str_contains($submittedE3View, "require __DIR__ . '/evaluate_e3.php'")) {
    throw new RuntimeException('Submitted E3 view must reuse the protected E3 renderer.');
}
if (!str_contains($e3, "if(\$submittedViewRequested&&(!\$existing||\$existing['status']!=='submitted'))")) {
    throw new RuntimeException('Submitted E3 view must reject missing or unsubmitted evaluation records.');
}

echo "evaluator_evaluation_routing: ok\n";
