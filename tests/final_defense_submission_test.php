<?php

require_once __DIR__ . '/../php/submissions.php';

$acceptedProposal = ['id' => 15];
$panelAcceptedRevision = [
    'proposal_confirmed_at' => '2026-11-26 09:00:00',
    'proposal_official_verdict' => 'PASSED/APPROVED (Major Revision)',
    'proposal_required_revisions' => 'Address the panel feedback.',
    'proposal_revision_accepted_submission_id' => 15,
];
if (!proposal_allows_final_submission($panelAcceptedRevision, $acceptedProposal)) {
    throw new RuntimeException('Final Defense must become available immediately after the original panel accepts the revision.');
}

$revisionStillPending = $panelAcceptedRevision;
$revisionStillPending['proposal_revision_accepted_submission_id'] = null;
if (proposal_allows_final_submission($revisionStillPending, $acceptedProposal)) {
    throw new RuntimeException('Final Defense must remain locked until every original reviewer accepts the revision.');
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
    [$studentPortal, '$proposalStageCompleted'],
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
