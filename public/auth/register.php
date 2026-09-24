<?php
require_once __DIR__ . '/config/supabase.php';
session_start_once();

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $result = supabase_auth_signup($email, $password, $fullName);
        if ($result['ok']) {
            if (!empty($result['needsEmailConfirmation'])) {
                $success = $result['message'];
            } else {
                header('Location: dashboard.php');
                exit;
            }
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
<title>Sign Up — Campus Event Hub</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<div class="auth-shell">
  <div class="auth-card">
    <h1 class="auth-title">Create your account</h1>
    <p class="auth-sub">Sign up to register for JPSSITE, SIES, and ACpES events.</p>

    <?php if ($error): ?>
      <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="auth-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST" class="auth-form">
      <label for="full_name">Full name</label>
      <input type="text" id="full_name" name="full_name" required autocomplete="name"
             value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">

      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autocomplete="email"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="new-password" minlength="6">

      <label for="confirm_password">Confirm password</label>
      <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" minlength="6">

      <button type="submit" class="btn btn-primary auth-submit">SIGN UP</button>
    </form>
    <?php endif; ?>

    <p class="auth-footnote">Already have an account? <a href="login.php">Log in</a></p>
  </div>
</div>

</body>
</html>
