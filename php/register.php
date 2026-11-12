<?php

require_once __DIR__ . '/../config.php';

session_start();

if (!empty($_SESSION['user'])) {
	$redirect = $_SESSION['user']['role'] === 'dean'
		? '../dean/dashboard.php'
		: ($_SESSION['user']['role'] === 'evaluator' ? '../evaluator/dashboard.php' : '../students/student_dashboard.php');
	header('Location: ' . $redirect);
	exit;
}

$errors = [];
$submittedRole = (string) ($_POST['role'] ?? 'student');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$role = in_array($submittedRole, ['dean', 'evaluator', 'student'], true) ? $submittedRole : '';
	$studentIdNumber = strtoupper(trim((string) ($_POST['student_id_number'] ?? '')));
	$employeeIdNumber = strtoupper(trim((string) ($_POST['employee_id_number'] ?? '')));
	$groupName = trim((string) ($_POST['group_name'] ?? ''));
	$fullName = trim((string) ($_POST['full_name'] ?? ''));
	$email = trim(strtolower((string) ($_POST['email'] ?? '')));
	$department = trim($_POST['department'] ?? 'Information Technology (IT)');
	$password = (string) ($_POST['password'] ?? '');
	$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

	if (!csrf_check($_POST['csrf'] ?? '')) {
		$errors[] = 'Your session expired. Refresh the page and try again.';
	}

	if ($role === '') {
		$errors[] = 'Please select a valid account role.';
	} elseif ($role === 'student') {
		if (!preg_match('/^[A-Z0-9][A-Z0-9-]{2,49}$/', $studentIdNumber)) {
			$errors[] = 'Enter a valid Student ID Number using at least 3 letters, numbers, or hyphens.';
		}
		if ($groupName === '' || strlen($groupName) > 200) {
			$errors[] = 'Enter a Group Name (up to 200 characters).';
		}
		$fullName = 'Student ' . $studentIdNumber;
	} elseif (!preg_match('/^[A-Z0-9][A-Z0-9-]{2,49}$/', $employeeIdNumber)) {
		$errors[] = 'Enter a valid Employee ID Number using at least 3 letters, numbers, or hyphens.';
	} else {
		$fullName = 'Employee ' . $employeeIdNumber;
	}

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'Please provide a valid email address.';
	} elseif (!is_csucc_email($email)) {
		$errors[] = 'Only @csucc.edu.ph email addresses can register.';
	}

	if (strlen($password) < 8) {
		$errors[] = 'Password must be at least 8 characters long.';
	}

	if ($password !== $confirmPassword) {
		$errors[] = 'Passwords do not match.';
	}

	if (empty($errors)) {
		$existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
		$existing->execute([$email]);

		if ($existing->fetch()) {
			$errors[] = 'An account with this email already exists.';
		}
	}

	if (empty($errors) && $role === 'student') {
		$existingStudentId = $pdo->prepare('SELECT id FROM users WHERE student_id_number = ? LIMIT 1');
		$existingStudentId->execute([$studentIdNumber]);
		if ($existingStudentId->fetch()) {
			$errors[] = 'An account with this Student ID Number already exists.';
		}
	}

	if (empty($errors) && in_array($role, ['dean', 'evaluator'], true)) {
		$existingEmployeeId = $pdo->prepare('SELECT id FROM users WHERE employee_id_number = ? LIMIT 1');
		$existingEmployeeId->execute([$employeeIdNumber]);
		if ($existingEmployeeId->fetch()) {
			$errors[] = 'An account with this Employee ID Number already exists.';
		}
	}

	if (empty($errors)) {
		$accountId = generate_account_id($pdo, $role);
		$hash = password_hash($password, PASSWORD_DEFAULT);

		try {
			$stmt = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, account_id, department, can_evaluate, student_id_number, group_name, employee_id_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
			$stmt->execute([
				$fullName,
				$email,
				$hash,
				$role,
				$accountId,
				$department,
				$role === 'dean' || $role === 'evaluator' ? 1 : 0,
				$role === 'student' ? $studentIdNumber : null,
				$role === 'student' ? $groupName : null,
				in_array($role, ['dean', 'evaluator'], true) ? $employeeIdNumber : null,
			]);
		} catch (PDOException $exception) {
			if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
				$duplicateMessage = (string) $exception->getMessage();
				$errors[] = (str_contains($duplicateMessage, 'employee_id_number') || str_contains($duplicateMessage, 'unique_employee_id_number'))
					? 'An account with this Employee ID Number already exists.'
					: ((str_contains($duplicateMessage, 'student_id_number') || str_contains($duplicateMessage, 'unique_student_id_number'))
						? 'An account with this Student ID Number already exists.'
						: 'An account with this email already exists.');
			} else {
				throw $exception;
			}
		}
	}

	if (empty($errors)) {
		require_once __DIR__ . '/email_notifications.php';
		$userId = (int) $pdo->lastInsertId();
		$registeredLoginId = $role === 'student' ? $studentIdNumber : $employeeIdNumber;
		notify_user_once($pdo, $userId, 'account-registered:' . $userId, 'Account registration received', 'Your Capstone Fuzzy account has been created. Sign in with your registered ID Number and password.', 'index.php');
		fuzzy_email_notify($pdo, [
			'event_key' => 'account_registered:' . $userId,
			'event_type' => 'account_registered',
			'recipient_user_id' => $userId,
			'recipient_email' => $email,
			'recipient_name' => $fullName,
			'subject' => 'Your Capstone Fuzzy account is registered',
			'heading' => 'Account registration received',
			'intro' => 'Your Capstone Fuzzy Portal account has been created. Sign in with your registered ID Number and password.',
			'sections' => [[
				'title' => 'Account details',
				'rows' => [['Role', ucfirst($role)], ['Account ID', $accountId]],
			]],
			'portal_path' => '/index.php',
		]);

		$_SESSION['registration_success'] = ['login_id' => $registeredLoginId];
		$requestPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
		$loginPage = str_contains($requestPath, '/php/') ? '../index.php' : 'index.php';
		header('Location: ' . $loginPage . '?registered=1');
		exit;
	}
}
$requestPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$assetPrefix = str_contains($requestPath, '/php/') ? '../' : '';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Create Account | Fuzzy Score</title>
	<link rel="stylesheet" href="<?= $assetPrefix ?>assets/style.css?v=20261021-register-style">
	<link rel="icon" type="image/png" href="<?= $assetPrefix ?>images_bgnd/favicon.png">
