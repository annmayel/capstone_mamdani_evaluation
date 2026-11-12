<?php
function official_verdict_definitions() {
    return [
        [
            'minimum' => 95,
            'maximum' => 100,
            'representative_score' => 97.5,
            'label' => 'PASSED/APPROVED (No Revision)',
            'description' => 'Outstanding Proposal.',
            'next_step' => 'The group may proceed to Chapter 4 / Prototype Development and the remaining documentary requirements.',
        ],
        [
            'minimum' => 80,
            'maximum' => 94,
            'representative_score' => 87,
            'label' => 'PASSED/APPROVED (Minor Revision)',
            'description' => 'Successful Proposal.',
            'next_step' => 'The group must comply with the cited minor revisions before proceeding to development.',
        ],
        [
            'minimum' => 60,
            'maximum' => 79,
            'representative_score' => 69.5,
            'label' => 'PASSED/APPROVED (Major Revision)',
            'description' => 'Core Methodological Adjustment.',
            'next_step' => 'Significant corrections are required in the architecture, objectives, or methodology, and the adviser must approve the revisions before actual development begins.',
        ],
        [
            'minimum' => 50,
            'maximum' => 59,
            'representative_score' => 54.5,
            'label' => 'REDEFENSE',
            'description' => 'Subject for Special Schedule of Presentation.',
            'next_step' => 'The group is subject to a special presentation schedule.',
        ],
        [
            'minimum' => 0,
            'maximum' => 49,
            'representative_score' => 24.5,
            'label' => 'FAILED',
            'description' => 'Subject for Course Re-enrollment.',
            'next_step' => 'The group is subject to course re-enrollment, as specified in CEIT-T07B.',
        ],
    ];
}

function official_verdict_from_score($score) {
    if (!is_numeric($score)) {
        throw new InvalidArgumentException('Final score must be a finite number from 0 to 100.');
    }
    $score = (float) $score;
    if (!is_finite($score) || $score < 0 || $score > 100) {
        throw new InvalidArgumentException('Final score must be a finite number from 0 to 100.');
    }

    $score = round($score, 2, PHP_ROUND_HALF_UP);
    $roundedScore = (int) round($score, 0, PHP_ROUND_HALF_UP);
    foreach (official_verdict_definitions() as $verdict) {
        if ($roundedScore >= $verdict['minimum'] && $roundedScore <= $verdict['maximum']) {
            return $verdict + [
                'score' => $score,
                'rounded_score' => $roundedScore,
            ];
        }
    }

    throw new LogicException('No CEIT-T07B verdict is defined for the validated score.');
}

function calculate_official_average_score($projectScore, $paperScore) {
    foreach (['Project Quality' => $projectScore, 'Paper Quality' => $paperScore] as $label => $score) {
        if (!is_numeric($score)) {
            throw new InvalidArgumentException($label . ' score must be a finite number from 0 to 100.');
        }
        $score = (float) $score;
        if (!is_finite($score) || $score < 0 || $score > 100) {
            throw new InvalidArgumentException($label . ' score must be a finite number from 0 to 100.');
        }
    }

    return round(((float) $projectScore + (float) $paperScore) / 2, 2);
}

function fuzzy_rating_label($score) {
    return official_verdict_from_score($score)['label'];
}

function fuzzy_rating_description($score) {
    $verdict = official_verdict_from_score($score);
    return $verdict['description'] . ' ' . $verdict['next_step'];
}

