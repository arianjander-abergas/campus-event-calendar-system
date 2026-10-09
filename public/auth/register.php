<?php
/**
 * Sign up. Everyone chooses student or faculty, their department and their
 * organization (required). Students also give their School ID, course,
 * highest year level and whether they're regular or irregular — irregular
 * students pick the year levels of their subjects in a pop-up.
 */
require_once __DIR__ . '/../../config/supabase.php';

if (current_user()) {
    header('Location: ' . BASE_URL . '/enduser/dashboard.php');
    exit;
}

$departments = get_departments();
$organizations = get_organizations();
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = post_param('full_name');
    $email = post_param('email');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    if ($fullName === '') $errors[] = 'Please enter your full name.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    $account = validate_account_fields([
        'account_type' => post_param('account_type'),
        'department_id' => post_param('department_id'),
        'organization_id' => post_param('organization_id'),
        'school_id' => post_param('school_id'),
        'course' => post_param('course'),
        'year_level' => post_param('year_level'),
        'student_type' => post_param('student_type'),
        'irregular_year_levels' => $_POST['irregular_year_levels'] ?? [],
    ], $departments, $organizations);
    $errors = array_merge($errors, $account['errors']);

    if (!$errors) {
        $result = supabase_auth_signup($email, $password, $fullName, $account['fields'] + ['school_id' => $account['school_id']]);
        if ($result['ok'] && empty($result['needsEmailConfirmation'])) {
            flash('success', 'Welcome to Campus Event Hub, ' . explode(' ', $fullName)[0] . '!');
            header('Location: ' . BASE_URL . '/enduser/dashboard.php');
            exit;
        }
        if ($result['ok']) {
            $success = $result['message']; // "confirm your email" case
        } else {
            $errors[] = $result['message'];
        }
    }

    if ($errors) {
        // Back to a clean URL: the typed values show once (passwords never).
        remember_form('register', ['full_name' => $fullName, 'email' => $email] + $account['fields'] + ['school_id' => $account['school_id'] ?? post_param('school_id')], $errors);
        header('Location: register.php');
        exit;
    }
}

$state = recall_form('register');
$errors = $state['errors'] ?? [];
$acct = $state['values'] ?? [];
$acctStyle = 'auth';
$old = fn(string $key): string => htmlspecialchars((string) ($acct[$key] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign Up — Campus Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;700;800&display=swap">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth.css') ?>">
</head>
<body class="login-page login-glass">

<!-- Decorative blurred "dashboard" behind the glass card (pure CSS, no images). -->
<div class="glass-backdrop" aria-hidden="true">
  <div class="gb-sidebar"><i></i><i></i><i></i><i></i><i></i><i></i></div>
  <div class="gb-main">
    <div class="gb-topbar"></div>
    <div class="gb-hero"></div>
    <div class="gb-cards"><i></i><i></i><i></i><i></i></div>
    <div class="gb-rows"><i></i><i></i><i></i></div>
  </div>
</div>

<main class="login-card register-card">
  <a href="../index.php" class="login-close" aria-label="Close and go back home">
    <img src="../assets/images/login/icon-close.svg" alt="" width="26" height="26">
  </a>

  <!-- Solid panel on the right (the login page's is on the left); "Log in" slides it left. -->
  <div class="login-slide-panel" aria-hidden="true"></div>

  <div class="login-pane">
  <a href="../index.php" class="login-logo" aria-label="Campus Event Hub home">
    <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="login-logo-mark" width="88" height="88">
    <span class="login-logo-text">
      <span class="campus">CAMPUS</span>
      <span class="hub">EVENT HUB</span>
    </span>
  </a>

  <h1>Create your account</h1>
  <p class="login-subtitle">Join Campus Event Hub to discover and register for campus events.</p>

  <?php if ($errors): ?>
    <div class="login-alert" role="alert">
      <?php if (count($errors) === 1): ?>
        <?= htmlspecialchars($errors[0]) ?>
      <?php else: ?>
        Please fix these:
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="login-notice"><?= htmlspecialchars($success) ?> <a href="login.php">Log in</a></div>
  <?php endif; ?>

  <?php if (!$success): ?>
  <form method="POST" action="register.php" class="login-form register-form" data-autoreset>
    <div class="register-grid">
      <div class="register-full">
        <label for="full_name">Full Name</label>
        <input type="text" id="full_name" name="full_name" class="login-input" required autocomplete="name"
               placeholder="Juan Dela Cruz" value="<?= $old('full_name') ?>">
      </div>

      <div class="register-full">
        <label for="email">Email Address</label>
        <input type="email" id="email" name="email" class="login-input" required autocomplete="email"
               placeholder="name@email.lcup.edu.ph" value="<?= $old('email') ?>">
      </div>

      <div>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="login-input" required minlength="6" autocomplete="new-password" placeholder="At least 6 characters">
      </div>
      <div>
        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" class="login-input" required minlength="6" autocomplete="new-password" placeholder="Type it again">
      </div>

      <?php include __DIR__ . '/../../includes/account-fields.php'; ?>
    </div>

    <button type="submit" class="login-btn login-submit">Sign Up</button>
  </form>
  <?php endif; ?>

  <p class="login-signup">Already have an account? <a href="login.php">Log in</a></p>
  </div>
</main>

<script src="../assets/js/account.js"></script>
<script src="../assets/js/forms.js"></script>
<script src="../assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>"></script>
<!-- Chrome/Edge get the login page ready while the pointer rests on "Log in", so it opens instantly. -->
<script type="speculationrules">{"prerender": [{"source": "list", "urls": ["login.php"], "eagerness": "moderate"}]}</script>
</body>
</html>