</head>
<body class="register-page">
	<header class="register-header">
		<a class="register-brand" href="index.php" aria-label="Back to home">
			<span class="register-brand-mark">F</span>
			<span><strong>CAPSTONE <b>FUZZY</b></strong><small>BSIT CAPSTONE PORTAL</small></span>
		</a>
		<nav class="register-nav" aria-label="Register navigation">
			<a href="index.php#about">About</a>
			<a href="index.php#system">System</a>
			<a href="index.php#contact">Contact</a>
			<a class="register-login-link" href="index.php#login">Log in <span aria-hidden="true">→</span></a>
		</nav>
	</header>

	<main class="register-main">
		<section class="register-card" aria-labelledby="register-title">
			<div class="register-heading">
				<div class="register-heading-icon" aria-hidden="true">+</div>
				<div>
					<p class="register-overline">ACCOUNT REGISTRATION</p>
					<h1 id="register-title">Create your account</h1>
					<p>Join the capstone evaluation system and begin using your assigned role.</p>
				</div>
			</div>

			<?php if (!empty($errors)): ?>
				<div class="register-alert" role="alert">
					<?php foreach ($errors as $error): ?>
						<div><?= esc($error) ?></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form class="register-form" method="post" novalidate>
				<input type="hidden" name="csrf" value="<?= esc(csrf_token()) ?>">
				<div class="register-column">
					<label for="student_id_number" <?= $submittedRole === 'student' ? '' : 'hidden' ?>>Student ID Number <small>Required</small></label>
					<input id="student_id_number" name="student_id_number" type="text" value="<?= esc($_POST['student_id_number'] ?? '') ?>" placeholder="Enter your student ID number" maxlength="50" autocomplete="off" pattern="[A-Za-z0-9][A-Za-z0-9-]{2,49}" aria-describedby="student-id-feedback" aria-invalid="false" <?= $submittedRole === 'student' ? 'required' : 'disabled hidden' ?>>
					<p class="register-field-feedback" id="student-id-feedback" aria-live="polite" <?= $submittedRole === 'student' ? '' : 'hidden' ?>>Use at least 3 letters, numbers, or hyphens.</p>

					<label for="employee_id_number" <?= in_array($submittedRole, ['dean', 'evaluator'], true) ? '' : 'hidden' ?>>Employee ID Number <small>Required</small></label>
					<input id="employee_id_number" name="employee_id_number" type="text" value="<?= esc($_POST['employee_id_number'] ?? '') ?>" placeholder="Enter your employee ID number" maxlength="50" autocomplete="off" pattern="[A-Za-z0-9][A-Za-z0-9-]{2,49}" aria-describedby="employee-id-feedback" aria-invalid="false" <?= in_array($submittedRole, ['dean', 'evaluator'], true) ? 'required' : 'disabled hidden' ?>>
					<p class="register-field-feedback" id="employee-id-feedback" aria-live="polite" <?= in_array($submittedRole, ['dean', 'evaluator'], true) ? '' : 'hidden' ?>>Use at least 3 letters, numbers, or hyphens.</p>

					<label for="group_name" <?= $submittedRole === 'student' ? '' : 'hidden' ?>>Group Name <small>Required</small></label>
					<input id="group_name" name="group_name" type="text" value="<?= esc($_POST['group_name'] ?? '') ?>" placeholder="Enter your group name" maxlength="200" autocomplete="off" aria-describedby="group-name-feedback" aria-invalid="false" <?= $submittedRole === 'student' ? 'required' : 'disabled hidden' ?>>
					<p class="register-field-feedback" id="group-name-feedback" aria-live="polite" <?= $submittedRole === 'student' ? '' : 'hidden' ?>>Enter the name your capstone group will use.</p>

					<label for="email">Email address <small>Required</small></label>
					<input id="email" name="email" type="email" value="<?= esc($_POST['email'] ?? '') ?>" autocomplete="email" aria-describedby="email-feedback" aria-invalid="false" required>
					<p class="register-field-feedback" id="email-feedback" aria-live="polite">Use your CSUCC institutional email (name@csucc.edu.ph).</p>

					<label for="department">Department / Program</label>
					<input id="department" name="department" type="text" value="<?= esc($_POST['department'] ?? 'Information Technology (IT)') ?>">
				</div>

				<div class="register-column">
					<label for="role">Role <small>Required</small></label>
					<select id="role" name="role" required>
						<option value="student" <?= (($_POST['role'] ?? 'student') === 'student') ? 'selected' : '' ?>>Student</option>
						<option value="evaluator" <?= (($_POST['role'] ?? 'student') === 'evaluator') ? 'selected' : '' ?>>Evaluator</option>
						<option value="dean" <?= (($_POST['role'] ?? 'student') === 'dean') ? 'selected' : '' ?>>Dean / IT Chairperson</option>
					</select>

					<label for="password">Password <small>Min 8 chars</small></label>
					<div class="password-field">
						<input id="password" name="password" type="password" autocomplete="new-password" required>
						<button class="password-toggle" id="password-toggle" type="button" aria-label="Show passwords" aria-controls="password confirm_password" aria-pressed="false">Show</button>
					</div>

					<label for="confirm_password">Confirm password <small>Required</small></label>
					<input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" aria-describedby="confirm-password-feedback" aria-invalid="false" required>
					<p class="register-field-feedback" id="confirm-password-feedback" aria-live="polite">Enter the same password again to confirm.</p>
				</div>

				<div class="register-actions" style="grid-column:1 / -1;">
					<button class="create-account-button" type="submit">Create Account</button>
					<a class="back-login-button" href="index.php#login">Back to login</a>
				</div>
			</form>
		</section>
	</main>
	<script>
		(() => {
			const form = document.querySelector('.register-form');
			const email = document.getElementById('email');
			const emailFeedback = document.getElementById('email-feedback');
			const password = document.getElementById('password');
			const confirmPassword = document.getElementById('confirm_password');
			const confirmFeedback = document.getElementById('confirm-password-feedback');
			const passwordToggle = document.getElementById('password-toggle');
			const role = document.getElementById('role');
			const studentIdNumber = document.getElementById('student_id_number');
			const studentIdFeedback = document.getElementById('student-id-feedback');
			const employeeIdNumber = document.getElementById('employee_id_number');
			const employeeIdFeedback = document.getElementById('employee-id-feedback');
			const groupName = document.getElementById('group_name');
			const groupNameFeedback = document.getElementById('group-name-feedback');
			let identityFieldsSubmitted = false;

			function setRoleFieldVisibility(label, input, visible) {
				label.hidden = !visible;
				input.hidden = !visible;
				input.disabled = !visible;
				input.required = visible;
			}

			function updateRoleFields() {
				const isStudent = role.value === 'student';
				const isStaff = role.value === 'dean' || role.value === 'evaluator';
				setRoleFieldVisibility(employeeIdNumber.previousElementSibling, employeeIdNumber, isStaff);
				setRoleFieldVisibility(studentIdNumber.previousElementSibling, studentIdNumber, isStudent);
				setRoleFieldVisibility(groupName.previousElementSibling, groupName, isStudent);
				studentIdFeedback.hidden = !isStudent;
				employeeIdFeedback.hidden = !isStaff;
				groupNameFeedback.hidden = !isStudent;
				if (!isStudent) {
					studentIdNumber.classList.remove('is-valid', 'is-invalid');
					studentIdNumber.setAttribute('aria-invalid', 'false');
					studentIdFeedback.classList.remove('is-valid', 'is-invalid');
				}
				if (!isStaff) {
					employeeIdNumber.classList.remove('is-valid', 'is-invalid');
					employeeIdNumber.setAttribute('aria-invalid', 'false');
					employeeIdFeedback.classList.remove('is-valid', 'is-invalid');
				} else {
					updateEmployeeIdFeedback();
				}
				if (!isStudent) {
					groupName.classList.remove('is-valid', 'is-invalid');
					groupName.setAttribute('aria-invalid', 'false');
					groupNameFeedback.classList.remove('is-valid', 'is-invalid');
				} else {
					updateStudentFieldsFeedback();
				}
			}

			function setIdentityFeedback(input, feedback, valid, message, invalidMessage, showInvalid, neutralMessage) {
				input.classList.remove('is-valid', 'is-invalid');
				feedback.classList.remove('is-valid', 'is-invalid');
				if (!showInvalid && input.value.trim() === '') {
					input.setAttribute('aria-invalid', 'false');
					feedback.textContent = neutralMessage;
					return true;
				}
				input.classList.add(valid ? 'is-valid' : 'is-invalid');
				input.setAttribute('aria-invalid', String(!valid));
				feedback.classList.add(valid ? 'is-valid' : 'is-invalid');
				feedback.textContent = valid ? message : invalidMessage;
				return valid;
			}

			function updateStudentFieldsFeedback(showInvalid = identityFieldsSubmitted) {
				const idValue = studentIdNumber.value.trim().toUpperCase();
				const groupValue = groupName.value.trim();
				const idValid = /^[A-Z0-9][A-Z0-9-]{2,49}$/.test(idValue);
				const groupValid = groupValue !== '' && groupValue.length <= 200;
				return {
					idValid: setIdentityFeedback(studentIdNumber, studentIdFeedback, idValid, 'Student ID format looks good.', 'Enter at least 3 letters, numbers, or hyphens.', showInvalid, 'Use at least 3 letters, numbers, or hyphens.'),
					groupValid: setIdentityFeedback(groupName, groupNameFeedback, groupValid, 'Group name is ready.', 'Enter a group name (up to 200 characters).', showInvalid, 'Enter the name your capstone group will use.'),
				};
			}
			function updateEmployeeIdFeedback(showInvalid = identityFieldsSubmitted) {
				const idValue = employeeIdNumber.value.trim().toUpperCase();
				const idValid = /^[A-Z0-9][A-Z0-9-]{2,49}$/.test(idValue);
				return setIdentityFeedback(employeeIdNumber, employeeIdFeedback, idValid, 'Employee ID format looks good.', 'Enter at least 3 letters, numbers, or hyphens.', showInvalid, 'Use at least 3 letters, numbers, or hyphens.');
			}
			let emailSubmitted = false;
			let confirmationAttempted = false;

			function updateEmailFeedback(submitted = emailSubmitted) {
				const value = email.value.trim();
				email.classList.remove('is-valid', 'is-invalid');

				if (value === '') {
					email.setAttribute('aria-invalid', submitted ? 'true' : 'false');
					emailFeedback.classList.remove('is-valid', 'is-invalid');
					emailFeedback.textContent = submitted
						? 'Please enter your institutional email address.'
						: 'Use your CSUCC institutional email (name@csucc.edu.ph).';
					return !submitted;
				}

				const candidate = document.createElement('input');
				candidate.type = 'email';
				candidate.value = value;

				if (!candidate.checkValidity()) {
					email.classList.add('is-invalid');
					email.setAttribute('aria-invalid', 'true');
					emailFeedback.classList.add('is-invalid');
					emailFeedback.classList.remove('is-valid');
					emailFeedback.textContent = 'Enter a valid email address.';
					return false;
				}

				const domain = value.slice(value.lastIndexOf('@') + 1).toLowerCase();
				if (domain !== 'csucc.edu.ph') {
					email.classList.add('is-invalid');
					email.setAttribute('aria-invalid', 'true');
					emailFeedback.classList.add('is-invalid');
					emailFeedback.classList.remove('is-valid');
					emailFeedback.textContent = 'Only @csucc.edu.ph email addresses can register.';
					return false;
				}

				email.classList.add('is-valid');
				email.setAttribute('aria-invalid', 'false');
				emailFeedback.classList.add('is-valid');
				emailFeedback.classList.remove('is-invalid');
				emailFeedback.textContent = 'This CSUCC email address is eligible to register.';
				return true;
			}

			function updateConfirmationFeedback(attempted = confirmationAttempted) {
				confirmPassword.classList.remove('is-valid', 'is-invalid');

				if (confirmPassword.value === '' && !attempted) {
					confirmPassword.setAttribute('aria-invalid', 'false');
					confirmFeedback.classList.remove('is-valid', 'is-invalid');
					confirmFeedback.textContent = 'Enter the same password again to confirm.';
					return true;
				}

				if (confirmPassword.value === '') {
					confirmPassword.classList.add('is-invalid');
					confirmPassword.setAttribute('aria-invalid', 'true');
					confirmFeedback.classList.add('is-invalid');
					confirmFeedback.classList.remove('is-valid');
					confirmFeedback.textContent = 'Please confirm your password.';
					return false;
				}

				if (confirmPassword.value !== password.value) {
					confirmPassword.classList.add('is-invalid');
					confirmPassword.setAttribute('aria-invalid', 'true');
					confirmFeedback.classList.add('is-invalid');
					confirmFeedback.classList.remove('is-valid');
					confirmFeedback.textContent = 'Passwords do not match.';
					return false;
				}

				confirmPassword.classList.add('is-valid');
				confirmPassword.setAttribute('aria-invalid', 'false');
				confirmFeedback.classList.add('is-valid');
				confirmFeedback.classList.remove('is-invalid');
				confirmFeedback.textContent = 'Passwords match.';
				return true;
			}

			email.addEventListener('input', () => updateEmailFeedback());
			role.addEventListener('change', updateRoleFields);
			studentIdNumber.addEventListener('input', () => updateStudentFieldsFeedback());
			employeeIdNumber.addEventListener('input', () => updateEmployeeIdFeedback());
			groupName.addEventListener('input', () => updateStudentFieldsFeedback());
			studentIdNumber.addEventListener('blur', () => {
				studentIdNumber.value = studentIdNumber.value.trim().toUpperCase();
				updateStudentFieldsFeedback();
			});
			employeeIdNumber.addEventListener('blur', () => {
				employeeIdNumber.value = employeeIdNumber.value.trim().toUpperCase();
				updateEmployeeIdFeedback();
			});
			groupName.addEventListener('blur', () => {
				groupName.value = groupName.value.trim();
				updateStudentFieldsFeedback();
			});
			email.addEventListener('blur', () => {
				email.value = email.value.trim();
				updateEmailFeedback();
			});
			confirmPassword.addEventListener('input', () => updateConfirmationFeedback());
			password.addEventListener('input', () => {
				if (confirmationAttempted || confirmPassword.value !== '') {
					updateConfirmationFeedback(true);
				}
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
				emailSubmitted = true;
				confirmationAttempted = true;
				identityFieldsSubmitted = true;
				email.value = email.value.trim();
				studentIdNumber.value = studentIdNumber.value.trim().toUpperCase();
				employeeIdNumber.value = employeeIdNumber.value.trim().toUpperCase();
				groupName.value = groupName.value.trim();

				const emailIsValid = updateEmailFeedback(true);
				const confirmationIsValid = updateConfirmationFeedback(true);
				const isStudent = role.value === 'student';
				const identityFieldsValid = isStudent ? updateStudentFieldsFeedback(true) : {idValid: true, groupValid: true};
				const employeeIdIsValid = isStudent ? true : updateEmployeeIdFeedback(true);

				if (!emailIsValid || !confirmationIsValid || !identityFieldsValid.idValid || !identityFieldsValid.groupValid || !employeeIdIsValid) {
					event.preventDefault();
					if (!emailIsValid) {
						email.focus();
					} else if (!isStudent && !employeeIdIsValid) {
						employeeIdNumber.focus();
					} else if (!identityFieldsValid.idValid) {
						studentIdNumber.focus();
					} else if (!identityFieldsValid.groupValid) {
						groupName.focus();
					} else {
						confirmPassword.focus();
					}
				}
			});

			if (email.value.trim() !== '') {
				updateEmailFeedback();
			}
			if (confirmPassword.value !== '') {
				updateConfirmationFeedback();
			}
			updateRoleFields();
		})();
	</script>
</body>
</html>