function fuzzy_consolidated_scores(PDO $pdo, int $projectId) {
    if ($projectId <= 0) {
        throw new InvalidArgumentException('Project ID must be a positive integer.');
    }

    $averages = [];
    foreach (['project' => 'evaluations_project', 'paper' => 'evaluations_paper'] as $type => $table) {
        $stmt = $pdo->prepare(
            'SELECT AVG(e.total_score) AS average_score, COUNT(*) AS evaluator_count,
                    COUNT(e.total_score) AS scored_count, MIN(e.total_score) AS minimum_score,
                    MAX(e.total_score) AS maximum_score
             FROM ' . $table . ' e
             INNER JOIN (
                 SELECT evaluator_id, MAX(id) AS latest_id
                 FROM ' . $table . '
                 WHERE project_id = ?
                 GROUP BY evaluator_id
             ) latest ON latest.latest_id = e.id'
        );
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $average = $row['average_score'] ?? null;
        $count = (int) ($row['evaluator_count'] ?? 0);
        if ($count > 0) {
            if ((int) ($row['scored_count'] ?? 0) !== $count) {
                throw new UnexpectedValueException('A saved ' . $type . ' evaluation is missing its total score.');
            }
            if ((float) $row['minimum_score'] < 0 || (float) $row['maximum_score'] > 100) {
                throw new UnexpectedValueException('A saved ' . $type . ' panel score is outside the valid 0-100 range.');
            }
            $average = (float) $average;
            if (!is_finite($average) || $average < 0 || $average > 100) {
                throw new UnexpectedValueException('A saved ' . $type . ' panel score is outside the valid 0-100 range.');
            }
        } else {
            $average = null;
        }
        $averages[$type . '_score'] = $average === null ? null : round($average, 2);
        $averages[$type . '_evaluator_count'] = $count;
    }

    $averages['final_score'] = $averages['project_score'] !== null && $averages['paper_score'] !== null
        ? calculate_official_average_score($averages['project_score'], $averages['paper_score'])
        : null;

    return $averages;
}

function fuzzy_membership_parameters() {
    $categories = [];
    foreach (official_verdict_definitions() as $verdict) {
        $categories[$verdict['label']] = [
            'minimum' => $verdict['minimum'],
            'maximum' => $verdict['maximum'],
            'representative_score' => $verdict['representative_score'],
        ];
    }

    return [
        'ProjectQuality' => $categories,
        'PaperQuality' => $categories,
        'Overall' => $categories,
    ];
}

function get_fuzzy_membership_map($pdo = null) {
    return fuzzy_membership_parameters();
}

function fuzzy_rule_definitions() {
    $categories = fuzzy_membership_parameters()['ProjectQuality'];
    $labels = array_keys($categories);
    $rules = [];
    $id = 1;

    foreach ($labels as $projectLabel) {
        foreach ($labels as $paperLabel) {
            $averageCategoryScore = (
                $categories[$projectLabel]['representative_score']
                + $categories[$paperLabel]['representative_score']
            ) / 2;
            $consequent = official_verdict_from_score($averageCategoryScore)['label'];
            $text = 'IF ProjectQuality IS ' . $projectLabel
                . ' AND PaperQuality IS ' . $paperLabel
                . ' THEN Overall IS ' . $consequent;
            $rules[] = [
                'id' => sprintf('R%02d', $id++),
                'rule_text' => $text,
                'antecedents' => [
                    ['variable' => 'ProjectQuality', 'label' => $projectLabel],
                    ['variable' => 'PaperQuality', 'label' => $paperLabel],
                ],
                'consequent' => ['variable' => 'Overall', 'label' => $consequent],
            ];
        }
    }

    return $rules;
}

function fuzz_membership_verdict_band($score, $verdictLabel) {
    $verdict = official_verdict_from_score($score);
    return $verdict['label'] === $verdictLabel ? 1.0 : 0.0;
}

