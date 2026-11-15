<?php
/**
 * Where Supabase sends people back after Google sign-in, the "confirm your
 * email" link or a password-reset link. Supabase puts the session tokens in
 * the URL *fragment* (#access_token=...), which never reaches PHP, so a tiny
 * script posts them here. The tokens are verified with Supabase before a
 * session is started.
 *
 * The site's URL must be allowed in Supabase: Authentication -> URL
 * Configuration -> Redirect URLs (e.g. http://localhost/campus-event-calendar-system/public/**).
 */
require_once __DIR__ . '/../../config/supabase.php';
session_start_once();

$error = null;
$step = get_param('step');

// ---- 1. Tokens posted by the script below --------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'tokens') {
    // The nonce proves the post came from this page (not another site
    // trying to log the visitor into someone else's account).
    if (!hash_equals((string) ($_SESSION['callback_nonce'] ?? ''), post_param('nonce'))) {
        $error = 'This sign-in link has expired. Please try again.';
    } else {
        unset($_SESSION['callback_nonce']);
        $result = supabase_session_from_tokens(post_param('access_token'), post_param('refresh_token') ?: null);
        if (!$result['ok']) {
            $error = $result['message'];
        } elseif (post_param('type') === 'recovery') {
            $_SESSION['password_recovery'] = true;
            header('Location: callback.php?step=new-password');
            exit;
        } elseif (post_param('type') === 'signup') {
            // The link from the "confirm your email" message.
            unset($_SESSION['signup_email']);
            flash('success', 'Your email is confirmed — welcome to Campus Event Hub, ' . explode(' ', trim((string) (current_user()['full_name'] ?? '')))[0] . '!');
            header('Location: ' . BASE_URL . '/enduser/dashboard.php');
            exit;
        } else {
            $target = safe_redirect_target($_SESSION['after_login'] ?? '', BASE_URL . '/enduser/dashboard.php');
            unset($_SESSION['after_login']);
            header('Location: ' . $target);
            exit;
        }
    }
}

// ---- 2. Choosing a new password after a reset link -------------------------
if ($step === 'new-password') {
    if (!current_user() || empty($_SESSION['password_recovery'])) {
        header('Location: forgot-password.php');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_password') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $error = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        } elseif ($password !== (is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '')) {
            $error = 'Passwords do not match.';
        } else {
            $result = supabase_auth_update_password($password);
            if ($result['ok']) {
                unset($_SESSION['password_recovery']);
                flash('success', 'Your password was changed.');
                header('Location: ' . BASE_URL . '/enduser/dashboard.php');
                exit;
            }
            $error = $result['message'];
        }
    }
}

$_SESSION['callback_nonce'] = $_SESSION['callback_nonce'] ?? bin2hex(random_bytes(16));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title><?= $step === 'new-password' ? 'Choose a New Password' : 'Signing you in…' ?> — Campus Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;700;800&display=swap">
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/auth.css?v=<?= filemtime(__DIR__ . '/../assets/css/auth.css') ?>">
</head>
<body class="login-page">

<a href="../index.php" class="login-logo" aria-label="Campus Event Hub home">
  <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="Campus Event Hub logo" class="login-logo-mark" width="72" height="68">
  <span class="login-logo-text">
    <span class="campus">CAMPUS</span>
    <span class="hub">EVENT HUB</span>
  </span>
</a>

<main class="login-card">
  <?php if ($step === 'new-password'): ?>
    <h1>Choose a new password</h1>
    <?php if ($error): ?><div class="login-alert" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST" class="login-form">
      <input type="hidden" name="action" value="set_password">
      <label for="password">New Password</label>
      <div class="password-wrap">
        <input type="password" id="password" name="password" class="login-input" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" placeholder="At least <?= PASSWORD_MIN_LENGTH ?> characters">
        <?= password_toggle_button('password') ?>
      </div>
      <label for="confirm_password">Confirm New Password</label>
      <div class="password-wrap">
        <input type="password" id="confirm_password" name="confirm_password" class="login-input" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
        <?= password_toggle_button('confirm_password') ?>
      </div>
      <button type="submit" class="login-btn login-submit">Save password</button>
    </form>
  <?php else: ?>
    <h1 id="callbackTitle"><?= $error ? 'Sign-in failed' : 'Signing you in…' ?></h1>
    <div class="login-alert" id="callbackError" role="alert"<?= $error ? '' : ' hidden' ?>><?= htmlspecialchars((string) $error) ?></div>
    <p class="login-help" id="callbackHelp"<?= $error ? '' : ' hidden' ?>><a href="login.php">Back to login</a></p>

    <form method="POST" id="tokenForm" hidden>
      <input type="hidden" name="action" value="tokens">
      <input type="hidden" name="nonce" value="<?= htmlspecialchars($_SESSION['callback_nonce']) ?>">
      <input type="hidden" name="access_token">
      <input type="hidden" name="refresh_token">
      <input type="hidden" name="type">
    </form>
  <?php endif; ?>
</main>

<?php if ($step === 'new-password'): ?>
<script src="../assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>"></script>
<?php endif; ?>
<?php if ($step !== 'new-password' && !$error): ?>
<script>
  // Supabase returns "#access_token=...&refresh_token=...&type=recovery" or "#error_description=...".
  (function () {
    const params = new URLSearchParams(location.hash.slice(1) || location.search.slice(1));
    const form = document.getElementById("tokenForm");
    if (params.get("access_token")) {
      form.access_token.value = params.get("access_token");
      form.refresh_token.value = params.get("refresh_token") || "";
      form.type.value = params.get("type") || new URLSearchParams(location.search).get("type") || "";
      history.replaceState(null, "", location.pathname); // don't leave tokens in the address bar
      form.submit();
      return;
    }
    document.getElementById("callbackTitle").textContent = "Sign-in failed";
    const err = document.getElementById("callbackError");
    const why = params.get("error_description") || "";
    const code = params.get("error_code") || "";
    // The database refuses accounts that aren't LCUP school emails (enforce_school_email()).
    err.textContent = /Database error saving new user|SCHOOL_EMAIL_ONLY/i.test(why)
      ? <?= json_encode('Use your LCUP Google account (@' . SCHOOL_EMAIL_DOMAIN . '). ' . school_email_rule_message()) ?>
      : (code === "otp_expired" || /expired/i.test(why))
        ? "This link has expired or was already used. Log in to get a new confirmation link, or use \"Forgot Password?\" for a new reset link."
        : (why || "This sign-in link is invalid or has expired. Please try again.");
    err.hidden = false;
    document.getElementById("callbackHelp").hidden = false;
  })();
</script>
<?php endif; ?>
</body>
</html>
