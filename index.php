<?php
// Simple login router for prototype
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
  ]);
  session_start();
}
$error = '';
$registrationSuccess = $_SERVER['REQUEST_METHOD'] === 'GET'
  ? ($_SESSION['registration_success'] ?? null)
  : null;
unset($_SESSION['registration_success']);
$loginIdentifier = trim((string) ($_POST['csucc_id'] ?? ($_POST['email'] ?? ($registrationSuccess['login_id'] ?? ''))));
unset($_SESSION['pending_otp']);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_SESSION['user']) && strpos($_SERVER['HTTP_REFERER'] ?? '', '/students/') !== false) {
  $_SESSION = [];
  session_destroy();
  header('Location: index.php?logged_out=1');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $error = 'Your session expired. Refresh the page and try again.';
  } else {
    unset($_SESSION['user']);
    $identifier = strtoupper(trim((string) ($_POST['csucc_id'] ?? ($_POST['email'] ?? ''))));
    $password = (string) ($_POST['password'] ?? '');

    if ($identifier === '') {
      $error = 'Enter your CSUCC ID Number.';
    } elseif ($password === '') {
      $error = 'Enter your password.';
    } else {
      $identifierQuery = $identifier;
      $stmt = $pdo->prepare('SELECT id, email, full_name, password_hash, role, account_id, department, can_evaluate, student_id_number, employee_id_number FROM users WHERE email = ? OR student_id_number = ? OR employee_id_number = ? OR account_id = ? LIMIT 1');
      $stmt->execute([$identifier, $identifierQuery, $identifierQuery, $identifier]);
      $user = $stmt->fetch(PDO::FETCH_ASSOC);
      $destinations = [
        'dean' => 'dean/dashboard.php',
        'evaluator' => 'evaluator/dashboard.php',
        'student' => 'students/student_dashboard.php',
      ];

      if (
        $user
        && !empty($user['password_hash'])
        && password_verify($password, $user['password_hash'])
        && isset($destinations[$user['role']])
      ) {
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        header('Location: ' . $destinations[$user['role']]);
        exit;
      } else {
        $error = 'Invalid credentials';
      }
    }
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Capstone Evaluation System - BSIT Capstone Portal</title>
  <link rel="stylesheet" href="assets/style.css?v=20261113a">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="icon" type="image/png" href="favicon.png?v=20261104">
</head>
<body class="home-page">
  <header class="home-header">
    <a class="home-brand" href="#home" aria-label="Capstone Evaluation System home">
      <span class="brand-mark">△</span>
      <span><strong>CAPSTONE EVALUATION SYSTEM</strong><small>BSIT CAPSTONE PORTAL</small></span>
    </a>
    <button class="home-menu" type="button" aria-label="Open navigation" aria-expanded="false">Menu</button>
    <nav class="home-nav" aria-label="Main navigation">
      <a href="#home">Home</a>
      <a href="#about">About</a>
      <a href="#system">System</a>
      <a href="#actors">Actors</a>
      <a href="#contact">Contact</a>
      <a class="nav-login" href="#login">Sign in <span aria-hidden="true">-&gt;</span></a>
    </nav>
  </header>

  <main>
    <section class="home-hero" id="home">
      <div class="hero-shade"></div>
      <div class="home-hero-content">
        <div class="hero-copy">
          <p class="home-kicker"><span></span> Academic evaluation, made clearer</p>
          <h1>Make every capstone<br><em>score explainable.</em></h1>
          <p class="hero-lead">A Mamdani Fuzzy Logic-based platform for consistent, transparent, and data-driven BSIT capstone project evaluation.</p>
          <div class="hero-actions">
            <a class="home-button home-button-accent" href="#login">Access the portal <span aria-hidden="true">-&gt;</span></a>
            <a class="home-text-link" href="#system">Explore the method <span aria-hidden="true">&#8595;</span></a>
          </div>
        </div>

        <div class="home-login-panel" id="login">
          <div class="login-panel-top"><span class="panel-overline">SECURE PORTAL ACCESS</span><span class="panel-dot" aria-hidden="true"></span></div>
          <h2>Welcome back.</h2>
          <p class="panel-intro">Sign in to continue to your evaluation workspace.</p>
          <?php if (isset($_GET['registered'])): ?>
            <div class="home-alert home-success">
              Your account has been created. Sign in below using your CSUCC ID Number and password.
              <?php if (!empty($registrationSuccess['login_id'])): ?>
                <br><strong>Your CSUCC ID Number:</strong> <?=esc($registrationSuccess['login_id'])?>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($error)): ?><div class="home-alert" id="login-error" role="alert"><?=esc($error)?></div><?php endif; ?>
          <form method="post" id="login-form" novalidate>
              <input type="hidden" name="csrf" value="<?=esc(csrf_token())?>">
              <input type="hidden" name="action" value="login">
              <label for="home-identifier">CSUCC ID NUMBER</label>
              <input id="home-identifier" name="csucc_id" type="text" value="<?=esc($loginIdentifier)?>" placeholder="Enter your CSUCC ID Number" autocomplete="username" required aria-describedby="home-identifier-error">
              <p class="login-field-error" id="home-identifier-error" aria-live="polite"></p>
              <label for="home-password">Password</label>
              <div class="home-password-field"><input id="home-password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required aria-describedby="home-password-error"><button class="home-password-toggle" type="button" aria-label="Show password" aria-controls="home-password" aria-pressed="false">&#128065;</button></div>
              <p class="login-field-error" id="home-password-error" aria-live="polite"></p>
              <button class="home-submit" type="submit">Sign in to portal <span aria-hidden="true">-&gt;</span></button>
          </form>
          <p class="register-prompt">New to the system? <a href="register.php">Create an account</a></p>
          <div class="security-assurance" aria-label="Data protection information">
            <div class="security-badge" aria-hidden="true">🛡️</div>
            <h3>Your Data, Protected.</h3>
            <p>Your academic records are accessible only to authorized users.</p>
            <div class="security-indicators" aria-label="Security indicators">
              <div class="security-indicator"><span aria-hidden="true">🔒</span><small>Secure Access</small></div>
              <div class="security-indicator"><span aria-hidden="true">✅</span><small>Confidential Records</small></div>
              <div class="security-indicator"><span aria-hidden="true">👥</span><small>Role-Based Authorization</small></div>
            </div>
            <a class="privacy-link" href="privacy_notice.php">Privacy Notice</a>
          </div>
        </div>
      </div>
      <div class="hero-scroll-note"><span class="scroll-line"></span> Scroll to explore</div>
    </section>

    <section class="home-section home-about" id="about">
      <div class="section-label">01 / THE PLATFORM</div>
      <div class="about-grid">
        <div><h2>Evaluation that leaves<br><em>a clear trail.</em></h2></div>
        <div><p class="section-lead">Fuzzy Score helps academic evaluators translate informed judgment into a consistent and understandable result.</p><p>Panel-average project and paper scores produce an official CEIT-T07B average and verdict. Mamdani membership functions and fuzzy rules remain available as a separate, explainable analysis.</p></div>
      </div>
      <div class="method-ribbon"><span><b>01</b> Project quality</span><strong>+</strong><span><b>02</b> Paper quality</span><strong>=</strong><span class="ribbon-result"><b>03</b> An explainable result</span></div>
    </section>

    <section class="home-section system-section" id="system">
      <div class="section-label light-label">02 / HOW IT WORKS</div>
      <div class="section-heading-row"><h2>From evidence<br>to <em>insight.</em></h2><p>Six deliberate steps turn structured evaluation inputs into a result that can be understood, reviewed, and improved.</p></div>
      <div class="process-grid">
        <article><span>01</span><i>+</i><h3>Evaluation input</h3><p>Evaluators provide project and paper assessment scores.</p></article>
        <article><span>02</span><i>~</i><h3>Fuzzification</h3><p>Numerical inputs become gradual membership degrees.</p></article>
        <article><span>03</span><i>:</i><h3>Rule evaluation</h3><p>Mamdani rules determine which outputs are activated.</p></article>
        <article><span>04</span><i>&gt;</i><h3>Aggregation</h3><p>Activated fuzzy outputs are combined by strength.</p></article>
        <article><span>05</span><i>%</i><h3>Fuzzy analysis</h3><p>Defuzzification provides an internal inference value for explanation.</p></article>
        <article class="process-final"><span>06</span><i>OK</i><h3>Official verdict</h3><p>The rounded panel-average score is classified using CEIT-T07B.</p></article>
      </div>
    </section>

    <section class="home-section actors-section" id="actors">
      <div class="section-label">03 / PEOPLE IN THE SYSTEM</div>
      <div class="section-heading-row"><h2>One platform.<br><em>Clear roles.</em></h2><p>Each actor sees the tools and information needed for their part in the evaluation workflow.</p></div>
      <div class="actors-grid">
        <article><span class="actor-number">01</span><div class="actor-icon">D</div><h3>IT Chairperson</h3><p>Manages users, projects, evaluator assignments, fuzzy rules, membership functions, analytics, and reports.</p><a href="#login">IT Chairperson access <span>-&gt;</span></a></article>
        <article><span class="actor-number">02</span><div class="actor-icon">E</div><h3>Evaluator</h3><p>Evaluates assigned capstone projects and papers, then records feedback for the project team.</p><a href="#login">Evaluator access <span>-&gt;</span></a></article>
        <article><span class="actor-number">03</span><div class="actor-icon">S</div><h3>Defendee</h3><p>Views capstone status, scores, feedback, history, and the fuzzy explanation behind the result.</p><a href="#login">Student access <span>-&gt;</span></a></article>
      </div>
    </section>

    <section class="institutional-home-section">
      <div class="institutional-home-content">
        <div class="institutional-home-copy">
          <div class="institution-mark">CSU</div>
          <p class="section-label">INSTITUTIONAL HOME</p>
          <h2>Caraga State University<br><em>- Cabadbaran Campus</em></h2>
          <p>College of Engineering and Information Technology<br>Bachelor of Science in Information Technology</p>
        </div>
      </div>
    </section>

    <section class="home-section developers-section">
      <div class="section-label">04 / PROJECT TEAM</div><div class="section-heading-row"><h2>Built with<br><em>purpose.</em></h2><p>Fuzzy Score is a capstone project developed by BS Information Technology researchers and developers, focused on creating a Mamdani Fuzzy Logic-Based Scoring System for BSIT Capstone Proposal Evaluation.</p></div>
      <div class="developers-grid">
        <article class="developer-card">
          <div class="developer-photo"><img src="profile_dev/ronela2.jpg" alt="Ronela T. Alarcio"></div>
          <div class="developer-info"><span>01</span><h3>Ronela T. Alarcio — 4th Year</h3><p>BS Information Technology / Developer · Researcher</p></div>
        </article>
        <article class="developer-card">
          <div class="developer-photo"><img src="profile_dev/mayel2.jpg" alt="J-Ann Mayel S. Estrella"></div>
          <div class="developer-info"><span>02</span><h3>J-Ann Mayel S. Estrella — 4th Year</h3><p>BS Information Technology / Developer · Researcher</p></div>
        </article>
        <article class="developer-card">
          <div class="developer-photo"><img src="profile_dev/jane.jpg" alt="Jane Rhea S. Garzon"></div>
          <div class="developer-info"><span>03</span><h3>Jane Rhea S. Garzon — 4th Year</h3><p>BS Information Technology / Developer · Researcher</p></div>
        </article>
      </div>
    </section>

    <section class="contact-band" id="contact"><div><p class="section-label light-label">05 / CONTACT</p><h2>Questions about<br><em>the evaluation?</em></h2></div><div><p>Fuzzy Score Capstone Evaluation System</p><p>Caraga State University - Cabadbaran Campus</p><p class="contact-muted">Official contact information will be provided by the project administrators.</p></div></section>
  </main>

  <footer class="home-footer"><div class="footer-main"><div class="footer-brand"><a class="home-brand" href="#home"><span class="brand-mark">F</span><span><strong>CAPSTONE EVALUATION SYSTEM</strong><small>BSIT CAPSTONE PORTAL</small></span></a><p>Mamdani Fuzzy Logic-based capstone project evaluation for clearer academic decisions.</p></div><div><h3>Explore</h3><a href="#home">Home</a><a href="#about">About</a><a href="#system">How it works</a><a href="#actors">Actors</a></div><div><h3>Institution</h3><p>Caraga State University<br>Cabadbaran Campus<br>BS Information Technology</p></div><div><h3>Access</h3><a href="#login">Sign in</a><a href="register.php">Create account</a></div></div><div class="footer-bottom"><span>In compliance with the principles of the Data Privacy Act of 2012 (Republic Act No. 10173).</span></div><div class="footer-links"><a href="privacy_notice.php">Privacy Notice</a><a href="terms_of_use.php">Terms of Use</a><a href="data_protection.php">Data Protection</a><a href="security_guidelines.php">Security Guidelines</a><a href="#contact">Contact / Report a Security Concern</a></div></footer>
  <script>
    const menuButton = document.querySelector('.home-menu');
    const navigation = document.querySelector('.home-nav');
    menuButton?.addEventListener('click', () => {
      const isOpen = navigation.classList.toggle('is-open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
    });
    navigation?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => navigation.classList.remove('is-open')));

    const loginForm = document.getElementById('login-form');
    const loginIdentifier = document.getElementById('home-identifier');
    const loginPassword = document.getElementById('home-password');
    const passwordToggle = document.querySelector('.home-password-toggle');

    function setFieldState(field, message, isInvalid) {
      if (!field) return false;
      const feedback = document.getElementById(`${field.id}-error`);
      field.classList.toggle('is-invalid', isInvalid);
      field.setAttribute('aria-invalid', String(isInvalid));
      if (feedback) feedback.textContent = isInvalid ? message : '';
      return !isInvalid;
    }

    function validateLoginField(field, message) {
      if (!field) return true;
      return setFieldState(field, message, !field.value.trim());
    }

    loginForm?.addEventListener('submit', (event) => {
      const identifierValid = validateLoginField(loginIdentifier, 'CSUCC ID Number is required.');
      const passwordValid = validateLoginField(loginPassword, 'Password is required.');
      if (!identifierValid || !passwordValid) {
        event.preventDefault();
        (identifierValid ? loginPassword : loginIdentifier).focus();
      }
    });

    [loginIdentifier, loginPassword].forEach((field) => field?.addEventListener('input', () => {
      validateLoginField(field, field === loginIdentifier ? 'CSUCC ID Number is required.' : 'Password is required.');
    }));

    passwordToggle?.addEventListener('click', () => {
      const visible = loginPassword.type === 'text';
      loginPassword.type = visible ? 'password' : 'text';
      passwordToggle.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
      passwordToggle.setAttribute('aria-pressed', String(!visible));
    });
  </script>
</body>
</html>