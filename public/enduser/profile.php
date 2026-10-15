<?php
/**
 * My Profile: name, student / faculty details (department, organization,
 * School ID, course, highest year level, regular / irregular), and password.
 * Accounts missing a required detail are sent here first (?complete=1)
 * — see profile_missing_fields().
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login();
$departments = get_departments();
$organizations = get_organizations();
$isAdmin = ($user['role'] ?? '') === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    $errors = [];

    if ($action === 'save_profile') {
        $fullName = post_param('full_name');
        if ($fullName === '') $errors[] = 'Please enter your name.';

        $account = validate_account_fields([
            // The student / faculty choice is fixed once made (only an admin changes it).
            'account_type' => !empty($user['account_type']) ? $user['account_type'] : post_param('account_type'),
            'department_id' => post_param('department_id'),
            'organization_id' => ($user['role'] ?? '') === 'moderator' ? (string) $user['organization_id'] : post_param('organization_id'),
            'school_id' => !empty($user['school_id_verified']) ? (string) $user['school_id'] : post_param('school_id'),
            'course' => post_param('course'),
            'year_level' => post_param('year_level'),
            'student_type' => post_param('student_type'),
            'irregular_year_levels' => $_POST['irregular_year_levels'] ?? [],
        ], $departments, $organizations, $user['school_id'] ?? null);
        // Admins don't have to belong to a department / organization.
        if ($isAdmin) {
            $account['errors'] = array_values(array_filter($account['errors'], fn($e) => !str_contains($e, 'department') && !str_contains($e, 'organization') && !str_contains($e, 'student or faculty')));
        }
        $errors = array_merge($errors, $account['errors']);

        if (!$errors) {
            $result = update_my_profile(['full_name' => $fullName] + $account['fields'], $account['school_id']);
            if ($result['ok']) {
                $stillMissing = profile_missing_fields(current_user());
                flash('success', $stillMissing ? 'Saved. Still missing: ' . implode(', ', $stillMissing) . '.' : (get_param('complete') !== '' ? 'Profile complete — welcome aboard!' : $result['message']));
                header('Location: ' . ($stillMissing ? 'profile.php?complete=1' : (get_param('complete') !== '' ? 'dashboard.php' : 'profile.php')));
                exit;
            }
            $errors[] = $result['message'];
        }
        remember_form('profile', ['full_name' => $fullName] + $account['fields'] + ['school_id' => $account['school_id'] ?? post_param('school_id')], $errors);
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
        remember_form('profile', [], $errors);
    }
    header('Location: profile.php' . (get_param('complete') !== '' ? '?complete=1' : ''));
    exit;
}

// Typed values after an error (shown once), otherwise the saved profile.
$state = recall_form('profile');
$errors = $state['errors'] ?? [];
$acct = ($state['values'] ?? []) ?: $user;
$missing = profile_missing_fields($user);
$completing = get_param('complete') !== '' || $missing;

$acctStyle = 'admin';
$acctTypeLocked = !empty($user['account_type']);
$acctOrgLocked = ($user['role'] ?? '') === 'moderator';
$acctSchoolIdLocked = !empty($user['school_id_verified']);
$acctOptional = $isAdmin; // admins don't need a department or organization
if ($acctTypeLocked) $acct['account_type'] = $user['account_type'];

$activeNav = 'profile';
$breadcrumbs = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['My Profile', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="neu">

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title"><?= $missing ? 'Complete your profile' : 'My Profile' ?></h1>
    <?= render_flashes() ?>
    <?php if ($missing): ?>
      <div class="admin-note">
        Before you continue, please fill in: <strong><?= htmlspecialchars(implode(', ', $missing)) ?></strong>.
        Every account needs a department and organization; students also need their School ID, course and highest year level.
      </div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="profile-layout">
      <form method="POST" action="profile.php<?= $completing ? '?complete=1' : '' ?>" class="admin-card" data-autoreset>
        <input type="hidden" name="action" value="save_profile">
        <h2 class="admin-card-title">Your details</h2>

        <div class="profile-head">
          <span class="avatar avatar-lg"><?= htmlspecialchars(initials($user['full_name'])) ?></span>
          <div>
            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="user-role"><?= htmlspecialchars($user['email'] ?? '') ?> · <?= htmlspecialchars(role_display($user['role'], $user['account_type'] ?? null)) ?></div>
            <?php if (($user['account_type'] ?? '') === 'student' && !empty($user['year_level'])): ?>
              <div class="profile-year">Highest year level: <strong><?= htmlspecialchars(student_year_line($user)) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($user['school_id'])): ?>
              <div class="profile-year">School ID: <strong><?= htmlspecialchars($user['school_id']) ?></strong>
                <?= !empty($user['school_id_verified']) ? '<span class="verify-badge is-verified">Verified</span>' : '<span class="verify-badge">Not verified yet</span>' ?></div>
            <?php endif; ?>
          </div>
        </div>

        <label class="admin-label" for="full_name">Full Name <span class="req">*</span></label>
        <input type="text" id="full_name" name="full_name" class="admin-input" required value="<?= htmlspecialchars((string) ($acct['full_name'] ?? $user['full_name'])) ?>">

        <?php include __DIR__ . '/../../includes/account-fields.php'; ?>

        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-primary"><?= $missing ? 'Save and continue' : 'Save profile' ?></button>
        </div>
      </form>

      <?php if (!$missing): ?>
      <form method="POST" action="profile.php" class="admin-card" data-autoreset autocomplete="off">
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
      <?php endif; ?>
    </div>
  </main>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/account.js?v=<?= filemtime(__DIR__ . '/../assets/js/account.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
