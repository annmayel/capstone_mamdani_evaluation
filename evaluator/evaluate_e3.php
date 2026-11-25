<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/e3.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
require_once __DIR__ . '/../php/submissions.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$projectId=(int)($_GET['project_id']??0);$evaluatorId=(int)$_SESSION['user']['id'];
$projectStmt=$pdo->prepare('SELECT p.*,a.status assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id=p.id WHERE p.id=? AND a.evaluator_id=? LIMIT 1');
$projectStmt->execute([$projectId,$evaluatorId]);$project=$projectStmt->fetch(PDO::FETCH_ASSOC);
if(!$project){header('Location: projects.php?evaluation_error=not_assigned');exit;}
$proposalSubmission=latest_stage_submission($pdo,$projectId,'proposal');
$finalSubmission=latest_stage_submission($pdo,$projectId,'final');
$finalPdf=$finalSubmission&&!empty($finalSubmission['stored_filename'])
    ? dirname(__DIR__).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'submissions'.DIRECTORY_SEPARATOR.$finalSubmission['stored_filename']
    : '';
if(!$finalSubmission||!in_array($finalSubmission['status'],['submitted','under_evaluation','accepted','revision_required'],true)||$finalPdf===''||!is_file($finalPdf)||!proposal_allows_final_submission($project,$proposalSubmission)){
 header('Location: evaluate_selection.php?project_id='.$projectId.'&evaluation_error=final_not_submitted');exit;
}
if($finalSubmission['status']==='submitted'){
 $pdo->prepare("UPDATE student_submissions SET status='under_evaluation' WHERE id=? AND status='submitted'")->execute([(int)$finalSubmission['id']]);
 $finalSubmission['status']='under_evaluation';
}
$criteria=e3_criteria();$errors=[];$success='';
$existingStmt=$pdo->prepare('SELECT * FROM e3_evaluations WHERE project_id=? AND evaluator_id=? AND final_submission_id=? ORDER BY id DESC LIMIT 1');$existingStmt->execute([$projectId,$evaluatorId,(int)$finalSubmission['id']]);$existing=$existingStmt->fetch(PDO::FETCH_ASSOC)?:null;
$panelResult=e3_consolidated($pdo,$projectId,(int)$finalSubmission['id']);
$panelRecommendation=$panelResult['calculated_score']===null?null:e3_stage_verdict_from_score((float)$panelResult['calculated_score']);
$officialStmt=$pdo->prepare('SELECT * FROM e3_final_verdicts WHERE project_id=? AND final_submission_id=? AND published_at IS NOT NULL ORDER BY id DESC LIMIT 1');
$officialStmt->execute([$projectId,(int)$finalSubmission['id']]);$officialResult=$officialStmt->fetch(PDO::FETCH_ASSOC)?:null;
$evaluationLocked=($existing&&$existing['status']==='submitted')||$officialResult!==null;
$form=$existing?:[];$commentValues=[];
foreach($criteria as$code=>$criterion){$commentValues[$code]=(string)($form[$criterion['key'].'_comment']??'');}
if($existing&&trim((string)($existing['revisions']??''))!== ''){
 $revisionLines=preg_split('/\R/',(string)$existing['revisions'])?:[];
 foreach($revisionLines as$line){if(preg_match('/^([^—]+) — (.*)$/',$line,$m)&&isset($commentValues[trim($m[1])])){$commentValues[trim($m[1])]=$m[2];}}
}
$isSummary=(isset($_GET['summary'])&&$_GET['summary']==='1')||($_POST['flow']??'')==='summary'||($_POST['flow']??'')==='submit';
if(isset($_GET['summary'])&&$_GET['summary']==='1'&&$_SERVER['REQUEST_METHOD']!=='POST'){
 $summaryComplete=$existing&&$existing['status']==='submitted';
 if(!$summaryComplete){$isSummary=false;}
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check($_POST['csrf']??'')){$errors[]='Your session could not be verified. Refresh the page and try again.';}
  elseif($officialResult){$errors[]='The IT Chairperson has already confirmed the official verdict for this Final Paper.';}
  elseif($existing&&$existing['status']==='submitted'){$errors[]='This E3 evaluation has already been submitted and cannot be edited.';}
 else{
  foreach($criteria as$code=>$criterion){$key=$criterion['key'];if(array_key_exists($key,$_POST))$form[$key]=(string)$_POST[$key];$commentKey='comment_'.$criterion['key'];$commentValues[$code]=trim((string)($_POST[$commentKey]??''));}
  $form['remarks']=trim((string)($_POST['remarks']??''));$validation=e3_validate_scores($_POST);
  if(($validation['errors'])){$errors=array_merge($errors,$validation['errors']);}
  $action=(string)($_POST['flow']??'summary');
  if(!$errors&&$action==='submit'){
   $scores=$validation['scores'];$total=$validation['total'];
   $commentLines=[];
   foreach($criteria as$code=>$criterion){if($commentValues[$code]!=='')$commentLines[]=$code.' — '.$commentValues[$code];}
   $commentText=implode("\n",$commentLines);$remarks=$form['remarks']!==''?$form['remarks']:null;
   try{
    $pdo->beginTransaction();$values=[];
    foreach($criteria as$criterion){$key=$criterion['key'];$values[$key]=isset($scores[$key])?$scores[$key]:null;}
    if($existing){
     $u=$pdo->prepare('UPDATE e3_evaluations SET fr1_1=?,fr1_2=?,fr1_3=?,fr1_4=?,arc2_1=?,arc2_2=?,arc2_3=?,dp3_1=?,dp3_2=?,pmd4_1=?,pmd4_2=?,pmd4_3=?,total_score=?,remarks=?,revisions=?,status=?,submitted_at=? WHERE id=?');
     $existingId=(int)$existing['id'];
     $updateValues=[$values['fr1_1'],$values['fr1_2'],$values['fr1_3'],$values['fr1_4'],$values['arc2_1'],$values['arc2_2'],$values['arc2_3'],$values['dp3_1'],$values['dp3_2'],$values['pmd4_1'],$values['pmd4_2'],$values['pmd4_3'],$total,$remarks,$commentText,'submitted',date('Y-m-d H:i:s'),$existingId];
     $u->execute($updateValues);
    }else{
     $i=$pdo->prepare('INSERT INTO e3_evaluations(project_id,evaluator_id,final_submission_id,fr1_1,fr1_2,fr1_3,fr1_4,arc2_1,arc2_2,arc2_3,dp3_1,dp3_2,pmd4_1,pmd4_2,pmd4_3,total_score,remarks,revisions,status,submitted_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
     $i->execute([$projectId,$evaluatorId,(int)$finalSubmission['id'],$values['fr1_1'],$values['fr1_2'],$values['fr1_3'],$values['fr1_4'],$values['arc2_1'],$values['arc2_2'],$values['arc2_3'],$values['dp3_1'],$values['dp3_2'],$values['pmd4_1'],$values['pmd4_2'],$values['pmd4_3'],$total,$remarks,$commentText,'submitted',date('Y-m-d H:i:s')]);
    }
    $pdo->prepare('UPDATE evaluator_assignments SET status=? WHERE project_id=? AND evaluator_id=?')->execute(['completed',$projectId,$evaluatorId]);
    $verdictForHistory=e3_stage_verdict_from_score($total);
    $pdo->prepare('INSERT INTO evaluation_history(project_id,evaluator_id,evaluation_type,project_score,paper_score,overall_score,overall_rating) VALUES(?,?,?,?,?,?,?)')->execute([$projectId,$evaluatorId,'e3',$total,$total,$total,$verdictForHistory['label']]);
    $pdo->commit();$_SESSION['e3_flash']='E3 evaluation submitted. Total: '.number_format($total,2).'/100.';header('Location: evaluate_e3.php?project_id='.$projectId);exit;
   }catch(Throwable$e){if($pdo->inTransaction())$pdo->rollBack();log_workflow_database_error('e3_evaluation_submit','save_evaluator_scores_and_comments',$e);$errors[]='The E3 evaluation could not be saved.';}
  }
 }
}
if(isset($_SESSION['e3_flash'])){$success=$_SESSION['e3_flash'];unset($_SESSION['e3_flash']);}
if($errors && ($_POST['flow']??'')==='summary'){$isSummary=false;}
$fieldValue=function($key)use($form,$existing){return array_key_exists($key,$form)&&$form[$key]!==null?(string)$form[$key]:'';};
$name=trim((string)($_SESSION['user']['full_name']??'Evaluator'));$email=trim((string)($_SESSION['user']['email']??''));
$categoryTotals=['I. Functionality and Results'=>50,'II. Academic Rigour and Conclusion'=>20,'III. Defence and Presentation'=>10,'IV. Printed Manuscript and Documentation Quality'=>20];
$categoryScores=[];$rawTotal=0;
foreach($criteria as$criterion){$value=(float)($fieldValue($criterion['key'])!==''?$fieldValue($criterion['key']):0);$rawTotal+=is_finite($value)?$value:0;$cat=$criterion['category'];$categoryScores[$cat]=($categoryScores[$cat]??0)+$value;}
try{$trace=build_fuzzy_trace($rawTotal,$rawTotal);}catch(Throwable$e){$trace=null;}
$currentInput=[];
foreach($criteria as$criterion){$currentInput[$criterion['key']]=$fieldValue($criterion['key']);}
$currentValidation=e3_validate_scores($currentInput);
$verdict=!$currentValidation['errors']?e3_stage_verdict_from_score($rawTotal):null;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CEIT-UR-E3 Final Hearing Evaluation</title><link rel="stylesheet" href="../assets/style.css?v=20261124"><link rel="stylesheet" href="evaluator-content.css?v=20261124"><link rel="stylesheet" href="evaluator-typography.css?v=20261124"><link rel="stylesheet" href="evaluate_selection.css?v=20261124"><link rel="stylesheet" href="evaluator-e3.css?v=20261124"></head>
<body class="evaluator-content-page"><div class="dashboard-layout evaluator-layout">
<aside class="sidebar evaluator-sidebar" id="evaluator-sidebar"><div class="sidebar-header"><img class="logo-icon" src="../images_bgnd/CSUCC%20LOGO.png" alt=""><div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div></div><ul class="sidebar-nav"><li class="nav-section-label">Workspace</li><li><a href="dashboard.php"><span class="nav-icon">▦</span><span>Dashboard</span></a></li><li class="nav-section-label">Evaluation</li><li><a class="active" href="projects.php"><span class="nav-icon">▰</span><span>Evaluate Projects</span></a></li><li><a href="evaluation_history.php"><span class="nav-icon">◷</span><span>Evaluation History</span></a></li><li class="nav-section-label">Account</li><li><a href="profile.php"><span class="nav-icon">◎</span><span>Profile</span></a></li><li><a href="../logout.php"><span class="nav-icon">↪</span><span>Logout</span></a></li></ul><div class="sidebar-user evaluator-sidebar-user"><div class="sidebar-user-avatar"><?=esc(strtoupper(substr($name,0,1)))?></div><div class="sidebar-user-info"><h5><?=esc($name)?></h5><p><?=esc($email)?></p></div></div></aside>
<button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
<main class="main-content e3-main"><div class="evaluator-topbar"><button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu" aria-controls="evaluator-sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="evaluator-topbar-end"><div class="account-avatar"><?=esc(strtoupper(substr($name,0,1)))?></div><span><?=esc($name)?></span></div></div>
<a class="selection-back" href="<?=$isSummary?'evaluate_selection.php?project_id='.$projectId:'projects.php'?>">← <?=$isSummary?'Back to Evaluation Type':'Back to Assigned Projects'?></a>
<header class="main-header e3-header"><div class="main-header-text"><span class="page-eyebrow">Final hearing · CEIT-UR-E3</span><h1><?=$isSummary?'Evaluation Summary':'Final Hearing Evaluation'?></h1><p>PART 1: GRADING RUBRIC — 100 POINTS · <?=esc($finalSubmission['original_filename'])?></p><a class="e3-btn secondary" href="view_submission.php?id=<?=esc($finalSubmission['id'])?>&amp;stage=final" target="_blank" rel="noopener">Open Final Paper PDF</a></div></header>
<?php if($success):?><div class="e3-alert success"><?=esc($success)?></div><?php endif;if($errors):?><div class="e3-alert error"><strong>Please review:</strong><ul><?php foreach(array_unique($errors)as$error):?><li><?=esc($error)?></li><?php endforeach;?></ul></div><?php endif;?>
<section class="project-info-card e3-panel-result"><div class="project-image"><span>E3 RESULT</span></div><dl>
<div><dt>Panel evaluations submitted</dt><dd><?=esc($panelResult['evaluator_count'])?></dd></div>
<div><dt>Panel score</dt><dd><?=$panelResult['calculated_score']===null?'Pending':number_format((float)$panelResult['calculated_score'],2).' / 100'?></dd></div>
<div><dt>Recommended verdict</dt><dd><?=esc($panelRecommendation['label']??'Pending evaluator submissions')?></dd></div>
<div><dt>Mamdani fuzzy output</dt><dd><?=$panelRecommendation?number_format((float)$panelRecommendation['fuzzy']['defuzz'],2).' / 100':'Pending'?></dd></div>
<div><dt>Official verdict</dt><dd><?=esc($officialResult['verdict_label']??'Pending IT Chairperson confirmation')?></dd></div>
<?php if($officialResult&&!empty($officialResult['revisions'])):?><div><dt>Chairperson required revisions</dt><dd><?=nl2br(esc($officialResult['revisions']))?></dd></div><?php endif;?>
</dl></section>
<?php if(!$isSummary):?>
<section class="project-info-card"><div class="project-image"><span>CAPSTONE</span></div><dl><div><dt>Program</dt><dd><?=esc($project['academic_year']?('BS Information Technology · '.$project['academic_year']):'BS Information Technology')?></dd></div><div><dt>Date and Time</dt><dd><?php if(!empty($project['defense_time'])): ?><?=esc(date('M j, Y',strtotime($project['defense_date'])) . ' · ' . $project['defense_time'])?><?php elseif(!empty($project['defense_date'])): ?><?=esc(date('M j, Y',strtotime($project['defense_date'])))?><?php else: ?>Not set<?php endif; ?></dd></div><div><dt>Thesis / Capstone Title</dt><dd><?=esc($project['title'])?></dd></div><div><dt>Proponents</dt><dd><?=esc(!empty($project['members'])?$project['members']:($project['group_name']?:'Not set'))?></dd></div><div><dt>Adviser</dt><dd><?=esc($project['adviser']?:'Not assigned')?></dd></div><div><dt>Panel Member Evaluating</dt><dd><?=esc($name)?></dd></div></dl></section>
<form method="post" class="rubric-form"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><input type="hidden" name="flow" value="summary">
<?php $last='';foreach($criteria as$code=>$criterion):if($last!==$criterion['category']):$last=$criterion['category'];?><h2 class="rubric-category"><?=esc($criterion['category'])?></h2><?php endif;?>
<section class="rubric-row"><div class="rubric-code"><?=esc($code)?></div><div class="rubric-copy"><h3><?=esc($criterion['label'])?></h3><p><?=esc($criterion['description'])?></p></div><label class="rubric-score"><span>Score · max <?=esc($criterion['maximum'])?></span><input type="number" name="<?=esc($criterion['key'])?>" min="0" max="<?=esc($criterion['maximum'])?>" step="0.01" value="<?=esc($fieldValue($criterion['key']))?>" <?=$evaluationLocked?'disabled':'required'?>></label><label class="rubric-comment"><span>Comment</span><textarea name="comment_<?=esc($criterion['key'])?>" rows="3" placeholder="Add criterion comment" <?=$evaluationLocked?'disabled':''?>><?=esc($commentValues[$code])?></textarea></label></section>
<?php endforeach;?>
<label class="rubric-general"><span>Revisions, comments and recommendations</span><textarea name="remarks" rows="5" <?=$evaluationLocked?'disabled':''?>><?=esc($fieldValue('remarks'))?></textarea></label>
<div class="rubric-footer"><div class="rubric-total"><span>Total Raw Score</span><strong id="e3-total"><?=number_format($rawTotal,2)?><small>/100</small></strong></div><div class="e3-actions"><a class="e3-btn secondary" href="evaluate_selection.php?project_id=<?=$projectId?>">Cancel</a><?php if(!$evaluationLocked):?><button class="e3-btn primary" type="submit">Review Summary</button><?php else:?><strong class="e3-locked-message">Evaluation is locked</strong><?php endif;?></div></div></form>
<?php else:?>
<section class="summary-layout"><div class="summary-breakdown"><h2>Score Breakdown</h2><?php foreach($categoryTotals as$cat=>$max):?><div class="breakdown-row"><div><span><?=esc($cat)?></span><small>Max <?= $max ?> points</small></div><strong><?=number_format($categoryScores[$cat]??0,2)?> <small>/ <?= $max ?></small></strong></div><div class="breakdown-track"><i style="width:<?= $max?min(100,(($categoryScores[$cat]??0)/$max)*100):0?>%"></i></div><?php endforeach;?><div class="raw-score-card"><span>Total Raw Score</span><strong><?=number_format($rawTotal,2)?> <small>/ 100</small></strong></div></div>
<aside class="mamdani-card"><span class="summary-label">Mamdani Result</span><strong><?=esc($trace?$trace['defuzz']:($verdict?$rawTotal:'0.00'))?><small>/100</small></strong><p><?=esc($trace?$trace['rating']:($verdict?$verdict['label']:''))?></p></aside></section>
<section class="final-verdict <?=$verdict&&str_starts_with($verdict['label'],'PASSED')?'passing':''?>"><span>Final Verdict</span><strong><?=esc($verdict?$verdict['label']:'Pending')?></strong><p><?=esc($verdict?$verdict['description']:'Complete the rubric to generate a verdict.')?></p></section>
<section class="revision-summary"><h2>Revisions / Comments by Criterion</h2><?php $hasComment=false;foreach($criteria as$code=>$criterion):if($commentValues[$code]!==''){$hasComment=true;}endforeach; if(!$hasComment):?><p class="muted-comment">No individual criterion comments were added.</p><?php else:foreach($criteria as$code=>$criterion):if($commentValues[$code]!==''):?><article><span><?=esc($code)?></span><div><h3><?=esc($criterion['label'])?></h3><p><?=esc($commentValues[$code])?></p></div></article><?php endif;endforeach;endif;?></section>
<form method="post" class="summary-submit"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><input type="hidden" name="flow" value="submit">
<?php foreach($criteria as$code=>$criterion):?><input type="hidden" name="<?=esc($criterion['key'])?>" value="<?=esc($fieldValue($criterion['key']))?>"><input type="hidden" name="comment_<?=esc($criterion['key'])?>" value="<?=esc($commentValues[$code])?>"><?php endforeach;?> <input type="hidden" name="remarks" value="<?=esc($fieldValue('remarks'))?>"><div class="e3-actions"><a class="e3-btn secondary" href="evaluate_e3.php?project_id=<?=$projectId?>">Back</a><?php if(!$evaluationLocked):?><button class="e3-btn primary" type="submit">Submit Evaluation</button><?php else:?><strong class="e3-locked-message">Evaluation is locked</strong><?php endif;?></div></form>
<?php endif;?>
</main></div>
<script src="evaluator-content.js?v=20261124" defer></script>
<script>(()=>{const form=document.querySelector('.rubric-form');if(!form)return;const out=document.getElementById('e3-total');const update=()=>{let total=0;form.querySelectorAll('input[type=number]').forEach(i=>{const n=parseFloat(i.value);if(Number.isFinite(n))total+=n});out.innerHTML=total.toFixed(2)+'<small>/100</small>'};form.addEventListener('input',update);update()})();</script></body></html>