function build_fuzzy_trace($projectScore, $paperScore, $pdo = null) {
    foreach (['ProjectQuality' => $projectScore, 'PaperQuality' => $paperScore] as $variable => $score) {
        if (!is_numeric($score)) {
            throw new InvalidArgumentException($variable . ' score must be a finite number from 0 to 100.');
        }
        $score = (float) $score;
        if (!is_finite($score) || $score < 0 || $score > 100) {
            throw new InvalidArgumentException($variable . ' score must be a finite number from 0 to 100.');
        }
        if ($variable === 'ProjectQuality') {
            $projectScore = $score;
        } else {
            $paperScore = $score;
        }
    }

    $mfs = get_fuzzy_membership_map();
    $inputs = ['ProjectQuality' => $projectScore, 'PaperQuality' => $paperScore];
    $inputMemberships = [];
    foreach ($inputs as $variable => $value) {
        foreach ($mfs[$variable] as $label => $range) {
            $inputMemberships[$variable][$label] = fuzz_membership_verdict_band($value, $label);
        }
    }

    $evaluatedRules = [];
    $firedRules = [];
    $consequentStrengths = [];
    foreach (fuzzy_rule_definitions() as $rule) {
        $antecedentDegrees = [];
        $antecedents = [];
        foreach ($rule['antecedents'] as $antecedent) {
            $degree = $inputMemberships[$antecedent['variable']][$antecedent['label']];
            $antecedentDegrees[] = $degree;
            $antecedents[] = $antecedent + ['value' => $degree];
        }

        $strength = min($antecedentDegrees);
        $label = $rule['consequent']['label'];
        $ruleTrace = [
            'id' => $rule['id'],
            'rule_text' => $rule['rule_text'],
            'strength' => $strength,
            'consequent' => $rule['consequent'],
            'antecedents' => $antecedents,
        ];
        $evaluatedRules[] = $ruleTrace;
        if ($strength > 0) {
            $firedRules[] = $ruleTrace;
        }
        if ($strength > 0 && (!isset($consequentStrengths[$label]) || $strength > $consequentStrengths[$label])) {
            $consequentStrengths[$label] = $strength;
        }
    }

    $centers = [];
    foreach ($mfs['Overall'] as $label => $range) {
        $centers[$label] = $range['representative_score'];
    }

    $numerator = 0;
    $denominator = 0;
    foreach ($consequentStrengths as $label => $strength) {
        $numerator += $centers[$label] * $strength;
        $denominator += $strength;
    }
    if ($denominator <= 0) {
        throw new RuntimeException('The fixed fuzzy rule set produced no active output for valid scores.');
    }

    $defuzzified = round(max(0, min(100, $numerator / $denominator)), 2);
    arsort($consequentStrengths);

    $finalScore = calculate_official_average_score($projectScore, $paperScore);
    $verdict = official_verdict_from_score($finalScore);

    return [
        'combined' => $finalScore,
        'final_score' => $finalScore,
        'rounded_score' => $verdict['rounded_score'],
        'verdict' => $verdict,
        'defuzz' => $defuzzified,
        'rating' => $verdict['label'],
        'rating_description' => $verdict['description'],
        'next_step' => $verdict['next_step'],
        'inputMemberships' => $inputMemberships,
        'evaluatedRules' => $evaluatedRules,
        'firedRules' => $firedRules,
        'outputMemberships' => $consequentStrengths,
        'centers' => $centers,
    ];
}

function evaluate_fuzzy_overall($projectScore, $paperScore, $pdo = null) {
    $trace = build_fuzzy_trace($projectScore, $paperScore);
    return [
        'combined' => $trace['combined'],
        'final_score' => $trace['final_score'],
        'rounded_score' => $trace['rounded_score'],
        'defuzz' => $trace['defuzz'],
        'rating' => $trace['rating'],
        'rating_description' => $trace['rating_description'],
        'next_step' => $trace['next_step'],
        'degrees' => $trace['outputMemberships'],
    ];
}

function evaluate_fuzzy_rules($projectScore, $paperScore, $pdo = null) {
    $trace = build_fuzzy_trace($projectScore, $paperScore);
    return [
        'combined' => $trace['combined'],
        'final_score' => $trace['final_score'],
        'rounded_score' => $trace['rounded_score'],
        'defuzz' => $trace['defuzz'],
        'rating' => $trace['rating'],
        'rating_description' => $trace['rating_description'],
        'next_step' => $trace['next_step'],
        'degrees' => $trace['outputMemberships'],
    ];
}
