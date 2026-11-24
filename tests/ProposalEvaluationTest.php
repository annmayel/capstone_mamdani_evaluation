<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/proposal_evaluation.php';

final class ProposalEvaluationTest extends TestCase
{
    public function testCompleteProposalScoresProduceExpectedTotal(): void
    {
        $input = [
            'proposal_background' => '18.50',
            'proposal_literature' => '17',
            'proposal_methodology' => '16.25',
            'proposal_design' => '18',
            'proposal_presentation' => '19',
            'proposal_question_answer' => '15.75',
        ];

        $validation = proposal_validate_scores($input, true);

        self::assertSame([], $validation['errors']);
        self::assertTrue($validation['complete']);
        self::assertSame(104.5, $validation['total']);
        self::assertSame(87.08, $validation['percentage']);
    }

    public function testProposalScoresRejectMissingAndOutOfRangeValues(): void
    {
        $input = [
            'proposal_background' => '21',
            'proposal_literature' => '-1',
            'proposal_methodology' => '16.251',
            'proposal_design' => '',
        ];

        $validation = proposal_validate_scores($input, true);

        self::assertFalse($validation['complete']);
        self::assertNotEmpty($validation['errors']);
        self::assertCount(6, $validation['errors']);
    }

    public function testDraftScoresMayBeIncompleteWithoutBecomingSubmittable(): void
    {
        $validation = proposal_validate_scores([
            'proposal_background' => '18',
            'proposal_literature' => '',
            'proposal_methodology' => '17.5',
        ], false);

        self::assertSame([], $validation['errors']);
        self::assertFalse($validation['complete']);
        self::assertSame(35.5, $validation['total']);
        self::assertSame(29.58, $validation['percentage']);
    }

    public function testProposalVerdictBandsMatchOfficialCeitRanges(): void
    {
        $cases = [
            [100, 'Approved'],
            [95, 'Approved'],
            [94.49, 'Approved with Minor Revision'],
            [94.5, 'Approved'],
            [80, 'Approved with Minor Revision'],
            [79, 'Approved with Major Revision'],
            [79.5, 'Approved with Minor Revision'],
            [60, 'Approved with Major Revision'],
            [59, 'Not Approved'],
            [0, 'Not Approved'],
        ];

        foreach ($cases as [$score, $expected]) {
            self::assertSame($expected, proposal_verdict_from_score($score)['label']);
        }
    }
}
