<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/stage_submission_migration.php';
require_once __DIR__ . '/../php/evaluation_feedback_migration.php';

final class StageSubmissionMigrationTest extends TestCase
{
    public function testSchemaReadinessRequiresBothSubmissionStagesAndWorkflowColumns(): void
    {
        $state = [
            'columns' => [
                'student_submissions' => [
                    'stage' => "enum('proposal','final')",
                    'status' => "enum('submitted','under_evaluation','evaluated','revision_requested','revision_required','approved','accepted')",
                ],
                'projects' => [
                    'proposal_verdict_submission_id' => 'int',
                    'proposal_revision_accepted_submission_id' => 'int',
                ],
                'evaluations_project' => ['proposal_submission_id' => 'int'],
                'e3_evaluations' => ['final_submission_id' => 'int'],
                'e3_final_verdicts' => ['final_submission_id' => 'int'],
                'stage_verdict_history' => ['id' => 'int'],
            ],
            'indexes' => [
                'student_submissions' => ['idx_student_submissions_project_stage' => true],
                'e3_evaluations' => ['uniq_e3_evaluation_submission_evaluator' => true],
            ],
        ];

        self::assertTrue(stage_submission_schema_is_ready($state));

        unset($state['columns']['student_submissions']['stage']);
        self::assertFalse(stage_submission_schema_is_ready($state));

        $state['columns']['student_submissions']['stage'] = "enum('proposal','final')";
        unset($state['columns']['e3_final_verdicts']['final_submission_id']);
        self::assertFalse(stage_submission_schema_is_ready($state));
    }

    public function testStatusMigrationPreservesExistingEnumValues(): void
    {
        $definition = stage_submission_status_enum_definition(
            "enum('submitted','under_evaluation','evaluated','revision_requested','custom_status')"
        );

        self::assertStringContainsString("'custom_status'", $definition);
        self::assertStringContainsString("'revision_required'", $definition);
        self::assertStringContainsString("'approved'", $definition);
        self::assertStringContainsString("'accepted'", $definition);
    }

    public function testEvaluationFeedbackSchemaRequiresSubmissionAndEvaluationLinks(): void
    {
        $columns = array_fill_keys([
            'id',
            'project_id',
            'submission_id',
            'evaluator_id',
            'evaluation_type',
            'stage',
            'evaluation_id',
            'score',
            'strengths',
            'weaknesses',
            'recommendations',
            'required_revisions',
            'remarks',
            'created_at',
            'updated_at',
        ], true);
        $indexes = array_fill_keys([
            'idx_evaluation_feedback_project',
            'idx_evaluation_feedback_evaluator',
            'idx_evaluation_feedback_created',
            'idx_evaluation_feedback_submission',
            'idx_evaluation_feedback_evaluation',
        ], true);

        self::assertTrue(evaluation_feedback_schema_is_ready(['columns' => $columns, 'indexes' => $indexes]));

        unset($columns['submission_id']);
        self::assertFalse(evaluation_feedback_schema_is_ready(['columns' => $columns, 'indexes' => $indexes]));
    }
}
