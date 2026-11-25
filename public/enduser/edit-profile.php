<?php
/**
 * Edit my profile (from My Profile): name, student / faculty details
 * (department, organization, School ID, course, highest year level,
 * regular / irregular). Changes to saved details go to the super admin for
 * approval (save_profile_details()); the super admin's own are saved.
 * Accounts missing a required detail are sent here first (?complete=1 —
 * see profile_missing_fields()); filling those in is saved straight away.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login();
$departments = get_departments();
$organizations = get_organizations();
$isAdmin = ($user['role'] ?? '') === 'admin';
$isSuper = is_super_admin_user($user);
$missing = profile_missing_fields($user);
$completing = (bool) $missing;
$selfUrl = 'edit-profile.php' . ($completing ? '?complete=1' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_profile') {
    $errors = [];
    // The name: Last / First / Middle once the database has them (full name made from them), else one Full Name.
    if (name_parts_ready()) {
        $name = name_parts_from_post();
        $errors = name_parts_errors($name);
    } else {
        $name = ['full_name' => post_param('full_name')];
        if ($name['full_name'] === '') $errors[] = 'Please enter your name.';
    }
    $fullName = $name['full_name'];

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
        'other_organization_ids' => $_POST['other_organization_ids'] ?? [],
    ], $departments, $organizations, $user['school_id'] ?? null);
    // Admins don't have to belong to a department / organization.
    if ($isAdmin) {
        $account['errors'] = array_values(array_filter($account['errors'], fn($e) => !str_contains($e, 'department') && !str_contains($e, 'organization') && !str_contains($e, 'student or faculty')));
    }
    $errors = array_merge($errors, $account['errors']);

    if (!$errors) {
        $result = save_profile_details($name + $account['fields'] + ['school_id' => $account['school_id']], $completing);
        if ($result['ok']) {
            if ($completing) {
                $stillMissing = profile_missing_fields(current_user());
                flash('success', $stillMissing ? 'Saved. Still missing: ' . implode(', ', $stillMissing) . '.' : 'Profile complete — welcome aboard!');
                header('Location: ' . ($stillMissing ? 'edit-profile.php?complete=1' : 'dashboard.php'));
                exit;
            }
            flash(!empty($result['unchanged']) ? 'info' : 'success', $result['message']);
            header('Location: profile.php');
            exit;
        }
        $errors[] = $result['message'];
    }
    remember_form('profile', $name + $account['fields'] + ['school_id' => $account['school_id'] ?? post_param('school_id')], $errors);
    header('Location: ' . $selfUrl);
    exit;
}

// Typed values after an error (shown once); otherwise the changes waiting
// for approval (so they can be adjusted), otherwise the saved profile.
$state = recall_form('profile');
$errors = $state['errors'] ?? [];
$pending = $completing ? null : get_my_profile_request('pending');
$acct = ($state['values'] ?? []) ?: array_merge($user, $pending['changes'] ?? []);

$acctStyle = 'admin';
$acctTypeLocked = !empty($user['account_type']);
$acctOrgLocked = ($user['role'] ?? '') === 'moderator';
$acctSchoolIdLocked = !empty($user['school_id_verified']);
$acctOptional = $isAdmin; // admins don't need a department or organization
if ($acctTypeLocked) $acct['account_type'] = $user['account_type'];
$needsApproval = !$completing && profile_edits_need_approval($user);

$activeNav = 'profile';
$breadcrumbs = $completing
    ? [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['Complete your profile', null]]
    : [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['My Profile', BASE_URL . '/enduser/profile.php'], ['Edit my profile', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title><?= $completing ? 'Complete your profile' : 'Edit my profile' ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="neu">

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title"><?= $completing ? 'Complete your profile' : 'Edit my profile' ?></h1>
    <?= render_flashes() ?>
    <?php if ($completing): ?>
      <div class="admin-note">
        Before you continue, please fill in: <strong><?= htmlspecialchars(implode(', ', $missing)) ?></strong>.
        Every account needs a department and organization; students also need their School ID, course and highest year level.
      </div>
    <?php elseif ($pending): ?>
      <div class="admin-note">
        <strong>Your changes from <?= htmlspecialchars(format_timestamp($pending['created_at'])) ?> are waiting for approval.</strong>
        They're filled in below — sending again replaces them.
      </div>
    <?php elseif ($needsApproval): ?>
      <div class="admin-note">
        Changes to your profile are checked by a Super Admin before they're saved.
        Until then, your profile keeps its current details.
      </div>
    <?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="profile-layout">
      <form method="POST" action="<?= $selfUrl ?>" class="admin-card" data-autoreset>
        <input type="hidden" name="action" value="save_profile">
        <h2 class="admin-card-title">Your details</h2>

        <div class="profile-head">
          <span class="avatar avatar-lg"><?= htmlspecialchars(initials($user['full_name'])) ?></span>
          <div>
            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="user-role"><?= htmlspecialchars($user['email'] ?? '') ?> · <?= htmlspecialchars(role_display($user['role'], $user['account_type'] ?? null)) ?></div>
          </div>
        </div>

        <?php if (name_parts_ready()): ?>
          <?php $nameValues = $acct + $user; include __DIR__ . '/../../includes/name-fields.php'; ?>
        <?php else: ?>
          <label class="admin-label" for="full_name">Full Name <span class="req">*</span></label>
          <input type="text" id="full_name" name="full_name" class="admin-input" required value="<?= htmlspecialchars((string) ($acct['full_name'] ?? $user['full_name'])) ?>">
        <?php endif; ?>

        <?php include __DIR__ . '/../../includes/account-fields.php'; ?>

        <div class="admin-actions admin-actions-start">
          <?php if (!$completing): ?><a href="profile.php" class="btn btn-outline">Cancel</a><?php endif; ?>
          <button type="submit" class="btn btn-primary"><?= $completing ? 'Save and continue' : ($needsApproval ? 'Send for approval' : 'Save changes') ?></button>
        </div>
      </form>
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
