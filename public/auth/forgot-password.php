<?php
require_once __DIR__ . '/../../config/supabase.php';

$error = null;
$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = post_param('email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter the email address you signed up with.';
    } else {
        $result = supabase_auth_recover($email);
        if ($result['ok']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — Campus Event Hub</title>
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

<main class="login-card forgot-card">
  <a href="login.php" class="login-close" aria-label="Back to login">
    <img src="../assets/images/login/icon-close.svg" alt="" width="26" height="26">
  </a>

  <!-- Solid panel strip on the left, same as the login page. -->
  <div class="login-slide-panel" aria-hidden="true"></div>

  <div class="login-pane">
  <a href="../index.php" class="login-logo" aria-label="Campus Event Hub home">
    <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="login-logo-mark" width="88" height="88">
    <span class="login-logo-text">
      <span class="campus">CAMPUS</span>
      <span class="hub">EVENT HUB</span>
    </span>
  </a>

  <h1>Reset your password</h1>

  <?php if ($error): ?>
    <div class="login-alert forgot-reveal" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="login-notice forgot-reveal"><?= htmlspecialchars($success) ?></div>
    <p class="login-subtitle forgot-reveal forgot-reveal-2">The link opens a page where you can choose a new password.</p>
  <?php else: ?>
    <p class="login-subtitle">Enter your email and we'll send you a link to choose a new password.</p>
    <form method="POST" class="login-form" data-loading-form>
      <label for="email">Email Address</label>
      <div class="login-field">
        <span class="login-field-icon" aria-hidden="true">
          <img src="../assets/images/login/icon-user.png" alt="" width="28" height="28">
        </span>
        <input type="email" id="email" name="email" required autocomplete="email"
               placeholder="name@email.lcup.edu.ph" value="<?= htmlspecialchars(post_param('email')) ?>">
      </div>
      <button type="submit" class="login-btn login-submit">Send reset link</button>
    </form>
  <?php endif; ?>

  <p class="login-signup">Remembered it? <a href="login.php">Back to login</a></p>
  </div>
</main>

<script src="../assets/js/auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/auth.js') ?>"></script>
<!-- Chrome/Edge get the login page ready while the pointer rests on "Back to login", so it opens instantly. -->
<script type="speculationrules">{"prerender": [{"source": "list", "urls": ["login.php"], "eagerness": "moderate"}]}</script>
</body>
</html>
