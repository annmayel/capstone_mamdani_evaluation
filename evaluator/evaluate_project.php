<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../php/fuzzy_engine.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$projectId=(int)($_GET['project_id']??0); $evaluatorId=(int)$_SESSION['user']['id'];
$stmt=$pdo->prepare('SELECT p.*,a.status assignment_status FROM projects p INNER JOIN evaluator_assignments a ON a.project_id=p.id WHERE p.id=? AND a.evaluator_id=? LIMIT 1');
$stmt->execute([$projectId,$evaluatorId]); $project=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$project){header('Location: projects.php?evaluation_error=not_assigned');exit;}
$latest=$pdo->prepare('SELECT * FROM evaluations_project WHERE project_id=? AND evaluator_id=? ORDER BY id DESC LIMIT 1'); $latest->execute([$projectId,$evaluatorId]); $existing=$latest->fetch(PDO::FETCH_ASSOC)?:null;
$errors=[];$success='';
$verdicts=[
 'approved'=>['label'=>'Approved','score'=>97.5,'icon'=>'✓'],
 'minor'=>['label'=>'Approved with Minor Revision','score'=>87,'icon'=>'!'],
 'major'=>['label'=>'Approved with Major Revision','score'=>69.5,'icon'=>'±'],
 'not'=>['label'=>'Not Approved','score'=>24.5,'icon'=>'×'],
];
$selected='';$remarks='';
if($existing){
 $selected=trim((string)($existing['remarks_type']??''));$remarks=(string)($existing['remarks']??'');
 if($selected===''){
  $oldScore=(float)($existing['total_score']??0);
  if($oldScore>=90){$selected='approved';}
  elseif($oldScore>=80){$selected='minor';}
  elseif($oldScore>=60){$selected='major';}
  else{$selected='not';}
 }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!csrf_check($_POST['csrf']??'')){$errors[]='Your session could not be verified. Refresh the page and try again.';}
 else{
  $selected=(string)($_POST['verdict']??'');$remarks=trim((string)($_POST['remarks']??''));
  if(!isset($verdicts[$selected])){$errors[]='Choose a proposal verdict.';}
  if($remarks===''){$errors[]='Comments or suggestions are required.';}
  if(!$errors){
   $score=(float)$verdicts[$selected]['score'];
   try{
    $pdo->beginTransaction();
    $scoreFields=['functionality'=>$score,'technical_quality'=>$score,'performance'=>$score,'usability'=>$score,'uiux'=>$score,'security'=>$score,'innovation'=>$score,'relevance'=>$score,'reliability'=>$score,'completeness'=>$score];
    if($existing){
      $u=$pdo->prepare('UPDATE evaluations_project SET functionality=?,technical_quality=?,performance=?,usability=?,uiux=?,security=?,innovation=?,relevance=?,reliability=?,completeness=?,total_score=?,remarks=?,created_at=created_at WHERE id=?');
      $u->execute([...array_values($scoreFields),$score,$remarks,(int)$existing['id']]);
      $evaluationId=(int)$existing['id'];
    }else{
      $i=$pdo->prepare('INSERT INTO evaluations_project(project_id,evaluator_id,functionality,technical_quality,performance,usability,uiux,security,innovation,relevance,reliability,completeness,total_score,remarks,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $i->execute([$projectId,$evaluatorId,$scoreFields['functionality'],$scoreFields['technical_quality'],$scoreFields['performance'],$scoreFields['usability'],$scoreFields['uiux'],$scoreFields['security'],$scoreFields['innovation'],$scoreFields['relevance'],$scoreFields['reliability'],$scoreFields['completeness'],$score,$remarks]);
      $evaluationId=(int)$pdo->lastInsertId();
    }
    $panelScores=fuzzy_consolidated_scores($pdo,$projectId);
    $paperScore=(float)($panelScores['paper_score']??0);
    $fuzzyResult=$panelScores['final_score']===null?null:build_fuzzy_trace($score,$paperScore);
    $pdo->prepare('UPDATE projects SET project_score=?,paper_score=?,overall_score=?,overall_rating=? WHERE id=?')->execute([$panelScores['project_score'], $panelScores['paper_score'], $fuzzyResult['final_score']??null, $fuzzyResult['rating']??null, $projectId]);
    $otherPaper=$pdo->prepare('SELECT id FROM evaluations_paper WHERE project_id=? AND evaluator_id=? LIMIT 1');
    $otherPaper->execute([$projectId,$evaluatorId]);
    $assignmentStatus=$otherPaper->fetchColumn()?'completed':'project_evaluated';
    $pdo->prepare('UPDATE evaluator_assignments SET status=? WHERE project_id=? AND evaluator_id=?')->execute([$assignmentStatus,$projectId,$evaluatorId]);
    $pdo->prepare('INSERT INTO evaluation_history(project_id,evaluator_id,evaluation_type,project_score,paper_score,overall_score,overall_rating) VALUES(?,?,?,?,?,?,?)')->execute([$projectId,$evaluatorId,'proposal',$panelScores['project_score'],$panelScores['paper_score'],$fuzzyResult['final_score']??null,$fuzzyResult['rating']??$verdicts[$selected]['label']]);
    $pdo->commit(); $_SESSION['proposal_flash']='Proposal evaluation submitted.';
    header('Location: evaluate_project.php?project_id='.$projectId);exit;
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]='The proposal evaluation could not be saved.';error_log('[proposal] '.$e->getMessage());}
  }
 }
}
if(isset($_SESSION['proposal_flash'])){$success=$_SESSION['proposal_flash'];unset($_SESSION['proposal_flash']);}
$name=trim((string)($_SESSION['user']['full_name']??'Evaluator'));$email=trim((string)($_SESSION['user']['email']??''));
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Proposal Evaluation</title><link rel="stylesheet" href="../assets/style.css?v=20261124"><link rel="stylesheet" href="evaluator-content.css?v=20261124"><link rel="stylesheet" href="evaluator-typography.css?v=20261124"><link rel="stylesheet" href="evaluate_selection.css?v=20261124"><link rel="stylesheet" href="evaluator-e3.css?v=20261124"></head>
<body class="evaluator-content-page"><div class="dashboard-layout evaluator-layout">
<aside class="sidebar evaluator-sidebar" id="evaluator-sidebar"><div class="sidebar-header"><img class="logo-icon" src="../images_bgnd/CSUCC%20LOGO.png" alt=""><div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">EVALUATION SYSTEM</span><small>EVALUATOR PORTAL</small></div></div><ul class="sidebar-nav"><li class="nav-section-label">Workspace</li><li><a href="dashboard.php"><span class="nav-icon">▦</span><span>Dashboard</span></a></li><li class="nav-section-label">Evaluation</li><li><a class="active" href="projects.php"><span class="nav-icon">▰</span><span>Evaluate Projects</span></a></li><li><a href="evaluation_history.php"><span class="nav-icon">◷</span><span>Evaluation History</span></a></li><li class="nav-section-label">Account</li><li><a href="profile.php"><span class="nav-icon">◎</span><span>Profile</span></a></li><li><a href="../logout.php"><span class="nav-icon">↪</span><span>Logout</span></a></li></ul><div class="sidebar-user evaluator-sidebar-user"><div class="sidebar-user-avatar"><?=esc(strtoupper(substr($name,0,1)))?></div><div class="sidebar-user-info"><h5><?=esc($name)?></h5><p><?=esc($email)?></p></div></div></aside>
<button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
<main class="main-content e3-main"><div class="evaluator-topbar"><button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu" aria-controls="evaluator-sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="evaluator-topbar-end"><div class="account-avatar"><?=esc(strtoupper(substr($name,0,1)))?></div><span><?=esc($name)?></span></div></div>
<a class="selection-back" href="evaluate_selection.php?project_id=<?=$projectId?>">← Back to Evaluation Type</a>
<header class="main-header"><div class="main-header-text"><span class="page-eyebrow">Proposal stage</span><h1>Proposal Evaluation</h1><p><?=esc($project['title'])?></p></div></header>
<?php if($success):?><div class="e3-alert success"><?=esc($success)?></div><?php endif;if($errors):?><div class="e3-alert error"><strong>Please review:</strong><ul><?php foreach(array_unique($errors) as$error):?><li><?=esc($error)?></li><?php endforeach;?></ul></div><?php endif;?>
<form method="post" class="proposal-layout"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
<section class="proposal-illustration" aria-label="Project overview"><div class="proposal-paper"><span>CEIT</span><div class="proposal-line"></div><div class="proposal-line short"></div><div class="proposal-chart"><i></i><i></i><i></i><i></i><i></i></div></div><div class="proposal-project-meta"><small>Capstone proposal</small><strong><?=esc($project['title'])?></strong><span><?=esc($project['group_name']?:'Group not set')?> · <?=esc($project['adviser']?:'Adviser not assigned')?></span></div></section>
<section class="proposal-form-card"><h2>Evaluation Verdict</h2><p>Select the official proposal result.</p><div class="verdict-options">
<?php foreach($verdicts as$key=>$verdict):?><label class="verdict-row"><input type="radio" name="verdict" value="<?=esc($key)?>" <?=$selected===($key)?'checked':''?> required><span class="verdict-mark <?=esc($key==='not'?'negative':'')?>"><?=esc($verdict['icon'])?></span><span><strong><?=esc($verdict['label'])?></strong><small><?=esc($key==='approved'?'Outstanding proposal':($key==='minor'?'Minor corrections required':($key==='major'?'Major methodological revision required':'Re-evaluation required'))) ?></small></span></label><?php endforeach;?>
</div><label class="comment-field"><span>Comments / Suggestions</span><textarea name="remarks" rows="5" placeholder="Enter revisions or recommendations..." required><?=esc($remarks)?></textarea></label><div class="e3-actions"><a class="e3-btn secondary" href="evaluate_selection.php?project_id=<?=$projectId?>">Cancel</a><button class="e3-btn primary" type="submit">Submit Evaluation</button></div></section>
</form></main></div><script src="evaluator-content.js?v=20261124" defer></script></body></html>
