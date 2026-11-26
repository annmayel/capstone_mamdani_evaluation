<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/proposal_revisions.php';
require_once __DIR__ . '/../php/submissions.php';
require_once __DIR__ . '/../php/email_notifications.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$evaluatorId=(int)$_SESSION['user']['id']; $cycleId=(int)($_GET['cycle_id']??0);
if (!proposal_revision_schema_available($pdo)) { http_response_code(503); exit('The proposal revision review schema is not installed. Apply sql/migrations/023_proposal_revision_panel_reviews.sql.'); }
$stmt=$pdo->prepare("SELECT c.*,p.title,p.group_name,p.google_drive_url,p.github_url,p.other_project_url,s.original_filename,s.stored_filename,s.id submission_id,r.decision,r.comments,r.submitted_at FROM proposal_revision_cycles c INNER JOIN projects p ON p.id=c.project_id INNER JOIN student_submissions s ON s.id=c.revision_submission_id INNER JOIN proposal_revision_reviews r ON r.revision_cycle_id=c.id WHERE c.id=? AND r.evaluator_id=? LIMIT 1");
$stmt->execute([$cycleId,$evaluatorId]); $cycle=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$cycle){http_response_code(403);exit('You are not assigned to this revision review.');}
$errors=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Your session could not be verified.';
    } elseif ($cycle['decision'] !== 'pending') {
        $errors[] = 'This revision review is already finalized.';
    } else {
        $decision = $_POST['decision'] ?? '';
        $comments = trim((string) ($_POST['comments'] ?? ''));
        if (!in_array($decision, ['accepted', 'returned'], true) || $comments === '') {
            $errors[] = 'Select a decision and provide feedback.';
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $update = $pdo->prepare("UPDATE proposal_revision_reviews SET decision=?, comments=?, submitted_at=NOW() WHERE id=? AND decision='pending'");
                $update->execute([$decision, $comments, (int) $cycle['id']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('This review was already submitted.');
                $summary = proposal_revision_refresh_status($pdo, $cycleId);
                if ($summary['status'] === 'accepted') {
                    $pdo->prepare('UPDATE projects SET proposal_revision_accepted_submission_id=? WHERE id=?')->execute([(int) $cycle['revision_submission_id'], (int) $cycle['project_id']]);
                    $pdo->prepare("UPDATE student_submissions SET status='accepted' WHERE id=?")->execute([(int) $cycle['revision_submission_id']]);
                } elseif ($summary['status'] === 'returned') {
                    $pdo->prepare("UPDATE student_submissions SET status='revision_required' WHERE id=?")->execute([(int) $cycle['revision_submission_id']]);
                }
                $pdo->commit();
                if ($summary['pending_count'] === 0) {
                    $student = $pdo->prepare('SELECT u.id FROM projects p INNER JOIN users u ON u.id=p.student_id WHERE p.id=?');
                    $student->execute([(int) $cycle['project_id']]);
                    if ($studentId = $student->fetchColumn()) notify_user_once($pdo, (int) $studentId, 'proposal-revision-complete:' . $cycleId, 'Proposal revision panel review complete', $summary['status'] === 'accepted' ? 'All assigned evaluators accepted your revised proposal.' : 'Your revised proposal was returned with additional panel feedback.', 'students/student_dashboard.php?view=proposal');
                }
                header('Location: review_proposal_revision.php?cycle_id=' . $cycleId); exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'The review could not be saved.';
                error_log('[proposal revision] ' . $e->getMessage());
            }
        }
    }
}
$summary=proposal_revision_review_summary($pdo,$cycleId);$original=$pdo->prepare('SELECT pe.*,u.full_name FROM proposal_evaluations pe INNER JOIN users u ON u.id=pe.evaluator_id WHERE pe.project_id=? AND pe.proposal_submission_id=? AND pe.status=\'submitted\' ORDER BY u.full_name');$original->execute([(int)$cycle['project_id'],(int)$cycle['original_submission_id']]);$originalReviews=$original->fetchAll(PDO::FETCH_ASSOC);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Review Revised Proposal</title><link rel="stylesheet" href="../assets/style.css"></head><body class="evaluator-content-page"><main class="main-content"><a class="selection-back" href="projects.php">← Evaluation Workspace</a><header class="main-header"><div class="main-header-text"><span class="page-eyebrow">Proposal revision <?=esc($cycle['revision_number'])?></span><h1>Review Revised Proposal</h1><p><?=esc($cycle['title'])?> · <?=esc($cycle['group_name']??'')?></p></div></header><?php if($errors):?><div class="e3-alert error"><?=esc(implode(' ',$errors))?></div><?php endif;?><section class="portal-panel"><h3>Revised submission</h3><p><?=esc($cycle['original_filename'])?> · submitted <?=esc($cycle['created_at'])?></p><a class="btn ghost" target="_blank" href="view_submission.php?id=<?=esc($cycle['submission_id'])?>&stage=proposal">View PDF</a><?php foreach(['google_drive_url'=>'Google Drive','github_url'=>'GitHub','other_project_url'=>'Other project resource'] as $field=>$label): if(!empty($cycle[$field])):?><p><strong><?=esc($label)?>:</strong> <a target="_blank" rel="noopener" href="<?=esc($cycle[$field])?>"><?=esc($cycle[$field])?></a></p><?php endif; endforeach;?><p><strong>Panel progress:</strong> <?=esc((string)$summary['accepted_count'])?> accepted, <?=esc((string)$summary['pending_count'])?> pending<?= $summary['returned_count'] ? ', '.$summary['returned_count'].' returned' : '' ?></p></section><section class="portal-panel"><h3>Original panel feedback</h3><?php foreach($originalReviews as $review):?><article class="proposal-student-revisions"><strong><?=esc($review['full_name'])?></strong><p><?=nl2br(esc((string)($review['overall_comments']??'')))?></p><p><strong>Recommendations:</strong><br><?=nl2br(esc((string)($review['recommendations']??'')))?></p><p><strong>Required revisions:</strong><br><?=nl2br(esc((string)($review['required_revisions']??'')))?></p></article><?php endforeach;?></section><section class="portal-panel"><h3>Your revision decision</h3><?php if($cycle['decision']!=='pending'):?><div class="proposal-student-revisions"><strong><?=esc(ucfirst($cycle['decision']))?></strong><p><?=nl2br(esc((string)$cycle['comments']))?></p></div><?php else:?><form method="post" class="submission-form"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><label><input type="radio" name="decision" value="accepted" required> Revision Accepted</label><label><input type="radio" name="decision" value="returned" required> Return for Further Corrections</label><label>Comments / feedback<textarea name="comments" required maxlength="2000" rows="5"></textarea></label><button class="btn primary">Submit Review</button></form><?php endif;?></section></main></body></html>
