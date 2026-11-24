<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/stage_submission_migration.php';

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
    }
}
