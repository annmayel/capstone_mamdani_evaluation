<?php
$sidebarActiveView = $view ?? (basename($_SERVER['SCRIPT_NAME']) === 'proposal.php' ? 'proposal' : 'overview');
$sidebarName = $displayName ?? (string) ($_SESSION['user']['full_name'] ?? 'Student');
$sidebarInitial = $initial ?? strtoupper(substr($sidebarName, 0, 1));
$sidebarEmail = (string) ($user['email'] ?? $_SESSION['user']['email'] ?? '');
$sidebarIcons = [
    'dashboard' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
    'project' => '<path d="M3 7.5h7l2 2.5h9v10H3z"/><path d="M3 7.5V5h7l2 2.5"/><path d="M8 12v5m4-5v3m4-3v5"/>',
    'results' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M8 12l2.2 2.2L15.5 9M8 18h8"/>',
    'history' => '<path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5m4-1v5l3 2"/>',
    'notifications' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
    'profile' => '<circle cx="10" cy="8" r="3.5"/><path d="M3.5 20a6.5 6.5 0 0 1 13 0M19 8v6m-3-3h6"/>',
    'logout' => '<path d="M10 17l5-5-5-5m5 5H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>',
];
$sidebarSections = [
    ['label' => 'Main', 'items' => ['overview' => ['dashboard', 'Dashboard']]],
    ['label' => 'Capstone Management', 'items' => ['proposal' => ['project', 'My Project'], 'results' => ['results', 'Evaluation Results'], 'history' => ['history', 'Submission History']]],
    ['label' => 'Reports & Activity', 'items' => ['notifications' => ['notifications', 'Notifications']]],
    ['label' => 'Account', 'items' => ['profile' => ['profile', 'Profile Settings']]],
];
?>
<aside class="student-sidebar" id="student-sidebar">
  <div class="student-brand-row">
    <a class="student-brand" href="student_dashboard.php?view=overview" aria-label="Capstone Evaluation System student dashboard">
      <span class="student-brand-mark" aria-hidden="true">CE</span>
      <span class="student-brand-copy"><strong>CAPSTONE EVALUATION SYSTEM</strong><small>STUDENT PORTAL</small></span>
    </a>
    <button class="student-sidebar-toggle" type="button" aria-label="Collapse sidebar" aria-controls="student-nav" aria-expanded="true" title="Collapse sidebar">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 18-6-6 6-6"/></svg>
    </button>
  </div>
  <nav class="student-nav" id="student-nav" aria-label="Student navigation">
    <?php foreach ($sidebarSections as $section): ?>
      <div class="student-nav-section">
        <div class="student-nav-label"><?= esc($section['label']) ?></div>
        <?php foreach ($section['items'] as $key => [$icon, $label]): ?>
          <a class="<?= $sidebarActiveView === $key ? 'active' : '' ?>" href="student_dashboard.php?view=<?= esc($key) ?>" title="<?= esc($label) ?>" <?= $sidebarActiveView === $key ? 'aria-current="page"' : '' ?>>
            <svg class="student-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $sidebarIcons[$icon] ?></svg>
            <span class="student-nav-text"><?= esc($label) ?></span>
            <?php if ($key === 'notifications' && $unreadNotificationCount > 0): ?><span class="student-nav-badge"><?=esc($unreadNotificationCount)?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="student-sidebar-footer">
    <a href="../logout.php" title="Log out">
      <svg class="student-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $sidebarIcons['logout'] ?></svg>
      <span class="student-nav-text">Log out</span>
    </a>
  </div>
  <div class="student-sidebar-user" title="<?= esc($sidebarName . ' · ' . $sidebarEmail) ?>">
    <div class="student-sidebar-avatar" aria-hidden="true"><?= esc($sidebarInitial) ?></div>
    <div class="student-sidebar-user-info"><strong><?= esc($sidebarName) ?></strong><small><?= esc($sidebarEmail) ?></small></div>
  </div>
</aside>
<script>
(() => {
  const sidebar = document.getElementById('student-sidebar');
  const toggle = sidebar?.querySelector('.student-sidebar-toggle');
  if (!sidebar || !toggle) return;

  const narrowDesktop = window.matchMedia('(min-width: 681px) and (max-width: 850px)').matches;
  let savedState = null;
  try {
    savedState = localStorage.getItem('student-sidebar-state');
  } catch (error) {
    savedState = null;
  }

  const setCollapsed = (collapsed, persist = false) => {
    sidebar.classList.toggle('is-collapsed', collapsed);
    toggle.setAttribute('aria-expanded', String(!collapsed));
    toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    toggle.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    if (persist) {
      try {
        localStorage.setItem('student-sidebar-state', collapsed ? 'collapsed' : 'expanded');
      } catch (error) {
        return;
      }
    }
  };

  setCollapsed(savedState ? savedState === 'collapsed' : narrowDesktop);
  toggle.addEventListener('click', () => setCollapsed(!sidebar.classList.contains('is-collapsed'), true));
})();
</script>
