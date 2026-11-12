<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_dean_access();

$userFormErrors = [];
$activeUserForm = 'add';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
  $activeUserForm = 'edit';
  $userId = (int) ($_POST['user_id'] ?? 0);
  $name = trim((string) ($_POST['full_name'] ?? ''));
  $email = trim((string) ($_POST['email'] ?? ''));
  $accountId = trim((string) ($_POST['account_id'] ?? ''));
  $department = trim((string) ($_POST['department'] ?? ''));
  if (!csrf_check($_POST['csrf'] ?? '')) $userFormErrors[] = 'Your session expired. Refresh the page and try again.';
  if ($userId < 1) $userFormErrors[] = 'Select a valid account to edit.';
  if ($name === '' || strlen($name) > 200) $userFormErrors[] = 'Enter a name up to 200 characters.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $userFormErrors[] = 'Enter a valid email address.';
  if (!$userFormErrors) {
    $roleStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleStmt->execute([$userId]);
    $existingRole = $roleStmt->fetchColumn();
    if (!$existingRole) {
      $userFormErrors[] = 'The selected account could not be found.';
    } else {
      $idColumn = $existingRole === 'student' ? 'student_id_number' : 'employee_id_number';
      try {
        $updateStmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, department = ?, ' . $idColumn . ' = ? WHERE id = ?');
        $updateStmt->execute([$name, $email, $department ?: null, $accountId ?: null, $userId]);
        header('Location: users.php?embedded=1'); exit;
      } catch (PDOException $exception) {
        $userFormErrors[] = 'The account could not be updated. Check that the email and ID number are not already in use.';
      }
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action']==='add') {
  $activeUserForm = 'add';
  if (!csrf_check($_POST['csrf'] ?? '')) { $userFormErrors[] = 'Your session expired. Refresh the page and try again.'; }
  $name = trim((string) ($_POST['full_name'] ?? ''));
  $email = trim((string) ($_POST['email'] ?? ''));
  $role = $_POST['role'] ?? 'evaluator';
  $schoolId = trim((string) ($_POST['school_id'] ?? ''));
  $department = trim((string) ($_POST['department'] ?? ''));
  $password = (string) ($_POST['password'] ?? '');
  if (!in_array($role, ['dean', 'evaluator', 'student'], true)) $userFormErrors[] = 'Select a valid account role.';
  if ($name === '' || strlen($name) > 200) $userFormErrors[] = 'Enter a name up to 200 characters.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $userFormErrors[] = 'Enter a valid email address.';
  if ($password === '') $userFormErrors[] = 'Enter a password for the new account.';
  if (!$userFormErrors) {
    $idColumn = $role === 'student' ? 'student_id_number' : 'employee_id_number';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
      $stmt = $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role,' . $idColumn . ',department) VALUES (?,?,?,?,?,?)');
      $stmt->execute([$name, $email, $hash, $role, $schoolId ?: null, $department ?: null]);
      header('Location: users.php?embedded=1'); exit;
    } catch (PDOException $exception) {
      $userFormErrors[] = 'The account could not be created. Check that the email and ID number are not already in use.';
    }
  }
}

