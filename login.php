<?php
require_once __DIR__ . '/config/supabase.php';
session_start_once();

// Already logged in? Skip straight to the dashboard.
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$redirectTo = $_GET['redirect'] ?? $_POST['redirect'] ?? 'dashboard.php';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Log In — Campus Event Hub</title>
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

</body>
</html>
