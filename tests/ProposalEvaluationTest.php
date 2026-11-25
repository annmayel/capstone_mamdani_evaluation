<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';
require_once __DIR__ . '/../php/submissions.php';

final class ProposalEvaluationTest extends TestCase
{
    public function testCompleteProposalScoresProduceExpectedTotal(): void
    {
        $input = array_fill_keys(array_column(proposal_criteria(), 'key'), '0');
        $input['pfo1_1'] = '10'; $input['pfo1_2'] = '10'; $input['pfo1_3'] = '10';
        $input['lts2_1'] = '10'; $input['lts2_2'] = '5'; $input['lts2_3'] = '5';
        $input['msa3_1'] = '10'; $input['msa3_2'] = '20'; $input['msa3_3'] = '10';
        $input['ppr4_1'] = '5'; $input['ppr4_2'] = '5';

        $validation = proposal_validate_scores($input, true);

        self::assertSame([], $validation['errors']);
        self::assertTrue($validation['complete']);
        self::assertSame(100.0, $validation['total']);
        self::assertSame(100.0, $validation['percentage']);
    }

    public function testProposalScoresRejectMissingAndOutOfRangeValues(): void
    {
        $input = [
            'pfo1_1' => '11',
            'pfo1_2' => '-1',
            'pfo1_3' => '10.001',
            'lts2_1' => '',
        ];

        $validation = proposal_validate_scores($input, true);

        self::assertFalse($validation['complete']);
        self::assertNotEmpty($validation['errors']);
        self::assertCount(11, $validation['errors']);
    }

    public function testDraftScoresMayBeIncompleteWithoutBecomingSubmittable(): void
    {
        $validation = proposal_validate_scores([
            'pfo1_1' => '8',
            'pfo1_2' => '',
            'pfo1_3' => '7.5',
        ], false);

        self::assertSame([], $validation['errors']);
        self::assertFalse($validation['complete']);
        self::assertSame(15.5, $validation['total']);
        self::assertSame(15.5, $validation['percentage']);
    }

    public function testProposalVerdictBandsMatchOfficialCeitRanges(): void
    {
        $cases = [
            [100, 'PASSED/APPROVED (No Revision)'], [95, 'PASSED/APPROVED (No Revision)'],
            [94, 'PASSED/APPROVED (Minor Revision)'], [80, 'PASSED/APPROVED (Minor Revision)'],
            [79, 'PASSED/APPROVED (Major Revision)'], [60, 'PASSED/APPROVED (Major Revision)'],
            [59, 'REDEFENSE'], [50, 'REDEFENSE'], [49, 'FAILED'], [0, 'FAILED'],
        ];

        foreach ($cases as [$score, $expected]) {
            self::assertSame($expected, proposal_verdict_from_score($score)['label']);
        }
    }

    public function testOfficialRubricContainsElevenCriteriaWorthOneHundredPoints(): void
    {
        $criteria = proposal_criteria();
        self::assertCount(11, $criteria);
        self::assertSame(100, array_sum(array_column($criteria, 'maximum')));
    }

    public function testFinalPaperUnlockRequiresOfficialApprovalAndAcceptedRevisions(): void
    {
        $proposal = ['id' => 12];
        $approved = ['proposal_confirmed_at' => '2026-11-25 10:00:00', 'proposal_official_verdict' => 'PASSED/APPROVED (No Revision)'];
        $approvedWithCorrections = [
            'proposal_confirmed_at' => '2026-11-25 10:00:00',
            'proposal_official_verdict' => 'PASSED/APPROVED (No Revision)',
            'proposal_required_revisions' => 'Correct the abstract.',
        ];
        $minorRevision = [
            'proposal_confirmed_at' => '2026-11-25 10:00:00',
            'proposal_official_verdict' => 'PASSED/APPROVED (Minor Revision)',
            'proposal_revision_accepted_submission_id' => 12,
        ];
        $notApproved = [
            'proposal_confirmed_at' => '2026-11-25 10:00:00',
            'proposal_official_verdict' => 'FAILED',
            'proposal_revision_accepted_submission_id' => 12,
        ];

        self::assertTrue(proposal_allows_final_submission($approved, $proposal));
        self::assertFalse(proposal_allows_final_submission($approvedWithCorrections, $proposal));
        $approvedWithCorrections['proposal_revision_accepted_submission_id'] = 12;
        self::assertTrue(proposal_allows_final_submission($approvedWithCorrections, $proposal));
        self::assertTrue(proposal_allows_final_submission($minorRevision, $proposal));
        self::assertFalse(proposal_allows_final_submission($minorRevision, ['id' => 13]));
        self::assertFalse(proposal_allows_final_submission($notApproved, $proposal));
        self::assertFalse(proposal_allows_final_submission([], $proposal));
    }
}