$users = $pdo->query('SELECT id,full_name,email,role,student_id_number,group_name,employee_id_number,department FROM users ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$departments = array_values(array_unique(array_filter(array_map(static fn($user) => trim((string) ($user['department'] ?? '')), $users))));
sort($departments);
?>
<!doctype html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manage Users</title>
<link rel="stylesheet" href="../assets/style.css?v=20261112">
<link rel="icon" type="image/png" href="../favicon.png?v=20261016">
<script defer src="../assets/dean-table.js"></script>
</head><body class="dean-module-page dean-users-page">
  <div class="dean-module-heading"><div><h2>Manage Users</h2><p>Manage system accounts and their roles.</p></div><button class="btn primary" type="button" id="open-user-form">+ Add User</button></div>
  <?php foreach ($userFormErrors as $userFormError): ?><div class="dean-form-alert" role="alert"><?=esc($userFormError)?></div><?php endforeach; ?>
  <section class="dean-table-panel" data-dean-table>
    <div class="dean-table-toolbar"><label class="dean-search-field">Search<input type="search" data-table-search placeholder="Search name, email or ID"></label><label>Role<select data-table-filter="role"><option value="">All roles</option><option value="dean">Dean</option><option value="evaluator">Evaluator</option><option value="student">Student</option></select></label><label>Department<select data-table-filter="department"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?=esc($department)?>"><?=esc($department)?></option><?php endforeach; ?></select></label></div>
    <div class="dean-table-scroll"><table class="dean-data-table"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Account ID</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($users as $user): $accountId = $user['student_id_number'] ?: ($user['employee_id_number'] ?? ''); ?>
        <tr data-table-row data-role="<?=esc(strtolower($user['role']))?>" data-department="<?=esc(strtolower((string) $user['department']))?>" data-search="<?=esc(strtolower(implode(' ', [$user['id'], $user['full_name'], $user['email'], $user['role'], $user['department'], $accountId, $user['group_name']])))?>"><td><?=esc($user['id'])?></td><td><strong><?=esc($user['full_name'])?></strong></td><td><?=esc($user['email'])?></td><td><span class="role-label role-<?=esc($user['role'])?>"><?=esc(ucfirst($user['role']))?></span></td><td><?=esc($user['department'] ?: 'Not set')?></td><td><?=esc($accountId ?: 'Not set')?></td><td><div class="dean-row-actions"><button class="dean-action-button" type="button" data-user-view data-name="<?=esc($user['full_name'])?>" data-email="<?=esc($user['email'])?>" data-role-label="<?=esc(ucfirst($user['role']))?>" data-department="<?=esc($user['department'] ?: 'Not set')?>" data-account-id="<?=esc($accountId ?: 'Not set')?>" data-group="<?=esc($user['group_name'] ?: 'Not set')?>">View</button><button class="dean-action-button" type="button" data-user-edit data-id="<?=esc($user['id'])?>" data-name="<?=esc($user['full_name'])?>" data-email="<?=esc($user['email'])?>" data-role-label="<?=esc(ucfirst($user['role']))?>" data-department="<?=esc($user['department'] ?: '')?>" data-account-id="<?=esc($accountId ?: '')?>">Edit</button></div></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <div class="dean-table-footer"><span data-table-count></span><label>Rows<select data-page-size><option value="10">10</option><option value="25">25</option><option value="50">50</option></select></label><nav data-table-pagination aria-label="User table pages"><button type="button" data-page-prev>Previous</button><span data-page-indicator></span><button type="button" data-page-next>Next</button></nav></div>
    <div class="dean-table-empty" data-table-empty hidden>No users match the current search and filters.</div>
  </section>
  <dialog class="dean-modal" id="user-form-modal"><form method="post" class="dean-modal-form"><div class="dean-modal-heading"><div><h2>Add User</h2><p>Create a new portal account.</p></div><button type="button" class="dean-modal-close" data-modal-close aria-label="Close">&times;</button></div><input type="hidden" name="action" value="add"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><label>Full Name<input name="full_name" maxlength="200" value="<?=esc($_POST['full_name'] ?? '')?>" required></label><label>Email<input name="email" type="email" value="<?=esc($_POST['email'] ?? '')?>" required></label><label>Password<input name="password" type="password" autocomplete="new-password" required></label><label>Role<select name="role"><option value="dean">Dean</option><option value="evaluator" selected>Evaluator</option><option value="student">Student</option></select></label><label>CSUCC ID Number<input name="school_id" value="<?=esc($_POST['school_id'] ?? '')?>"></label><label>Department<input name="department" value="<?=esc($_POST['department'] ?? '')?>"></label><button class="btn primary" type="submit">Create User</button></form></dialog>
  <dialog class="dean-modal" id="user-edit-modal"><form method="post" class="dean-modal-form"><div class="dean-modal-heading"><div><h2>Edit User</h2><p>Update account identity details.</p></div><button type="button" class="dean-modal-close" data-modal-close aria-label="Close">&times;</button></div><input type="hidden" name="action" value="edit"><input type="hidden" name="csrf" value="<?=esc(csrf_token())?>"><input type="hidden" name="user_id" id="edit-user-id" value="<?=esc($_POST['user_id'] ?? '')?>"><label>Full Name<input name="full_name" id="edit-user-name" maxlength="200" value="<?=esc($_POST['full_name'] ?? '')?>" required></label><label>Email<input name="email" id="edit-user-email" type="email" value="<?=esc($_POST['email'] ?? '')?>" required></label><p class="dean-readonly-note">Role is managed separately.</p><label>CSUCC ID Number<input name="account_id" id="edit-user-account" value="<?=esc($_POST['account_id'] ?? '')?>"></label><label>Department<input name="department" id="edit-user-department" value="<?=esc($_POST['department'] ?? '')?>"></label><button class="btn primary" type="submit">Save Changes</button></form></dialog>
  <dialog class="dean-modal dean-user-details" id="user-details-modal"><div class="dean-modal-heading"><div><h2 id="user-details-name">User details</h2><p>Account information</p></div><button type="button" class="dean-modal-close" data-modal-close aria-label="Close">&times;</button></div><dl><div><dt>Email</dt><dd id="user-details-email"></dd></div><div><dt>Role</dt><dd id="user-details-role"></dd></div><div><dt>Department</dt><dd id="user-details-department"></dd></div><div><dt>CSUCC ID Number</dt><dd id="user-details-account"></dd></div><div><dt>Group</dt><dd id="user-details-group"></dd></div></dl></dialog>
  <script>
    const userFormModal = document.getElementById('user-form-modal');
    const userEditModal = document.getElementById('user-edit-modal');
    const userDetailsModal = document.getElementById('user-details-modal');
    document.getElementById('open-user-form').addEventListener('click', () => userFormModal.showModal());
    document.querySelectorAll('[data-modal-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('[data-user-view]').forEach(button => button.addEventListener('click', () => {
      document.getElementById('user-details-name').textContent = button.dataset.name;
      document.getElementById('user-details-email').textContent = button.dataset.email;
      document.getElementById('user-details-role').textContent = button.dataset.roleLabel;
      document.getElementById('user-details-department').textContent = button.dataset.department;
      document.getElementById('user-details-account').textContent = button.dataset.accountId;
      document.getElementById('user-details-group').textContent = button.dataset.group;
      userDetailsModal.showModal();
    }));
    document.querySelectorAll('[data-user-edit]').forEach(button => button.addEventListener('click', () => {
      document.getElementById('edit-user-id').value = button.dataset.id;
      document.getElementById('edit-user-name').value = button.dataset.name;
      document.getElementById('edit-user-email').value = button.dataset.email;
      document.getElementById('edit-user-account').value = button.dataset.accountId;
      document.getElementById('edit-user-department').value = button.dataset.department;
      userEditModal.showModal();
    }));
    <?php if ($userFormErrors): ?>document.getElementById('user-<?=esc($activeUserForm)?>-modal').showModal();<?php endif; ?>
  </script>
</body></html>
