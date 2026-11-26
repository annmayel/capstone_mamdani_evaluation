<?php

require_once __DIR__ . '/../php/submissions.php';

$acceptedProposal = ['id' => 15];
$chairOpenedRevision = [
    'proposal_confirmed_at' => '2026-11-26 09:00:00',
    'proposal_official_verdict' => 'PASSED/APPROVED (Major Revision)',
    'proposal_required_revisions' => 'Address the panel feedback.',
    'proposal_revision_accepted_submission_id' => 15,
];
if (!proposal_allows_final_submission($chairOpenedRevision, $acceptedProposal)) {
    throw new RuntimeException('Final Defense must become available only after the Chairperson accepts the accepted revision.');
}

$panelAcceptedOnly = $chairOpenedRevision;
$panelAcceptedOnly['proposal_revision_accepted_submission_id'] = null;
if (proposal_allows_final_submission($panelAcceptedOnly, $acceptedProposal)) {
    throw new RuntimeException('Panel acceptance alone must not unlock Final Defense submission.');
}

$root = dirname(__DIR__);
$submissionEndpoint = (string) file_get_contents($root . '/students/submit_proposal.php');
$studentPortal = (string) file_get_contents($root . '/students/proposal.php');
$chairView = (string) file_get_contents($root . '/dean/submissions.php');
$assignmentView = (string) file_get_contents($root . '/dean/assign_evaluators.php');
$evaluatorSelection = (string) file_get_contents($root . '/evaluator/evaluate_selection.php');
$evaluatorEvaluation = (string) file_get_contents($root . '/evaluator/evaluate_e3.php');
$verdictView = (string) file_get_contents($root . '/dean/e3_verdict.php');
$assignmentMigration = (string) file_get_contents($root . '/sql/migrations/024_final_defense_evaluator_assignments.sql');

foreach ([
    [$submissionEndpoint, 'project_resource_url'],
    [$submissionEndpoint, "\$stage === 'final'"],
    [$studentPortal, 'Final Defense Submission (Final Paper &amp; Project)'],
    [$studentPortal, 'Registered Project Title'],
    [$studentPortal, 'Evaluators Assigned'],
    [$studentPortal, 'final-defense.css'],
    [$chairView, 'Review Final Defense'],
    [$assignmentView, 'final_submission_id'],
    [$assignmentView, 'Assign Final Hearing Evaluators'],
    [$evaluatorSelection, 'final_defense_evaluator_is_assigned'],
    [$evaluatorEvaluation, 'final_defense_evaluator_is_assigned'],
    [$verdictView, 'final_defense_assigned_evaluator_count'],
    [$assignmentMigration, 'final_defense_evaluator_assignments'],
] as [$source, $required]) {
    if (!str_contains($source, $required)) {
        throw new RuntimeException('Final Defense workflow marker missing: ' . $required);
    }
}

echo "final_defense_submission: ok\n";
