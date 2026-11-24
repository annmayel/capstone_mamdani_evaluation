<?php

function e3_criteria(): array
{
    return [
        'FR1.1' => ['key' => 'fr1_1', 'category' => 'I. Functionality and Results', 'label' => 'Architecture and Requirements', 'description' => 'System architecture aligns with requirements and defined objectives.', 'maximum' => 5],
        'FR1.2' => ['key' => 'fr1_2', 'category' => 'I. Functionality and Results', 'label' => 'Implementation', 'description' => 'Core functions are complete, accurate, and reliably implemented.', 'maximum' => 20],
        'FR1.3' => ['key' => 'fr1_3', 'category' => 'I. Functionality and Results', 'label' => 'Testing and Objective Verification', 'description' => 'Tests demonstrate that objectives and system requirements are met.', 'maximum' => 20],
        'FR1.4' => ['key' => 'fr1_4', 'category' => 'I. Functionality and Results', 'label' => 'Focused Verification', 'description' => 'Critical features and edge cases receive focused verification.', 'maximum' => 5],
        'ARC2.1' => ['key' => 'arc2_1', 'category' => 'II. Academic Rigour and Conclusion', 'label' => 'Background and Methodology', 'description' => 'Background, research basis, and methodology are rigorous and coherent.', 'maximum' => 5],
        'ARC2.2' => ['key' => 'arc2_2', 'category' => 'II. Academic Rigour and Conclusion', 'label' => 'Data Analysis and Synthesis', 'description' => 'Results are analyzed and synthesized using appropriate evidence.', 'maximum' => 10],
        'ARC2.3' => ['key' => 'arc2_3', 'category' => 'II. Academic Rigour and Conclusion', 'label' => 'Conclusion and Recommendation', 'description' => 'Conclusions are supported and recommendations are actionable.', 'maximum' => 5],
        'DP3.1' => ['key' => 'dp3_1', 'category' => 'III. Defence and Presentation', 'label' => 'Presentation Quality', 'description' => 'Presentation is organized, clear, professional, and evidence-based.', 'maximum' => 5],
        'DP3.2' => ['key' => 'dp3_2', 'category' => 'III. Defence and Presentation', 'label' => 'Question and Answer, and Technical Defence', 'description' => 'Responses demonstrate mastery of the system and technical decisions.', 'maximum' => 5],
        'PMD4.1' => ['key' => 'pmd4_1', 'category' => 'IV. Printed Manuscript and Documentation Quality', 'label' => 'Chapters 1 to 3', 'description' => 'Introduction, review, and methodology are complete and consistent.', 'maximum' => 5],
        'PMD4.2' => ['key' => 'pmd4_2', 'category' => 'IV. Printed Manuscript and Documentation Quality', 'label' => 'Chapters 4 and 5', 'description' => 'Results, findings, conclusions, and recommendations are well documented.', 'maximum' => 10],
        'PMD4.3' => ['key' => 'pmd4_3', 'category' => 'IV. Printed Manuscript and Documentation Quality', 'label' => 'Formatting, Appendices and Forms', 'description' => 'Document formatting, appendices, citations, and forms follow standards.', 'maximum' => 5],
    ];
}

function e3_verdict(float $score): array
{
    if (!is_finite($score) || $score < 0 || $score > 100) {
        throw new InvalidArgumentException('E3 final score must be between 0 and 100.');
    }
    $score = round($score, 2);
    if ($score >= 95) {
        return [
            'label' => 'PASSED / APPROVED (No Revision)',
            'range' => '95-100',
            'description' => 'No required correction.',
            'action' => 'File E5 marked NIL and complete remaining documentary and clearance requirements.',
        ];
    }
    if ($score >= 80) {
        return [
            'label' => 'PASSED / APPROVED (Minor Revision)',
            'range' => '80-below 95',
            'description' => 'Typographical, formatting, or minimal documentation adjustments before documents are routed.',
            'action' => 'Complete the required minor revisions before documents are routed.',
        ];
    }
    if ($score >= 60) {
        return [
            'label' => 'PASSED / APPROVED (Major Revision)',
            'range' => '60-below 80',
            'description' => 'Significant correction to objectives, testing, data analysis, or major prototype fixes before documents are routed.',
            'action' => 'Certified on CEIT-UR-E5.',
        ];
    }
    if ($score >= 50) {
        return [
            'label' => 'REDEFENSE',
            'range' => '50-below 60',
            'description' => 'Renewed oral or technical assessment required.',
            'action' => 'Correct verified project or evidence defects, obtain a fresh CEIT-UR-E2, and repeat the hearing.',
        ];
    }
    return [
        'label' => 'FAILED',
        'range' => 'Below 50',
        'description' => 'Required academic criteria not met.',
        'action' => 'Apply review, second-defense, and course rules before re-enrolment/new topic. Misconduct is determined separately.',
    ];
}

function e3_validate_scores(array $input): array
{
    $scores = [];
    $errors = [];
    foreach (e3_criteria() as $code => $criterion) {
        $key = $criterion['key'];
        $raw = $input[$key] ?? null;
        if (!is_string($raw) || $raw === '' || !preg_match('/^(?:\d+(?:\.\d{1,2})?)$/', trim($raw))) {
            $errors[] = $code . ' ' . $criterion['label'] . ' is required and must be numeric.';
            continue;
        }
        $value = (float) trim($raw);
        if (!is_finite($value) || $value < 0 || $value > (float) $criterion['maximum']) {
            $errors[] = $code . ' must be between 0 and ' . $criterion['maximum'] . '.';
            continue;
        }
        $scores[$key] = round($value, 2);
    }
    $total = array_sum($scores);
    if (count($scores) === 12 && (!is_finite($total) || $total > 100)) {
        $errors[] = 'The total score cannot exceed 100.';
    }
    return ['scores' => $scores, 'total' => round($total, 2), 'errors' => $errors];
}

function e3_consolidated(PDO $pdo, int $projectId): array
{
    $criteria = e3_criteria();
    $consolidated = [];
    $stmt = $pdo->prepare('SELECT * FROM e3_evaluations WHERE project_id = ? AND status = ? ORDER BY id');
    $stmt->execute([$projectId, 'submitted']);
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $evaluatorCount = count($evaluations);
    foreach ($criteria as $code => $criterion) {
        $key = $criterion['key'];
        $sum = 0.0;
        foreach ($evaluations as $evaluation) {
            $sum += (float) $evaluation[$key];
        }
        $consolidated[$code] = $evaluatorCount > 0 ? round($sum / $evaluatorCount, 2) : null;
    }
    $calculated = array_sum(array_map(static fn($value) => (float) ($value ?? 0), $consolidated));
    $calculated = $evaluatorCount > 0 ? round($calculated, 2) : null;
    return [
        'evaluations' => $evaluations,
        'criteria' => $consolidated,
        'calculated_score' => $calculated,
        'evaluator_count' => count($evaluations),
    ];
}
