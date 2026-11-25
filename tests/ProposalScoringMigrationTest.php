<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../php/proposal_scoring_migration.php';

final class ProposalScoringMigrationTest extends TestCase
{
    public function testSchemaReadinessRequiresProposalCriteriaAndWorkflowColumns(): void
    {
        $state = [
            'columns' => [
                'evaluations_project' => [
                    'proposal_status' => "enum('draft','submitted')",
                    'proposal_background' => 'decimal(5,2)',
                    'proposal_literature' => 'decimal(5,2)',
                    'proposal_methodology' => 'decimal(5,2)',
                    'proposal_design' => 'decimal(5,2)',
                    'proposal_presentation' => 'decimal(5,2)',
                    'proposal_question_answer' => 'decimal(5,2)',
                    'proposal_background_comment' => 'text',
                    'proposal_literature_comment' => 'text',
                    'proposal_methodology_comment' => 'text',
                    'proposal_design_comment' => 'text',
                    'proposal_presentation_comment' => 'text',
                    'proposal_question_answer_comment' => 'text',
                    'proposal_total_score' => 'decimal(6,2)',
                    'proposal_evaluator_comments' => 'text',
                ],
                'evaluator_assignments' => ['proposal_draft' => 'longtext'],
                'projects' => [
                    'proposal_official_score' => 'decimal(6,2)',
                    'proposal_official_verdict' => 'varchar(100)',
                    'proposal_required_revisions' => 'text',
                    'proposal_chairperson_comments' => 'text',
                    'proposal_chairperson_id' => 'int',
                    'proposal_confirmed_at' => 'datetime',
                ],
            ],
        ];

        self::assertTrue(proposal_scoring_schema_is_ready($state));

        unset($state['columns']['evaluations_project']['proposal_background']);
        self::assertFalse(proposal_scoring_schema_is_ready($state));
    }
}
