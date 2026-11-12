<?php
require_once __DIR__ . '/../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'evaluator') { header('Location: ../index.php'); exit; }
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$profileErrors = [];
$profileUpdated = isset($_GET['profile_updated']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $fullName = trim((string) ($_POST['full_name'] ?? ''));
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $profileErrors[] = 'Your session expired. Refresh the page and try again.';
  } elseif ($fullName === '' || strlen($fullName) > 200) {
    $profileErrors[] = 'Enter your full name (up to 200 characters).';
  } else {
    $updateNameStmt = $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ? AND role = ?');
    $updateNameStmt->execute([$fullName, $userId, 'evaluator']);
    $_SESSION['user']['full_name'] = $fullName;
    header('Location: profile.php?profile_updated=1');
    exit;
  }
}

$profileStmt = $pdo->prepare('SELECT full_name, email, employee_id_number, department FROM users WHERE id = ? AND role = ? LIMIT 1');
$profileStmt->execute([$userId, 'evaluator']);
$user = $profileStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: ../logout.php'); exit; }
$_SESSION['user']['full_name'] = $user['full_name'];
$evaluatorUser = $user;
$evaluatorFullName = trim((string)($evaluatorUser['full_name'] ?? 'Evaluator'));
$evaluatorEmail = trim((string)($evaluatorUser['email'] ?? ''));
$displayName = $evaluatorFullName ?: 'Evaluator';
$displayEmail = $evaluatorEmail ?: 'No email available';

function evaluator_content_sidebar(string $active, string $displayName, string $displayEmail): void { ?>
  <aside class="sidebar evaluator-sidebar" id="evaluator-sidebar" aria-label="Evaluator navigation">
    <div class="sidebar-header"><div class="logo-icon" aria-hidden="true"></div><div class="brand-copy"><span class="brand-line">CAPSTONE</span><span class="brand-line brand-emphasis">FUZZY</span><small>EVALUATOR</small></div></div>
    <ul class="sidebar-nav">
      <li class="nav-section-label">Workspace</li><li><a href="dashboard.php"><span class="nav-icon" aria-hidden="true">▦</span><span class="nav-label">Dashboard</span></a></li>
      <li class="nav-section-label">Evaluation</li>
      <li><a href="projects.php" class="<?=$active === 'projects' ? 'active' : ''?>" <?=$active === 'projects' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">▰</span><span class="nav-label">Evaluate Projects</span></a></li>
      <li><a href="papers.php" class="<?=$active === 'papers' ? 'active' : ''?>" <?=$active === 'papers' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">▤</span><span class="nav-label">Evaluate Papers</span></a></li>
      <li><a href="evaluation_history.php" class="<?=$active === 'history' ? 'active' : ''?>" <?=$active === 'history' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">◷</span><span class="nav-label">Evaluation History</span></a></li>
      <li class="nav-section-label">Account</li><li><a href="profile.php" class="<?=$active === 'profile' ? 'active' : ''?>" <?=$active === 'profile' ? 'aria-current="page"' : ''?>><span class="nav-icon" aria-hidden="true">◎</span><span class="nav-label">Profile</span></a></li>
      <li><a href="../logout.php"><span class="nav-icon" aria-hidden="true">↪</span><span class="nav-label">Logout</span></a></li>
    </ul>
    <div class="sidebar-user evaluator-sidebar-user"><div class="sidebar-user-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div><div class="sidebar-user-info"><h5><?=esc($displayName)?></h5><p><?=esc($displayEmail)?></p></div></div>
  </aside>
<?php } ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Evaluator Profile</title>
  <link rel="stylesheet" href="../assets/style.css?v=20260918">
  <link rel="stylesheet" href="evaluator-content.css?v=20261106">
  <link rel="stylesheet" href="evaluator-typography.css?v=20261112">
  <link rel="icon" type="image/png" href="../favicon.png?v=20261016">
</head>
<body class="evaluator-content-page">
  <div class="dashboard-layout evaluator-layout">
    <?php evaluator_content_sidebar('profile', $displayName, $displayEmail); ?>
    <button class="sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>
    <main class="main-content">
      <div class="evaluator-topbar">
        <button class="mobile-menu-toggle" type="button" aria-label="Open navigation menu" aria-controls="evaluator-sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <div class="evaluator-topbar-end"><div class="account-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div><span><?=esc($displayName)?></span></div>
      </div>
      <header class="main-header"><div class="main-header-text"><span class="page-eyebrow">Account settings</span><h1>Evaluator Profile</h1><p>Your account information and academic identity.</p></div></header>
      <section class="profile-hero" aria-label="Profile overview">
        <div class="account-avatar" aria-hidden="true"><?=esc(strtoupper(substr($displayName ?: 'E', 0, 1)))?></div>
        <div><h2><?=esc($user['full_name'] ?? 'Evaluator')?></h2><p><?=esc($user['email'] ?? '')?></p></div>
      </section>
      <section class="work-panel profile-panel" aria-labelledby="profile-details-title">
        <div class="work-panel-head"><div><h2 id="profile-details-title">Personal information</h2><p>Only your full name can be changed here.</p></div></div>
        <?php if ($profileUpdated || $profileErrors): ?>
          <div class="profile-feedback">
            <?php if ($profileUpdated): ?><div class="register-field-feedback is-valid" role="status">Your full name has been updated.</div><?php endif; ?>
            <?php foreach ($profileErrors as $profileError): ?><div class="register-field-feedback is-invalid" role="alert"><?=esc($profileError)?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form method="post" class="profile-form" data-profile-form data-editing="<?=$profileErrors ? 'true' : 'false'?>">
          <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
          <div class="profile-form-grid">
            <div class="profile-field"><label for="profile-full-name">Full name</label><input id="profile-full-name" name="full_name" type="text" maxlength="200" value="<?=esc($_POST['full_name'] ?? $user['full_name'] ?? '')?>" required autocomplete="name"></div>
            <div class="profile-field"><label for="profile-email">Email address</label><input id="profile-email" type="email" value="<?=esc($user['email'] ?? '')?>" readonly aria-readonly="true"></div>
            <div class="profile-field"><label for="profile-employee-id">Employee ID number</label><input id="profile-employee-id" type="text" value="<?=esc($user['employee_id_number'] ?? 'Not set')?>" readonly aria-readonly="true"></div>
            <div class="profile-field"><label for="profile-department">Department / program</label><input id="profile-department" type="text" value="<?=esc($user['department'] ?? 'Not set')?>" readonly aria-readonly="true"></div>
            <div class="profile-field"><label for="profile-role">Role</label><input id="profile-role" type="text" value="Evaluator" readonly aria-readonly="true"></div>
          </div>
          <div class="profile-form-actions">
            <button class="btn primary" type="submit" data-profile-save>Save changes</button>
            <button class="profile-cancel" type="button" data-profile-cancel hidden>Cancel</button>
            <button class="btn ghost" type="button" data-profile-edit hidden>Edit full name</button>
          </div>
        </form>
      </section>
    </main>
  </div>
  <script src="evaluator-content.js?v=20261106" defer></script>
</body>
</html>
