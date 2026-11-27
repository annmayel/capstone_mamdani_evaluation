<?php
/**
 * Student Evaluation Results page partial.
 *
 * The variables are prepared by student_dashboard.php from the exact current
 * Final Defense submission. Keep this separate from the legacy CEIT-T07B
 * Proposal Evaluation display above it.
 */
?>
<section class="portal-panel" style="margin-top:24px;" aria-labelledby="final-defense-results-title">
  <div class="panel-heading">
    <div>
      <span class="eyebrow">CEIT-UR-E3 FINAL HEARING</span>
      <h3 id="final-defense-results-title">Final Defense Evaluation Results</h3>
    </div>
    <strong class="panel-score"><?=$hasFinalDefenseComputedResult ? number_format((float) $finalDefenseComputedResult['calculated_score'], 2) : 'Pending'?> <small>/100</small></strong>
  </div>

  <?php if ($hasFinalDefenseComputedResult && $finalDefenseComputedResult && $finalDefenseRecommendation): ?>
    <p class="submission-help">This computed result is based only on the evaluators assigned to your current Final Defense submission. It does not reuse Proposal Evaluation scores.</p>
    <div class="detail-grid">
      <div><small>CONSOLIDATED SCORE</small><strong><?=number_format((float) $finalDefenseComputedResult['calculated_score'], 2)?> / 100</strong></div>
      <div><small>MAMDANI FUZZY SCORE</small><strong><?=number_format((float) $finalDefenseRecommendation['fuzzy']['defuzz'], 2)?> / 100</strong></div>
      <div><small>FUZZY LINGUISTIC RATING</small><strong><?=esc($finalDefenseRecommendation['fuzzy']['rating'])?></strong></div>
      <div><small>PANEL RECOMMENDATION</small><strong><?=esc($finalDefenseRecommendation['label'])?></strong></div>
      <div><small>EVALUATION STATUS</small><strong>Evaluation Completed</strong><span><?=esc((string) $finalDefensePanel['submitted_count'])?> of <?=esc((string) $finalDefensePanel['assigned_count'])?> evaluators submitted</span></div>
      <div><small>OFFICIAL VERDICT</small><strong><?=esc($finalDefenseOfficialVerdict['verdict_label'] ?? 'Official Verdict Pending')?></strong><span><?=$finalDefenseOfficialVerdict ? 'IT Chairperson confirmed' : 'Pending IT Chairperson confirmation'?></span></div>
    </div>

    <div class="student-two-col results-grid" style="margin-top:18px;">
      <section>
        <h4>Final Hearing Criteria</h4>
        <div class="criteria-grid">
          <?php foreach (e3_criteria() as $code => $criterion): $score = (float) ($finalDefenseComputedResult['criteria'][$code] ?? 0); ?>
            <div class="result-score-item"><span><?=esc($code)?> — <?=esc($criterion['label'])?></span><strong><?=number_format($score, 2)?> / <?=number_format((float) $criterion['maximum'], 0)?></strong></div>
          <?php endforeach; ?>
        </div>
      </section>
      <section>
        <h4>Final Hearing Evaluator Comments</h4>
        <?php if ($finalDefenseFeedback): ?>
          <div class="feedback-list">
            <?php foreach ($finalDefenseFeedback as $item): ?>
              <article class="feedback-item">
                <span class="feedback-avatar">E3</span>
                <div>
                  <strong>Final Hearing evaluator</strong>
                  <small><?=esc($formatDate($item['submitted_at']))?></small>
                  <?php if ($item['remarks'] !== ''): ?><p><?=nl2br(esc($item['remarks']))?></p><?php endif; ?>
                  <?php if ($item['revisions'] !== ''): ?><p><strong>Recommendations:</strong> <?=nl2br(esc($item['revisions']))?></p><?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?><div class="empty-state">No additional Final Hearing comments or recommendations were submitted.</div><?php endif; ?>
      </section>
    </div>
  <?php elseif ($latestFinalSubmission): ?>
    <div class="empty-state">Final Hearing results will appear after every evaluator assigned to this Final Defense submission has submitted an evaluation.<?php if ($finalDefensePanel): ?> <?=esc((string) $finalDefensePanel['submitted_count'])?> of <?=esc((string) $finalDefensePanel['assigned_count'])?> assigned evaluators have submitted.<?php endif; ?></div>
  <?php else: ?>
    <div class="empty-state">Submit your Final Paper and Project before Final Hearing results can be recorded.</div>
  <?php endif; ?>
</section>
