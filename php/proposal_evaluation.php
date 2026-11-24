<?php

function proposal_criteria(): array
{
    return [
        'background' => [
            'key' => 'proposal_background',
            'comment_key' => 'proposal_background_comment',
            'label' => 'Project Background',
            'description' => 'Clarity of the problem, objectives, and significance of the study.',
            'maximum' => 20,
            'icon' => '<path d="M9 18h6M10 22h4M8 14c-1.2-1-2-2.5-2-4a6 6 0 1 1 12 0c0 1.5-.8 3-2 4l-1 2H9l-1-2Z"/><path d="M12 2v2"/>',
        ],
        'literature' => [
            'key' => 'proposal_literature',
            'comment_key' => 'proposal_literature_comment',
            'label' => 'Related Literature',
            'description' => 'Relevance of current literature and clarity of the research gap.',
            'maximum' => 20,
            'icon' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/><path d="M8 7h8M8 11h7"/>',
        ],
        'methodology' => [
            'key' => 'proposal_methodology',
            'comment_key' => 'proposal_methodology_comment',
            'label' => 'Methodology',
            'description' => 'Appropriateness of the research methods and development plan.',
            'maximum' => 20,
            'icon' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.7-1.4-2.4L7.3 15a8 8 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.7-1L11 7.4h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a8 8 0 0 1-.1 2Z"/>',
        ],
        'design' => [
            'key' => 'proposal_design',
            'comment_key' => 'proposal_design_comment',
            'label' => 'System Design',
            'description' => 'Feasibility and completeness of the proposed system design.',
            'maximum' => 20,
            'icon' => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4M7 8h10M7 12h6"/>',
        ],
        'presentation' => [
            'key' => 'proposal_presentation',
            'comment_key' => 'proposal_presentation_comment',
            'label' => 'Presentation',
            'description' => 'Clarity, organization, and effectiveness of the presentation.',
            'maximum' => 20,
            'icon' => '<path d="M3 3h18v13H3z"/><path d="m7 12 3-3 2 2 4-5M8 21l4-5 4 5"/>',
        ],
        'question_answer' => [
            'key' => 'proposal_question_answer',
            'comment_key' => 'proposal_question_answer_comment',
            'label' => 'Question and Answer',
            'description' => 'Depth of knowledge and ability to defend the proposal.',
            'maximum' => 20,
            'icon' => '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/>',
        ],
    ];
}

function proposal_validate_scores(array $input, bool $requireComplete = true): array
{
    $scores = [];
    $errors = [];

    foreach (proposal_criteria() as $criterionKey => $criterion) {
        $key = $criterion['key'];
        $raw = trim((string) ($input[$key] ?? ''));
        if ($raw === '') {
            if ($requireComplete) {
                $errors[] = $criterion['label'] . ' score is required.';
            } else {
                $scores[$key] = null;
            }
            continue;
        }
        if (!preg_match('/^(?:\d+(?:\.\d{1,2})?)$/', $raw)) {
            $errors[] = $criterion['label'] . ' score must be a non-negative number with up to two decimal places.';
            continue;
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value > (float) $criterion['maximum']) {
            $errors[] = $criterion['label'] . ' score must be between 0 and ' . $criterion['maximum'] . '.';
            continue;
        }
        $scores[$key] = round($value, 2);
    }

    $scoredCount = count(array_filter($scores, static fn($score) => $score !== null));
    $total = round(array_sum(array_map(static fn($score) => (float) ($score ?? 0), $scores)), 2);
    return [
        'scores' => $scores,
        'total' => $total,
        'percentage' => round(($total / 120) * 100, 2),
        'complete' => $scoredCount === count(proposal_criteria()),
        'errors' => $errors,
    ];
}

function proposal_verdict_bands(): array
{
    $bands = [];
    foreach (official_verdict_definitions() as $definition) {
        $rating = (string) $definition['label'];
        if (strpos($rating, 'No Revision') !== false) {
            $label = 'Approved';
        } elseif (strpos($rating, 'Minor Revision') !== false) {
            $label = 'Approved with Minor Revision';
        } elseif (strpos($rating, 'Major Revision') !== false) {
            $label = 'Approved with Major Revision';
        } else {
            $label = 'Not Approved';
        }
        if (!isset($bands[$label])) {
            $bands[$label] = [
                'label' => $label,
                'minimum' => (int) $definition['minimum'],
                'maximum' => (int) $definition['maximum'],
            ];
        } else {
            $bands[$label]['minimum'] = min($bands[$label]['minimum'], (int) $definition['minimum']);
            $bands[$label]['maximum'] = max($bands[$label]['maximum'], (int) $definition['maximum']);
        }
    }
    return array_values($bands);
}

function proposal_verdict_from_score(float $percentage): array
{
    $trace = build_fuzzy_trace($percentage, $percentage);
    $rating = (string) $trace['rating'];

    if (strpos($rating, 'No Revision') !== false) {
        $label = 'Approved';
        $description = 'The proposal meets the required standard and may proceed.';
    } elseif (strpos($rating, 'Minor Revision') !== false) {
        $label = 'Approved with Minor Revision';
        $description = 'Small corrections are required before proceeding.';
    } elseif (strpos($rating, 'Major Revision') !== false) {
        $label = 'Approved with Major Revision';
        $description = 'Significant revisions are required before proceeding.';
    } else {
        $label = 'Not Approved';
        $description = 'The proposal requires further work and re-evaluation.';
    }

    return [
        'label' => $label,
        'description' => $description,
        'percentage' => round($percentage, 2),
        'fuzzy' => $trace,
    ];
}
