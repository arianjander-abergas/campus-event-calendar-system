<?php
require_once __DIR__ . '/../../config/supabase.php';

if (current_user()) {
    header('Location: ' . BASE_URL . '/enduser/dashboard.php');
    exit;
}

$organizations = get_organizations();
$orgIds = array_column($organizations, 'id');
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = post_param('full_name');
    $email = post_param('email');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $yearLevel = post_param('year_level');
    $course = post_param('course');
    $organizationId = post_param('organization_id');

    if ($fullName === '' || $email === '' || $password === '') {
        $error = 'Please fill in your name, email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($yearLevel !== '' && !in_array($yearLevel, ['1', '2', '3', '4', '5'], true)) {
        $error = 'Please choose a valid year level.';
    } elseif ($organizationId !== '' && !in_array($organizationId, $orgIds, true)) {
        $error = 'Please choose an organization from the list.';
    } else {
        $result = supabase_auth_signup($email, $password, $fullName, [
            'year_level' => $yearLevel !== '' ? (int) $yearLevel : null,
            'course' => $course !== '' ? $course : null,
            'organization_id' => $organizationId !== '' ? $organizationId : null,
        ]);
        if ($result['ok'] && empty($result['needsEmailConfirmation'])) {
            flash('success', 'Welcome to Campus Event Hub, ' . explode(' ', $fullName)[0] . '!');
            header('Location: ' . BASE_URL . '/enduser/dashboard.php');
            exit;
        }
        if ($result['ok']) {
            $success = $result['message']; // "confirm your email" case
        } else {
            $error = $result['message'];
        }
    }
}
$old = fn(string $key): string => htmlspecialchars(post_param($key));
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
<link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body class="login-page">

<a href="../index.php" class="login-logo" aria-label="Campus Event Hub home">
  <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="login-logo-mark" width="72" height="68">
  <span class="login-logo-text">
    <span class="campus">CAMPUS</span>
    <span class="hub">EVENT HUB</span>
  </span>
</a>

<main class="login-card register-card">
  <a href="../index.php" class="login-close" aria-label="Close and go back home">
    <img src="../assets/images/login/icon-close.svg" alt="" width="26" height="26">
  </a>

  <h1>Create your account</h1>

  <?php if ($error): ?>
    <div class="login-alert" role="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="login-notice"><?= htmlspecialchars($success) ?> <a href="login.php">Log in</a></div>
  <?php endif; ?>

  <?php if (!$success): ?>
  <form method="POST" class="login-form register-form">
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

      <div>
        <label for="year_level">Year Level</label>
        <select id="year_level" name="year_level" class="login-input">
          <option value="">Not a student / Faculty</option>
          <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => '5th Year'] as $y => $label): ?>
            <option value="<?= $y ?>" <?= post_param('year_level') === (string) $y ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="course">Course</label>
        <input type="text" id="course" name="course" class="login-input" list="course-options" placeholder="e.g. BSIT" value="<?= $old('course') ?>">
        <datalist id="course-options">
          <option value="BSIT"><option value="BSCS"><option value="BSCpE"><option value="BSECE"><option value="BSEE">
          <option value="BSA"><option value="BSBA"><option value="BSN"><option value="BSPsych"><option value="BSEd">
        </datalist>
      </div>

      <div class="register-full">
        <label for="organization_id">Organization <span class="login-optional">(optional)</span></label>
        <select id="organization_id" name="organization_id" class="login-input">
          <option value="">None</option>
          <?php foreach ($organizations as $o): ?>
            <option value="<?= htmlspecialchars($o['id']) ?>" <?= post_param('organization_id') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <button type="submit" class="login-btn login-submit">Sign Up</button>
  </form>
  <?php endif; ?>
</main>

<p class="login-signup">Already have an account? <a href="login.php">Log in</a></p>

</body>
</html>
