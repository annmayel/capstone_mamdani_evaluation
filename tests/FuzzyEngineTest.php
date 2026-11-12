<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/fuzzy_engine.php';

final class FuzzyEngineTest extends TestCase {
    public function testOfficialVerdictBoundaries(): void {
        $cases = [
            [100, 'PASSED/APPROVED (No Revision)'],
            [95, 'PASSED/APPROVED (No Revision)'],
            [94, 'PASSED/APPROVED (Minor Revision)'],
            [80, 'PASSED/APPROVED (Minor Revision)'],
            [79, 'PASSED/APPROVED (Major Revision)'],
            [60, 'PASSED/APPROVED (Major Revision)'],
            [59, 'REDEFENSE'],
            [50, 'REDEFENSE'],
            [49, 'FAILED'],
            [0, 'FAILED'],
        ];

        foreach ($cases as [$score, $expected]) {
            self::assertSame($expected, fuzzy_rating_label($score), 'Verdict at score ' . $score);
        }
    }

    public function testVerdictClassificationRoundsBeforeApplyingOfficialRanges(): void {
        $cases = [
            [94.49, 94, 'PASSED/APPROVED (Minor Revision)'],
            [94.5, 95, 'PASSED/APPROVED (No Revision)'],
            [94.496, 95, 'PASSED/APPROVED (No Revision)'],
            [79.49, 79, 'PASSED/APPROVED (Major Revision)'],
            [79.5, 80, 'PASSED/APPROVED (Minor Revision)'],
            [59.49, 59, 'REDEFENSE'],
            [59.5, 60, 'PASSED/APPROVED (Major Revision)'],
            [49.49, 49, 'FAILED'],
            [49.5, 50, 'REDEFENSE'],
        ];

        foreach ($cases as [$score, $rounded, $expected]) {
            $verdict = official_verdict_from_score($score);
            self::assertSame($rounded, $verdict['rounded_score']);
            self::assertSame($expected, $verdict['label']);
        }
    }

    public function testOfficialScoreUsesEqualArithmeticMeanOfNormalizedInputs(): void {
        self::assertSame(97.0, calculate_official_average_score(97, 97));
        self::assertSame(88.0, calculate_official_average_score(88, 88));
        self::assertSame(88.0, calculate_official_average_score(80, 96));
        self::assertSame(70.0, calculate_official_average_score(60, 80));
        self::assertSame(55.0, calculate_official_average_score(50, 60));
        self::assertSame(42.0, calculate_official_average_score(40, 44));
        self::assertSame(
            'PASSED/APPROVED (No Revision)',
            official_verdict_from_score(calculate_official_average_score(94, 95))['label']
        );

        $examples = [
            [97, 'PASSED/APPROVED (No Revision)'],
            [88, 'PASSED/APPROVED (Minor Revision)'],
            [70, 'PASSED/APPROVED (Major Revision)'],
            [55, 'REDEFENSE'],
            [42, 'FAILED'],
        ];
        foreach ($examples as [$score, $expected]) {
            self::assertSame($expected, official_verdict_from_score(calculate_official_average_score($score, $score))['label']);
        }
    }

    public function testOfficialVerdictDoesNotUseSeparateRuleAnalysisOutput(): void {
        $trace = build_fuzzy_trace(88, 88);

        self::assertSame(88.0, $trace['final_score']);
        self::assertSame(87.0, $trace['defuzz']);
        self::assertSame('PASSED/APPROVED (Minor Revision)', $trace['rating']);
    }

    public function testVerdictMembershipsUseOnlyOfficialCEITCategories(): void {
        $expectedLabels = array_column(official_verdict_definitions(), 'label');
        $membershipMap = fuzzy_membership_parameters();

        self::assertSame($expectedLabels, array_keys($membershipMap['ProjectQuality']));
        self::assertSame($expectedLabels, array_keys($membershipMap['PaperQuality']));
        self::assertSame($expectedLabels, array_keys($membershipMap['Overall']));
        self::assertSame($membershipMap['ProjectQuality'], $membershipMap['PaperQuality']);
        self::assertSame($membershipMap['ProjectQuality'], $membershipMap['Overall']);

        $cases = [
            [49.49, 'FAILED'],
            [49.5, 'REDEFENSE'],
            [59.49, 'REDEFENSE'],
            [59.5, 'PASSED/APPROVED (Major Revision)'],
            [79.49, 'PASSED/APPROVED (Major Revision)'],
            [79.5, 'PASSED/APPROVED (Minor Revision)'],
            [94.49, 'PASSED/APPROVED (Minor Revision)'],
            [94.5, 'PASSED/APPROVED (No Revision)'],
        ];
        foreach ($cases as [$score, $expectedLabel]) {
            $memberships = [];
            foreach ($membershipMap['ProjectQuality'] as $label => $range) {
                $memberships[$label] = fuzz_membership_verdict_band($score, $label);
            }
            self::assertSame(1.0, $memberships[$expectedLabel]);
            self::assertSame(1.0, array_sum($memberships));
        }
    }

    public function testFixedRulesCoverTheFullValidInputGrid(): void {
        $labels = array_keys(fuzzy_membership_parameters()['ProjectQuality']);
        self::assertCount(25, fuzzy_rule_definitions());
        foreach (fuzzy_rule_definitions() as $rule) {
            self::assertContains($rule['antecedents'][0]['label'], $labels);
            self::assertContains($rule['antecedents'][1]['label'], $labels);
            self::assertContains($rule['consequent']['label'], $labels);
        }

        for ($project = 0; $project <= 100; $project += 0.5) {
            for ($paper = 0; $paper <= 100; $paper += 0.5) {
                $trace = build_fuzzy_trace($project, $paper, new stdClass());
                self::assertCount(25, $trace['evaluatedRules']);
                self::assertCount(1, $trace['firedRules']);
                foreach ($trace['firedRules'] as $rule) {
                    self::assertSame(1.0, $rule['strength']);
                }
                self::assertGreaterThanOrEqual(0, $trace['defuzz']);
                self::assertLessThanOrEqual(100, $trace['defuzz']);
                self::assertSame(fuzzy_rating_label($trace['final_score']), $trace['rating']);
                self::assertSame(official_verdict_from_score($trace['final_score'])['rounded_score'], $trace['rounded_score']);
            }
        }
    }

    public function testRejectsOutOfRangeFuzzyInputs(): void {
        $this->expectException(InvalidArgumentException::class);
        build_fuzzy_trace(100.01, 50);
    }

    public function testRejectsInvalidFinalScores(): void {
        foreach ([-0.01, 100.01, NAN, INF, 'not a score'] as $score) {
            try {
                official_verdict_from_score($score);
                self::fail('Expected invalid official score to be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testRejectsNonnumericFuzzyInputs(): void {
        $this->expectException(InvalidArgumentException::class);
        build_fuzzy_trace('invalid', 50);
    }
}
