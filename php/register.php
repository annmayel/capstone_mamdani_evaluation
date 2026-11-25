<?php

require_once __DIR__ . '/../config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
	session_set_cookie_params([
		'httponly' => true,
		'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
		'samesite' => 'Lax',
	]);
	session_start();
}

$errors = [];
$fieldErrors = [];
$studentIdNumber = strtoupper(trim((string) ($_POST['student_id_number'] ?? '')));
$groupName = trim((string) ($_POST['group_name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$department = trim((string) ($_POST['department'] ?? 'Information Technology (IT)'));

if (!empty($_SESSION['user'])) {
	$destinations = [
		'dean' => '../dean/dashboard.php',
		'evaluator' => '../evaluator/dashboard.php',
		'student' => '../students/student_dashboard.php',
		'adviser' => '../adviser/dashboard.php',
	];
	$redirect = $destinations[$_SESSION['user']['role'] ?? ''] ?? '../index.php';
	header('Location: ' . $redirect);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_check($_POST['csrf'] ?? '')) {
		$errors[] = 'Your session expired. Refresh the page and try again.';
	} else {
		$studentIdNumber = strtoupper(trim((string) ($_POST['student_id_number'] ?? '')));
		$groupName = trim((string) ($_POST['group_name'] ?? ''));
		$email = strtolower(trim((string) ($_POST['email'] ?? '')));
		$department = trim((string) ($_POST['department'] ?? ''));
		$password = (string) ($_POST['password'] ?? '');
		$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

		if (!preg_match('/^[A-Z0-9][A-Z0-9-]{2,49}$/', $studentIdNumber)) {
			$fieldErrors['student_id_number'] = 'Enter a valid Student ID Number using at least 3 letters, numbers, or hyphens.';
		}
		if ($groupName === '' || strlen($groupName) > 200) {
			$fieldErrors['group_name'] = 'Enter a Group Name (up to 200 characters).';
		}
		if ($email === '' || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$fieldErrors['email'] = 'Enter a valid email address.';
		} elseif (!is_csucc_email($email)) {
			$fieldErrors['email'] = 'Only @csucc.edu.ph email addresses can register.';
		}
		if (strlen($department) > 100) {
			$fieldErrors['department'] = 'Department / Program must be 100 characters or fewer.';
		}
		$passwordLength = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
		if ($passwordLength < 8) {
			$fieldErrors['password'] = 'Password must be at least 8 characters long.';
		}
		if ($confirmPassword === '') {
			$fieldErrors['confirm_password'] = 'Please confirm your password.';
		} elseif ($password !== $confirmPassword) {
			$fieldErrors['confirm_password'] = 'Passwords do not match.';
		}

		if (!$fieldErrors) {
			$emailQuery = $pdo->prepare('SELECT id, email, password_hash, role, student_id_number, student_approved_at FROM users WHERE email = ? LIMIT 1');
			$emailQuery->execute([$email]);
			$existingEmail = $emailQuery->fetch(PDO::FETCH_ASSOC) ?: null;

			$idQuery = $pdo->prepare('SELECT id, email, password_hash, role, student_id_number, student_approved_at FROM users WHERE student_id_number = ? LIMIT 1');
			$idQuery->execute([$studentIdNumber]);
			$existingStudentId = $idQuery->fetch(PDO::FETCH_ASSOC) ?: null;

			$pendingAccount = null;
			if ($existingEmail || $existingStudentId) {
				$bothMatch = $existingEmail
					&& $existingStudentId
					&& (int) $existingEmail['id'] === (int) $existingStudentId['id']
					&& $existingEmail['role'] === 'student'
					&& $existingEmail['student_approved_at'] === null
					&& hash_equals(strtolower((string) $existingEmail['email']), $email)
					&& hash_equals((string) $existingEmail['student_id_number'], $studentIdNumber)
					&& password_verify($password, (string) $existingEmail['password_hash']);

				if ($bothMatch) {
					$pendingAccount = $existingEmail;
					$pdo->prepare('UPDATE users SET group_name = ?, department = ? WHERE id = ? AND role = \'student\' AND student_approved_at IS NULL')
						->execute([$groupName, $department ?: null, (int) $pendingAccount['id']]);
				} else {
					if ($existingEmail) {
						$fieldErrors['email'] = 'An account with this email already exists. If the student account is still pending approval, use the same Student ID and password.';
					}
					if ($existingStudentId) {
						$fieldErrors['student_id_number'] = 'An account with this Student ID Number already exists.';
					}
				}
			}

			if (!$fieldErrors) {
				try {
					if (!$pendingAccount) {
						$accountId = generate_account_id($pdo, 'student');
						$hash = password_hash($password, PASSWORD_DEFAULT);
						$insert = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, account_id, department, can_evaluate, student_id_number, group_name) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)');
						$insert->execute([
							'Student ' . $studentIdNumber,
							$email,
							$hash,
							'student',
							$accountId,
							$department ?: null,
							$studentIdNumber,
							$groupName,
						]);
					}

					$_SESSION['approval_pending_notice'] = 'Registration is complete. Your student account is waiting for Chairperson approval before you can sign in.';
					header('Location: ../index.php?approval_pending=1');
					exit;
				} catch (PDOException $exception) {
					if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
						$message = strtolower((string) $exception->getMessage());
						$fieldErrors[str_contains($message, 'student_id_number') ? 'student_id_number' : 'email'] =
							str_contains($message, 'student_id_number')
								? 'An account with this Student ID Number already exists.'
								: 'An account with this email already exists.';
					} else {
						error_log('[registration] Could not create pending student account: ' . $exception->getMessage());
						$errors[] = 'We could not save your registration. Please try again later.';
					}
				}
			}
		}
	}
}

$requestPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$assetPrefix = str_contains($requestPath, '/php/') ? '../' : '';
$firstInvalidField = (string) array_key_first($fieldErrors);
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Create Account | Fuzzy Score</title>
	<link rel="stylesheet" href="<?= $assetPrefix ?>assets/style.css?v=20261119-registration">
	<link rel="icon" type="image/png" href="<?= $assetPrefix ?>images_bgnd/favicon.png">
</head>
<body class="register-page">
	<header class="register-header">
		<a class="register-brand" href="<?= $assetPrefix ?>index.php" aria-label="Back to home">
			<img class="register-brand-mark" src="<?= $assetPrefix ?>images_bgnd/CSUCC%20LOGO.png" alt="">
			<span><strong>CAPSTONE EVALUATION SYSTEM</strong><small>BSIT CAPSTONE PORTAL</small></span>
		</a>
		<nav class="register-nav" aria-label="Register navigation">
			<a href="<?= $assetPrefix ?>index.php#about">About</a>
			<a href="<?= $assetPrefix ?>index.php#system">System</a>
			<a href="<?= $assetPrefix ?>index.php#contact">Contact</a>
			<a class="register-login-link" href="<?= $assetPrefix ?>index.php#login">Log in <span aria-hidden="true">→</span></a>
		</nav>
	</header>

	<main class="register-main">
		<section class="register-card" aria-labelledby="register-title">
			<div class="register-heading">
				<div class="register-heading-icon" aria-hidden="true">+</div>
				<div>
					<p class="register-overline">STUDENT ACCOUNT REGISTRATION</p>
					<h1 id="register-title">Create your account</h1>
					<p>Email confirmation is not required. Chairperson approval is required before you can sign in.</p>
				</div>
			</div>

			<?php if ($errors): ?>
				<div class="register-alert" role="alert">
					<?php foreach ($errors as $error): ?><div><?= esc($error) ?></div><?php endforeach; ?>
				</div>
			<?php endif; ?>
			<form class="register-form" method="post" novalidate>
				<input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
				<div class="register-column">
					<label for="student_id_number">Student ID Number <small>Required</small></label>
					<input id="student_id_number" name="student_id_number" type="text" value="<?= esc($studentIdNumber) ?>" placeholder="Enter your student ID number" maxlength="50" autocomplete="off" pattern="[A-Za-z0-9][A-Za-z0-9-]{2,49}" aria-describedby="student-id-feedback" aria-invalid="<?= isset($fieldErrors['student_id_number']) ? 'true' : 'false' ?>" <?= isset($fieldErrors['student_id_number']) ? 'class="is-invalid" data-server-error="1"' : '' ?> required>
					<p class="register-field-feedback <?= isset($fieldErrors['student_id_number']) ? 'is-invalid' : '' ?>" id="student-id-feedback" aria-live="polite"><?= esc($fieldErrors['student_id_number'] ?? 'Use at least 3 letters, numbers, or hyphens.') ?></p>

					<label for="group_name">Group Name <small>Required</small></label>
					<input id="group_name" name="group_name" type="text" value="<?= esc($groupName) ?>" placeholder="Enter your group name" maxlength="200" autocomplete="off" aria-describedby="group-name-feedback" aria-invalid="<?= isset($fieldErrors['group_name']) ? 'true' : 'false' ?>" <?= isset($fieldErrors['group_name']) ? 'class="is-invalid" data-server-error="1"' : '' ?> required>
					<p class="register-field-feedback <?= isset($fieldErrors['group_name']) ? 'is-invalid' : '' ?>" id="group-name-feedback" aria-live="polite"><?= esc($fieldErrors['group_name'] ?? 'Enter the name your capstone group will use.') ?></p>

					<label for="email">Email address <small>Required</small></label>
					<input id="email" name="email" type="email" value="<?= esc($email) ?>" autocomplete="email" aria-describedby="email-feedback" aria-invalid="<?= isset($fieldErrors['email']) ? 'true' : 'false' ?>" <?= isset($fieldErrors['email']) ? 'class="is-invalid" data-server-error="1"' : '' ?> required>
					<p class="register-field-feedback <?= isset($fieldErrors['email']) ? 'is-invalid' : '' ?>" id="email-feedback" aria-live="polite"><?= esc($fieldErrors['email'] ?? 'Use your CSUCC institutional email (name@csucc.edu.ph).') ?></p>

					<label for="department">Department / Program</label>
					<input id="department" name="department" type="text" maxlength="100" value="<?= esc($department) ?>" aria-describedby="department-feedback" aria-invalid="<?= isset($fieldErrors['department']) ? 'true' : 'false' ?>" <?= isset($fieldErrors['department']) ? 'class="is-invalid" data-server-error="1"' : '' ?>>
					<p class="register-field-feedback <?= isset($fieldErrors['department']) ? 'is-invalid' : '' ?>" id="department-feedback" aria-live="polite"><?= esc($fieldErrors['department'] ?? 'Optional; up to 100 characters.') ?></p>
				</div>

				<div class="register-column">
					<label for="role-display">Role</label>
					<input id="role-display" type="text" value="Student" readonly aria-readonly="true">

					<label for="password">Password <small>Min 8 characters</small></label>
					<div class="password-field">
						<input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-feedback" aria-invalid="<?= isset($fieldErrors['password']) ? 'true' : 'false' ?>" <?= isset($fieldErrors['password']) ? 'class="is-invalid" data-server-error="1"' : '' ?> required>
						<button class="password-toggle" id="password-toggle" type="button" aria-label="Show passwords" aria-controls="password confirm_password" aria-pressed="false">Show</button>
					</div>
					<p class="register-field-feedback <?= isset($fieldErrors['password']) ? 'is-invalid' : '' ?>" id="password-feedback" aria-live="polite"><?= esc($fieldErrors['password'] ?? 'Use at least 8 characters.') ?></p>

					<label for="confirm_password">Confirm password <small>Required</small></label>
					<input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" aria-describedby="confirm-password-feedback" aria-invalid="<?= isset($fieldErrors['confirm_password']) ? 'true' : 'false' ?>" <?= isset($fieldErrors['confirm_password']) ? 'class="is-invalid" data-server-error="1"' : '' ?> required>
					<p class="register-field-feedback <?= isset($fieldErrors['confirm_password']) ? 'is-invalid' : '' ?>" id="confirm-password-feedback" aria-live="polite"><?= esc($fieldErrors['confirm_password'] ?? 'Enter the same password again to confirm.') ?></p>
				</div>

				<div class="register-actions">
					<button class="create-account-button" type="submit">Create Account</button>
					<a class="back-login-button" href="<?= $assetPrefix ?>index.php#login">Back to login</a>
				</div>
			</form>
		</section>
	</main>

	<script>
		(() => {
			const form = document.querySelector('.register-form');
			const studentId = document.getElementById('student_id_number');
			const groupName = document.getElementById('group_name');
			const email = document.getElementById('email');
			const department = document.getElementById('department');
			const password = document.getElementById('password');
			const confirmPassword = document.getElementById('confirm_password');
			const passwordToggle = document.getElementById('password-toggle');
			const firstInvalidField = <?= json_encode($firstInvalidField) ?>;
			let submitted = <?= $fieldErrors ? 'true' : 'false' ?>;
			let confirmAttempted = submitted;

			function setFeedback(input, message, valid, neutral = false) {
				const feedback = document.getElementById(input.getAttribute('aria-describedby'));
				if (!feedback) return valid;
				input.classList.remove('is-valid', 'is-invalid');
				feedback.classList.remove('is-valid', 'is-invalid');
				if (neutral) {
					input.setAttribute('aria-invalid', 'false');
					return true;
				}
				input.classList.add(valid ? 'is-valid' : 'is-invalid');
				input.setAttribute('aria-invalid', String(!valid));
				feedback.classList.add(valid ? 'is-valid' : 'is-invalid');
				feedback.textContent = message;
				return valid;
			}

			function validateStudentId(showError = submitted) {
				const valid = /^[A-Z0-9][A-Z0-9-]{2,49}$/.test(studentId.value.trim().toUpperCase());
				if (!showError && !studentId.value.trim()) return setFeedback(studentId, '', false, true);
				return setFeedback(studentId, valid ? 'Student ID format looks good.' : 'Enter at least 3 letters, numbers, or hyphens.', valid);
			}

			function validateGroupName(showError = submitted) {
				const value = groupName.value.trim();
				const valid = value !== '' && value.length <= 200;
				if (!showError && value === '') return setFeedback(groupName, '', false, true);
				return setFeedback(groupName, valid ? 'Group name is ready.' : 'Enter a group name (up to 200 characters).', valid);
			}

			function validateEmail(showError = submitted) {
				const value = email.value.trim();
				const candidate = document.createElement('input');
				candidate.type = 'email';
				candidate.value = value;
				const validFormat = value !== '' && candidate.checkValidity();
				const institutional = validFormat && value.slice(value.lastIndexOf('@') + 1).toLowerCase() === 'csucc.edu.ph';
				if (!showError && value === '') return setFeedback(email, '', false, true);
				const message = !validFormat ? 'Enter a valid email address.' : (!institutional ? 'Only @csucc.edu.ph email addresses can register.' : 'This CSUCC email address is eligible to register.');
				return setFeedback(email, message, institutional);
			}

			function validateDepartment() {
				const valid = department.value.length <= 100;
				if (!department.value) return setFeedback(department, 'Optional; up to 100 characters.', true);
				return setFeedback(department, valid ? 'Department / Program is ready.' : 'Department / Program must be 100 characters or fewer.', valid);
			}

			function validatePassword(showError = submitted) {
				const length = [...password.value].length;
				const valid = length >= 8;
				if (!showError && password.value === '') return setFeedback(password, '', false, true);
				return setFeedback(password, valid ? 'Password length is valid.' : 'Password must be at least 8 characters long.', valid);
			}

			function validateConfirmation(showError = confirmAttempted) {
				if (confirmPassword.value === '' && !showError) return setFeedback(confirmPassword, 'Enter the same password again to confirm.', false, true);
				const valid = confirmPassword.value !== '' && confirmPassword.value === password.value;
				const message = confirmPassword.value === '' ? 'Please confirm your password.' : (valid ? 'Passwords match.' : 'Passwords do not match.');
				return setFeedback(confirmPassword, message, valid);
			}

			[studentId, groupName, email, department, password, confirmPassword].forEach((input) => {
				input.addEventListener('input', () => {
					delete input.dataset.serverError;
					if (input === studentId) validateStudentId();
					if (input === groupName) validateGroupName();
					if (input === email) validateEmail();
					if (input === department) validateDepartment();
					if (input === password) {
						validatePassword();
						if (confirmAttempted || confirmPassword.value !== '') validateConfirmation(true);
					}
					if (input === confirmPassword) validateConfirmation(true);
				});
			});

			studentId.addEventListener('blur', () => {
				if (studentId.dataset.serverError) return;
				studentId.value = studentId.value.trim().toUpperCase();
				validateStudentId();
			});
			groupName.addEventListener('blur', () => {
				if (groupName.dataset.serverError) return;
				groupName.value = groupName.value.trim();
				validateGroupName();
			});
			email.addEventListener('blur', () => {
				if (email.dataset.serverError) return;
				email.value = email.value.trim();
				validateEmail();
			});

			passwordToggle.addEventListener('click', () => {
				const showPasswords = password.type === 'password';
				password.type = showPasswords ? 'text' : 'password';
				confirmPassword.type = showPasswords ? 'text' : 'password';
				passwordToggle.textContent = showPasswords ? 'Hide' : 'Show';
				passwordToggle.setAttribute('aria-label', showPasswords ? 'Hide passwords' : 'Show passwords');
				passwordToggle.setAttribute('aria-pressed', String(showPasswords));
			});

			form.addEventListener('submit', (event) => {
				submitted = true;
				confirmAttempted = true;
				studentId.value = studentId.value.trim().toUpperCase();
				groupName.value = groupName.value.trim();
				email.value = email.value.trim();
				const valid = [
					validateStudentId(true),
					validateGroupName(true),
					validateEmail(true),
					validateDepartment(),
					validatePassword(true),
					validateConfirmation(true),
				].every(Boolean);
				if (!valid) {
					event.preventDefault();
					const firstInvalid = form.querySelector('[aria-invalid="true"]');
					firstInvalid?.focus();
				}
			});

			[studentId, groupName, email, department, password, confirmPassword].forEach((input) => {
				if (input.dataset.serverError) return;
				if (input.value !== '') {
					if (input === studentId) validateStudentId();
					if (input === groupName) validateGroupName();
					if (input === email) validateEmail();
					if (input === department) validateDepartment();
					if (input === password) validatePassword();
					if (input === confirmPassword) validateConfirmation();
				}
			});
			if (firstInvalidField) document.getElementById(firstInvalidField)?.focus();

		})();
	</script>
</body>
</html>
