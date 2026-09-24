<?php
<<<<<<< Updated upstream
require_once __DIR__ . '/config/supabase.php';
=======
require_once __DIR__ . '/../../config/supabase.php';
>>>>>>> Stashed changes
session_start_once();

// If logged in, skip straight to the dashboard.
if (current_user()) {
<<<<<<< Updated upstream
    header('Location: dashboard.php');
    exit;
}

$redirectTo = $_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard.php';
=======
    header('Location: ../enduser/dashboard.php');
    exit;
}

$redirectTo = $_GET['redirect'] ?? $_POST['redirect'] ?? '../enduser/dashboard.php';
>>>>>>> Stashed changes
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

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
<<<<<<< Updated upstream
=======

// Short info messages for the buttons that don't have a backend yet
// (Google sign-in, password reset) and for the Admin Login shortcut.
$notices = [
    'google' => 'Google sign-in isn\'t set up yet. Please log in with your email and password.',
    'forgot' => 'Password reset isn\'t available yet. Please contact the site administrator.',
    'admin'  => 'Log in with your admin account to open the Admin View.',
];
$notice = $notices[$_GET['notice'] ?? ''] ?? null;
$adminLoginUrl = 'login.php?notice=admin&redirect=' . urlencode(BASE_URL . '/admin/event-organizer.php');
>>>>>>> Stashed changes
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — Campus Event Hub</title>
<<<<<<< Updated upstream
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="auth-shell">
  <div class="auth-card">
    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-sub">Log in to register for events and see your dashboard.</p>

    <?php if ($error): ?>
      <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo) ?>">

      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autocomplete="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">

      <button type="submit" class="btn btn-primary auth-submit">LOG IN</button>
    </form>

    <p class="auth-footnote">Don't have an account? <a href="register.php">Sign up</a></p>
  </div>
</div>
=======
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;700;800&display=swap">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body class="login-page">

<a href="../index.php" class="login-logo" aria-label="Campus Event Hub home">
  <img src="../assets/images/login/logo-mark.svg" alt="" class="login-logo-mark" width="72" height="68">
  <span class="login-logo-text">
    <span class="campus">CAMPUS</span>
    <span class="hub">EVENT HUB</span>
  </span>
</a>

<main class="login-card">
  <a href="../index.php" class="login-close" aria-label="Close and go back home">
    <img src="../assets/images/login/icon-close.svg" alt="" width="26" height="26">
  </a>

  <h1>Login to your account</h1>

  <?php if ($error): ?>
    <div class="login-alert"><?= htmlspecialchars($error) ?></div>
  <?php elseif ($notice): ?>
    <div class="login-notice"><?= htmlspecialchars($notice) ?></div>
  <?php endif; ?>

  <form method="POST" class="login-form">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo) ?>">

    <label for="email">Email Address</label>
    <div class="login-field">
      <span class="login-field-icon" aria-hidden="true">
        <img src="../assets/images/login/icon-email.svg" alt="" width="28" height="28">
      </span>
      <input type="email" id="email" name="email" required autocomplete="email"
             placeholder="name@email.lcup.edu.ph"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
    </div>

    <label for="password">Password</label>
    <div class="login-field">
      <button type="button" class="login-field-icon" id="togglePassword" aria-label="Show password">
        <img class="icon-hidden" src="../assets/images/login/icon-eye-off.svg" alt="" width="28" height="28">
        <img class="icon-visible" src="../assets/images/login/icon-eye.svg" alt="" width="28" height="28" hidden>
      </button>
      <input type="password" id="password" name="password" required autocomplete="current-password"
             placeholder="************************">
    </div>

    <a href="login.php?notice=forgot" class="login-forgot">Forgot Password</a>

    <button type="submit" class="login-btn login-submit">Login</button>
  </form>

  <div class="login-divider">Or Sign in with</div>

  <div class="login-socials">
    <a href="login.php?notice=google" class="login-btn">
      <img src="../assets/images/login/icon-google.svg" alt="" width="34" height="34">
      Google
    </a>
    <a href="<?= htmlspecialchars($adminLoginUrl) ?>" class="login-btn">
      <img src="../assets/images/login/icon-admin.svg" alt="" width="34" height="34">
      Admin Login
    </a>
  </div>
</main>

<p class="login-signup">Don't have an account? <a href="register.php">Sign up</a></p>

<script>
  // Eye button: show/hide the password text.
  (function () {
    const btn = document.getElementById("togglePassword");
    const input = document.getElementById("password");
    btn.addEventListener("click", () => {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      btn.querySelector(".icon-hidden").hidden = show;
      btn.querySelector(".icon-visible").hidden = !show;
      btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
    });
  })();
</script>
>>>>>>> Stashed changes

</body>
</html>
