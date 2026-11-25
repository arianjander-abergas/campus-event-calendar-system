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
session_start_once();

// "Didn't get the email?" on the check-your-inbox screen.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'resend') {
    $pendingEmail = (string) ($_SESSION['signup_email'] ?? '');
    if ($pendingEmail !== '') {
        $resent = supabase_auth_resend_confirmation($pendingEmail);
        flash($resent['ok'] ? 'success' : 'error', $resent['message']);
    }
    header('Location: register.php?sent=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = name_parts_from_post(); // last / first / middle name, and the full name they make
    $fullName = $name['full_name'];
    $email = strtolower(post_param('email'));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    $errors = array_merge($errors, name_parts_errors($name));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter your LCUP school email (ending in @' . SCHOOL_EMAIL_DOMAIN . ').';
    } elseif (!email_allowed($email)) {
        $errors[] = school_email_rule_message();
    }
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
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
        'other_organization_ids' => $_POST['other_organization_ids'] ?? [],
    ], $departments, $organizations);
    $errors = array_merge($errors, $account['errors']);

    if (!$errors) {
        $result = supabase_auth_signup($email, $password, $fullName, $account['fields'] + ['school_id' => $account['school_id']]
            + array_intersect_key($name, array_flip(['last_name', 'first_name', 'middle_name'])));
        if ($result['ok'] && empty($result['needsEmailConfirmation'])) {
            flash('success', 'Welcome to Campus Event Hub, ' . $name['first_name'] . '!');
            header('Location: ' . BASE_URL . '/enduser/dashboard.php');
            exit;
        }
        if ($result['ok']) {
            // "Confirm your email": a clean page with the address and a Resend button.
            $_SESSION['signup_email'] = $email;
            header('Location: register.php?sent=1');
            exit;
        }
        $errors[] = $result['message'];
    }

    if ($errors) {
        // Back to a clean URL: the typed values show once (passwords never).
        remember_form('register', ['last_name' => $name['last_name'], 'first_name' => $name['first_name'], 'middle_name' => $name['middle_name'], 'email' => $email] + $account['fields'] + ['school_id' => $account['school_id'] ?? post_param('school_id')], $errors);
        header('Location: register.php');
        exit;
    }
}

// After signing up: check your inbox (the confirmation link comes back to auth/callback.php).
$sentTo = get_param('sent') !== '' ? (string) ($_SESSION['signup_email'] ?? '') : '';
$flashes = render_flashes();

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
<?= app_head_tags() ?>
<title>Sign Up — Campus Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;700;800&display=swap">
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
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
    <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="Campus Event Hub logo" class="login-logo-mark" width="88" height="88">
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
  <?= $flashes ?>
  <?php if ($sentTo !== ''): ?>
    <div class="signup-sent" role="status">
      <h2>Check your email</h2>
      <p>We sent a confirmation link to <strong><?= htmlspecialchars($sentTo) ?></strong>. Open it to activate your account — then you're logged in.</p>
      <p class="signup-sent-small">Not there? Check your spam or promotions folder. The link works once.</p>
      <form method="POST" action="register.php" class="signup-resend">
        <input type="hidden" name="action" value="resend">
        <button type="submit" class="login-btn">Resend the email</button>
        <a href="login.php" class="signup-sent-link">Back to login</a>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($sentTo === ''): ?>
  <form method="POST" action="register.php" class="login-form register-form" data-autoreset>
    <div class="register-grid">
      <div>
        <label for="last_name">Last Name</label>
        <input type="text" id="last_name" name="last_name" class="login-input" required maxlength="60" autocomplete="family-name"
               placeholder="Dela Cruz" value="<?= $old('last_name') ?>">
      </div>
      <div>
        <label for="first_name">First Name</label>
        <input type="text" id="first_name" name="first_name" class="login-input" required maxlength="60" autocomplete="given-name"
               placeholder="Juan" value="<?= $old('first_name') ?>">
      </div>
      <div class="register-full">
        <label for="middle_name">Middle Name <span class="login-optional">(optional)</span></label>
        <input type="text" id="middle_name" name="middle_name" class="login-input" maxlength="60" autocomplete="additional-name"
               placeholder="Santos" value="<?= $old('middle_name') ?>">
      </div>

      <div class="register-full">
        <label for="email">LCUP School Email</label>
        <input type="email" id="email" name="email" class="login-input" required autocomplete="email"
               placeholder="juan.delacruz@<?= SCHOOL_EMAIL_DOMAIN ?>" value="<?= $old('email') ?>"
               pattern="[^@\s]+@email\.lcup\.edu\.ph" title="Use your LCUP school email, ending in @<?= SCHOOL_EMAIL_DOMAIN ?>">
        <p class="login-field-hint">Only @<?= SCHOOL_EMAIL_DOMAIN ?> addresses can sign up. We'll email you a link to confirm it.</p>
      </div>

      <div>
        <label for="password">Password</label>
        <div class="password-wrap">
          <input type="password" id="password" name="password" class="login-input" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
          <?= password_toggle_button('password') ?>
        </div>
      </div>
      <div>
        <label for="confirm_password">Confirm Password</label>
        <div class="password-wrap">
          <input type="password" id="confirm_password" name="confirm_password" class="login-input" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" placeholder="Type it again">
          <?= password_toggle_button('confirm_password') ?>
        </div>
      </div>

      <?php include __DIR__ . '/../../includes/account-fields.php'; ?>
    </div>

    <button type="submit" class="login-btn login-submit">Sign Up</button>
  </form>
  <?php endif; ?>

  <p class="login-signup">Already have an account? <a href="login.php">Log in</a></p>
  </div>
</main>

<script src="../assets/js/confirm.js?v=<?= filemtime(__DIR__ . '/../assets/js/confirm.js') ?>"></script>
<script src="../assets/js/account.js?v=<?= filemtime(__DIR__ . '/../assets/js/account.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<script src="../assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>"></script>
<!-- Chrome/Edge get the login page ready while the pointer rests on "Log in", so it opens instantly. -->
<script type="speculationrules">{"prerender": [{"source": "list", "urls": ["login.php"], "eagerness": "moderate"}]}</script>
</body>
</html>
