<?php
/** My Profile: name, year level, course, organization, and password. */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login();
$organizations = get_organizations();
$orgIds = array_column($organizations, 'id');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');

    if ($action === 'save_profile') {
        $fullName = post_param('full_name');
        $yearLevel = post_param('year_level');
        $course = post_param('course');
        $organizationId = post_param('organization_id');

        if ($fullName === '') $errors[] = 'Please enter your name.';
        if ($yearLevel !== '' && !in_array($yearLevel, ['1', '2', '3', '4', '5'], true)) $errors[] = 'Please choose a valid year level.';
        if ($organizationId !== '' && !in_array($organizationId, $orgIds, true)) $errors[] = 'Please choose an organization from the list.';

        if (!$errors) {
            $result = update_my_profile([
                'full_name' => $fullName,
                'year_level' => $yearLevel !== '' ? (int) $yearLevel : null,
                'course' => $course !== '' ? $course : null,
                'organization_id' => $organizationId !== '' ? $organizationId : null,
            ]);
            if ($result['ok']) {
                flash('success', $result['message']);
                header('Location: profile.php');
                exit;
            }
            $errors[] = $result['message'];
        }
    } elseif ($action === 'change_password') {
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        if (strlen($password) < 6) {
            $errors[] = 'Your new password must be at least 6 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'The new passwords do not match.';
        } else {
            $result = supabase_auth_update_password($password);
            if ($result['ok']) {
                flash('success', $result['message']);
                header('Location: profile.php');
                exit;
            }
            $errors[] = $result['message'];
        }
    }
    $user = current_user();
}

// Re-show what was typed after an error, otherwise the saved profile.
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_profile';
$val = fn(string $key): string => htmlspecialchars($posted ? post_param($key) : (string) ($user[$key] ?? ''));

$activeNav = 'profile';
$breadcrumbs = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['My Profile', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">My Profile</h1>
    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="profile-layout">
      <form method="POST" class="admin-card">
        <input type="hidden" name="action" value="save_profile">
        <h2 class="admin-card-title">Your details</h2>

        <div class="profile-head">
          <span class="avatar avatar-lg"><?= htmlspecialchars(initials($user['full_name'])) ?></span>
          <div>
            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="user-role"><?= htmlspecialchars($user['email'] ?? '') ?> · <?= htmlspecialchars(role_display($user['role'])) ?></div>
          </div>
        </div>

        <label class="admin-label" for="full_name">Full Name <span class="req">*</span></label>
        <input type="text" id="full_name" name="full_name" class="admin-input" required value="<?= $val('full_name') ?>">

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="year_level">Year Level</label>
            <select id="year_level" name="year_level" class="admin-input">
              <option value="">Not a student / Faculty</option>
              <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => '5th Year'] as $y => $label): ?>
                <option value="<?= $y ?>" <?= $val('year_level') === (string) $y ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="admin-label" for="course">Course</label>
            <input type="text" id="course" name="course" class="admin-input" placeholder="e.g. BSIT" value="<?= $val('course') ?>">
          </div>
        </div>

        <label class="admin-label" for="organization_id">Organization</label>
        <select id="organization_id" name="organization_id" class="admin-input">
          <option value="">None</option>
          <?php foreach ($organizations as $o): ?>
            <option value="<?= htmlspecialchars($o['id']) ?>" <?= $val('organization_id') === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-primary">Save profile</button>
        </div>
      </form>

      <form method="POST" class="admin-card">
        <input type="hidden" name="action" value="change_password">
        <h2 class="admin-card-title">Change password</h2>
        <label class="admin-label" for="password">New Password</label>
        <input type="password" id="password" name="password" class="admin-input" minlength="6" required autocomplete="new-password">
        <label class="admin-label" for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" class="admin-input" minlength="6" required autocomplete="new-password">
        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-outline">Change password</button>
        </div>
      </form>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
