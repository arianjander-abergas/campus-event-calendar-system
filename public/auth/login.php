<?php
require_once __DIR__ . '/../../config/supabase.php';

// Where to go after logging in. Only paths on this site are allowed, so
// "?redirect=https://evil.site" can't bounce people to another website.
$defaultTarget = BASE_URL . '/enduser/dashboard.php';
$redirectTo = safe_redirect_target(get_param('redirect') ?: post_param('redirect'), $defaultTarget);

if (current_user()) {
    header('Location: ' . $redirectTo);
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = post_param('email');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $result = supabase_auth_signin($email, $password);
        if ($result['ok']) {
            header('Location: ' . $redirectTo);
            exit;
        }
        $error = $result['message'];
    }
}

// Google sign-in goes through Supabase once the Google provider is switched
// on (Supabase Dashboard -> Authentication -> Sign In / Providers -> Google).
$googleEnabled = auth_provider_enabled('google');
if ($googleEnabled) {
    session_start_once();
    $_SESSION['after_login'] = $redirectTo; // auth/callback.php sends them here
}

$notices = [
    'google' => "Google sign-in isn't switched on yet. Please log in with your email and password.",
    'admin'  => 'Log in with your admin account to open the admin pages.',
];
$adminTarget = BASE_URL . '/admin/dashboard.php';
$adminLoginUrl = 'login.php?notice=admin&redirect=' . urlencode($adminTarget);
// Admin mode = the card with the panel slid to the right (see auth.js). The
// Admin Login button toggles it in place; it is also on when the page is
// opened for an admin page (?notice=admin, or a redirect into /admin/).
$adminMode = get_param('notice') === 'admin' || strpos($redirectTo, BASE_URL . '/admin/') === 0;
$notice = get_param('notice') === 'admin' ? null : ($notices[get_param('notice')] ?? null);
// Where the form sends you after logging in: the admin dashboard in admin
// mode (unless a specific admin page was asked for), otherwise $redirectTo.
$formRedirect = ($adminMode && strpos($redirectTo, BASE_URL . '/admin/') !== 0) ? $adminTarget : $redirectTo;
$flashes = render_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — Campus Event Hub</title>
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

<main class="login-card signin-card<?= $adminMode ? ' is-admin' : '' ?>"
      data-admin-redirect="<?= htmlspecialchars($adminTarget) ?>"
      data-user-redirect="<?= htmlspecialchars($adminMode ? $defaultTarget : $redirectTo) ?>">
  <a href="../index.php" class="login-close" aria-label="Close and go back home">
    <!-- IMAGE SLOT 2: Close (✕) button, top-right of the card | file: assets/images/login/icon-close.svg | 26×26 -->
    <img src="../assets/images/login/icon-close.svg" alt="" width="26" height="26">
  </a>

  <!-- Solid panel: left = normal login, right = admin login (see auth.css / auth.js). -->
  <div class="login-slide-panel" aria-hidden="true"></div>

  <div class="login-pane">
  <a href="../index.php" class="login-logo" aria-label="Campus Event Hub home">
    <!-- IMAGE SLOT 1: School logo, top of the card | file: assets/images/school-logo.png (config SCHOOL_LOGO) -->
    <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="login-logo-mark" width="88" height="88">
    <span class="login-logo-text">
      <span class="campus">CAMPUS</span>
      <span class="hub">EVENT HUB</span>
    </span>
  </a>

  <h1>Login to your account</h1>
  <p class="login-subtitle">Welcome back! Sign in to see what's happening on campus.</p>

  <?= $flashes ?>
  <?php if ($error): ?>
    <div class="login-alert" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($notice): ?>
    <div class="login-notice"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>
  <div class="login-notice login-admin-notice"<?= $adminMode ? '' : ' hidden' ?>><?= htmlspecialchars($notices['admin']) ?></div>
  <div class="login-notice login-google-notice" hidden><?= htmlspecialchars($notices['google']) ?></div>

  <form method="POST" class="login-form">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($formRedirect) ?>">

    <label for="email">Email Address</label>
    <div class="login-field">
      <span class="login-field-icon" aria-hidden="true">
        <!-- IMAGE SLOT 3: Email field icon | file: assets/images/login/icon-user.png | 28×28 -->
        <img src="../assets/images/login/icon-user.png" alt="" width="28" height="28">
      </span>
      <input type="email" id="email" name="email" required autocomplete="email"
             placeholder="name@email.lcup.edu.ph"
             value="<?= htmlspecialchars(post_param('email')) ?>">
    </div>

    <label for="password">Password</label>
    <div class="login-field">
      <span class="login-field-icon" aria-hidden="true">
        <!-- IMAGE SLOT 8: Password field icon | file: assets/images/login/icon-lock.png | 28×28 -->
        <img src="../assets/images/login/icon-lock.png" alt="" width="28" height="28">
      </span>
      <input type="password" id="password" name="password" required autocomplete="current-password"
             placeholder="Enter your password">
      <button type="button" class="login-field-toggle" data-toggle-password="password" aria-label="Show password">
        <!-- IMAGE SLOT 4: Password eye, default (password hidden) | file: assets/images/login/icon-eye-off.svg | 28×28 -->
        <img class="icon-hidden" src="../assets/images/login/icon-eye-off.svg" alt="" width="28" height="28">
        <!-- IMAGE SLOT 5: Password eye, shown after clicking (password visible) | file: assets/images/login/icon-eye.svg | 28×28 -->
        <img class="icon-visible" src="../assets/images/login/icon-eye.svg" alt="" width="28" height="28" hidden>
      </button>
    </div>

    <a href="forgot-password.php" class="login-forgot">Forgot Password?</a>

    <button type="submit" class="login-btn login-submit">Login</button>
  </form>

  <div class="login-divider">Or Sign in with</div>

  <div class="login-socials">
    <a href="<?= htmlspecialchars($googleEnabled ? google_signin_url() : 'login.php?notice=google') ?>" class="login-btn" data-google="<?= $googleEnabled ? 'on' : 'off' ?>">
      <!-- IMAGE SLOT 6: Google button icon | file: assets/images/login/icon-google.svg | 34×34 -->
      <img src="../assets/images/login/icon-google.svg" alt="" width="34" height="34">
      Google
    </a>
    <a href="<?= htmlspecialchars($adminLoginUrl) ?>" class="login-btn" data-toggle-admin aria-pressed="<?= $adminMode ? 'true' : 'false' ?>">
      <!-- IMAGE SLOT 7: Admin Login button icon | file: assets/images/login/icon-admin.svg | 34×34 -->
      <img src="../assets/images/login/icon-admin.svg" alt="" width="34" height="34">
      Admin Login
    </a>
  </div>

  <p class="login-signup">Don't have an account? <a href="register.php">Sign up</a></p>
  </div>
</main>

<script src="../assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>"></script>
<!-- Chrome/Edge get Sign up / Forgot Password ready while the pointer rests on the link, so they open instantly. -->
<script type="speculationrules">{"prerender": [{"source": "list", "urls": ["register.php", "forgot-password.php"], "eagerness": "moderate"}]}</script>
</body>
</html>
