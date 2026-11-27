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

function e3_stage_verdict_from_score(float $score): array
{
    if (!function_exists('build_fuzzy_trace')) {
        throw new LogicException('The Mamdani fuzzy engine must be loaded before calculating a final-hearing verdict.');
    }

    $trace = build_fuzzy_trace($score, $score);
    $label = (string) $trace['rating'];
    if (strpos($label, 'No Revision') !== false) {
        $stageLabel = 'Passed';
        $description = 'The final hearing meets the required standard without corrections.';
    } elseif (strpos($label, 'Minor Revision') !== false) {
        $stageLabel = 'Passed with Minor Corrections';
        $description = 'Minor corrections are required before the final paper is accepted.';
    } elseif (strpos($label, 'Major Revision') !== false) {
        $stageLabel = 'Passed with Major Revisions';
        $description = 'Major revisions are required before the final paper is accepted.';
    } else {
        $stageLabel = 'Not Passed';
        $description = 'The final hearing requirements have not been met.';
    }

    return [
        'label' => $stageLabel,
        'description' => $description,
        'score' => round($score, 2),
        'fuzzy' => $trace,
    ];
}

function e3_validate_scores(array $input, bool $requireComplete = true): array
{
    $scores = [];
    $errors = [];
    foreach (e3_criteria() as $code => $criterion) {
        $key = $criterion['key'];
        $raw = $input[$key] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            if ($requireComplete) {
                $errors[] = $code . ' ' . $criterion['label'] . ' is required and must be numeric.';
            }
            $scores[$key] = null;
            continue;
        }
        if (!preg_match('/^(?:\d+(?:\.\d{1,2})?)$/', trim($raw))) {
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
    $total = array_sum(array_map(static fn($score): float => (float) ($score ?? 0), $scores));
    if (count(array_filter($scores, static fn($score): bool => $score !== null)) === count(e3_criteria()) && (!is_finite($total) || $total > 100)) {
        $errors[] = 'The total score cannot exceed 100.';
    }
    return ['scores' => $scores, 'total' => round($total, 2), 'errors' => $errors];
}

/**
 * E3 submissions also create a history row. Older databases used an enum that
 * predates the E3 workflow, so detect that incompatibility before starting the
 * final-hearing save transaction. Migration 022 is the non-destructive repair.
 */
function e3_evaluation_history_supports_e3(PDO $pdo): bool
{
    $column = $pdo->query("SHOW COLUMNS FROM `evaluation_history` LIKE 'evaluation_type'")
        ->fetch(PDO::FETCH_ASSOC);

    return e3_evaluation_history_type_supports_e3((string) ($column['Type'] ?? ''));
}

function e3_evaluation_history_type_supports_e3(string $columnType): bool
{
    return preg_match("/(?:^|,)'e3'(?:,|\))/", strtolower($columnType)) === 1;
}

/**
 * Save one evaluator's submitted Final Hearing sheet and its matching history
 * record atomically. The assignment update includes the exact final submission
 * ID, so a project-level proposal assignment can never authorize an E3 save.
 *
 * @return int The created or updated E3 evaluation ID.
 */
function e3_submit_evaluation(
    PDO $pdo,
    int $projectId,
    int $evaluatorId,
    int $finalSubmissionId,
    array $scores,
    float $total,
    ?string $remarks,
    ?string $revisions,
    string $historyRating
): int {
    $criteria = e3_criteria();
    foreach ($criteria as $criterion) {
        if (!array_key_exists($criterion['key'], $scores) || $scores[$criterion['key']] === null) {
            throw new InvalidArgumentException('Every E3 criterion must be scored before submission.');
        }
    }

    $pdo->beginTransaction();
    try {
        $existingStmt = $pdo->prepare(
            'SELECT id, status FROM e3_evaluations
             WHERE project_id=? AND evaluator_id=? AND final_submission_id=?
             ORDER BY id DESC LIMIT 1'
        );
        $existingStmt->execute([$projectId, $evaluatorId, $finalSubmissionId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing && $existing['status'] === 'submitted') {
            throw new LogicException('This E3 evaluation has already been submitted and cannot be edited.');
        }

        $values = [];
        foreach ($criteria as $criterion) {
            $values[] = $scores[$criterion['key']];
        }
        $submittedAt = date('Y-m-d H:i:s');
        if ($existing) {
            $update = $pdo->prepare(
                'UPDATE e3_evaluations SET
                 fr1_1=?,fr1_2=?,fr1_3=?,fr1_4=?,arc2_1=?,arc2_2=?,arc2_3=?,
                 dp3_1=?,dp3_2=?,pmd4_1=?,pmd4_2=?,pmd4_3=?,
                 total_score=?,remarks=?,revisions=?,status=?,submitted_at=? WHERE id=?'
            );
            $update->execute(array_merge($values, [$total, $remarks, $revisions, 'submitted', $submittedAt, (int) $existing['id']]));
            $evaluationId = (int) $existing['id'];
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO e3_evaluations(
                 project_id,evaluator_id,final_submission_id,
                 fr1_1,fr1_2,fr1_3,fr1_4,arc2_1,arc2_2,arc2_3,dp3_1,dp3_2,pmd4_1,pmd4_2,pmd4_3,
                 total_score,remarks,revisions,status,submitted_at
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $insert->execute(array_merge([$projectId, $evaluatorId, $finalSubmissionId], $values, [$total, $remarks, $revisions, 'submitted', $submittedAt]));
            $evaluationId = (int) $pdo->lastInsertId();
        }

        $assignment = $pdo->prepare(
            'UPDATE final_defense_evaluator_assignments SET status=?
             WHERE project_id=? AND final_submission_id=? AND evaluator_id=?'
        );
        $assignment->execute(['completed', $projectId, $finalSubmissionId, $evaluatorId]);
        if ($assignment->rowCount() !== 1) {
            throw new RuntimeException('The Final Defense assignment is no longer available for this evaluator.');
        }

        $history = $pdo->prepare(
            'INSERT INTO evaluation_history(
             project_id,evaluator_id,evaluation_type,project_score,paper_score,overall_score,overall_rating
            ) VALUES(?,?,?,?,?,?,?)'
        );
        $history->execute([$projectId, $evaluatorId, 'e3', $total, $total, $total, $historyRating]);
        $pdo->commit();

        return $evaluationId;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function e3_consolidated(PDO $pdo, int $projectId, ?int $submissionId = null): array
{
    $criteria = e3_criteria();
    $consolidated = [];
    $sql = 'SELECT * FROM e3_evaluations WHERE project_id = ? AND status = ?';
    $params = [$projectId, 'submitted'];
    if ($submissionId !== null) {
        $sql .= ' AND final_submission_id = ?';
        $params[] = $submissionId;
    }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
