<?php
/**
 * Super Admin: edit one account (Users & Roles › Edit) — name, role,
 * student / faculty, department, organization, and for students the School
 * ID (verified or not), course, highest year level and regular / irregular.
 * Saved straight away (no approval needed). The protected account
 * (profiles.is_super_admin) keeps its Super Admin role; nobody changes their
 * own role here.
 *   user-edit.php?id=<account>&back=<users.php?… list to return to>
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();
$departments = get_departments();
$organizations = get_organizations();

$back = get_param('back', post_param('back'));
if (!preg_match('~^users\.php(\?[\w%.+\-=&]*)?$~', $back)) $back = 'users.php';
$account = get_profile_admin(get_param('id'));
if (!$account) {
    flash('error', "That account doesn't exist anymore.");
    header('Location: ' . $back);
    exit;
}
$isMe = $account['id'] === $user['id'];
$isProtected = in_array($account['id'], protected_account_ids(), true);
$roleLocked = $isMe || $isProtected;
$selfUrl = 'user-edit.php?' . http_build_query(['id' => $account['id'], 'back' => $back]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_account') {
    $errors = [];
    $fullName = post_param('full_name');
    if ($fullName === '') $errors[] = 'Please enter their name.';
    $role = $roleLocked ? $account['role'] : post_param('role');
    if (!isset(ROLES[$role])) $errors[] = 'Please choose a role.';

    $result = validate_account_fields([
        'account_type' => post_param('account_type'),
        'department_id' => post_param('department_id'),
        'organization_id' => post_param('organization_id'),
        'school_id' => post_param('school_id'),
        'course' => post_param('course'),
        'year_level' => post_param('year_level'),
        'student_type' => post_param('student_type'),
        'irregular_year_levels' => $_POST['irregular_year_levels'] ?? [],
    ], $departments, $organizations, $account['school_id'] ?? null);
    $accountErrors = $result['errors'];
    // Super Admins don't have to belong to a department / organization.
    if ($role === 'admin') {
        $accountErrors = array_values(array_filter($accountErrors, fn($e) => !str_contains($e, 'department') && !str_contains($e, 'organization') && !str_contains($e, 'student or faculty')));
    }
    if ($role === 'moderator' && empty($result['fields']['organization_id'])) {
        $accountErrors[] = 'An Admin runs their organization\'s events, so choose their organization.';
    }
    $errors = array_merge($errors, $accountErrors);

    if (!$errors) {
        $saved = update_profile_admin($account, ['full_name' => $fullName] + $result['fields'], $result['school_id'], isset($_POST['verified']));
        if ($saved['ok'] && $role !== $account['role']) {
            $changed = set_user_role_admin($account['id'], $role);
            $saved = $changed['ok'] ? ['ok' => true, 'message' => $saved['message'] . ' ' . $changed['message']] : ['ok' => false, 'message' => 'The details were saved, but not the role: ' . $changed['message']];
        }
        if ($saved['ok']) {
            flash('success', $saved['message']);
            header('Location: ' . $back);
            exit;
        }
        $errors[] = $saved['message'];
    }
    remember_form('user-edit', ['full_name' => $fullName, 'role' => $role, 'verified' => isset($_POST['verified'])] + $result['fields'] + ['school_id' => $result['school_id'] ?? post_param('school_id')], $errors);
    header('Location: ' . $selfUrl);
    exit;
}

// Typed values after an error (shown once), otherwise the saved account.
$state = recall_form('user-edit');
$errors = $state['errors'] ?? [];
$acct = ($state['values'] ?? []) ?: $account;
$verified = isset($state['values']) ? !empty($acct['verified']) : !empty($account['school_id_verified']);
$roleValue = $acct['role'] ?? $account['role'];

$acctStyle = 'admin';
$acctTypeLocked = false;
$acctOrgLocked = false;
$acctSchoolIdLocked = false;
$acctOptional = $roleValue === 'admin';

$activeNav = 'admin-users';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Users & Roles', $back], ['Edit account', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title>Edit account — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">Edit account</h1>
    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <div class="profile-layout">
      <form method="POST" action="<?= htmlspecialchars($selfUrl) ?>" class="admin-card" autocomplete="off">
        <input type="hidden" name="action" value="save_account">
        <input type="hidden" name="back" value="<?= htmlspecialchars($back) ?>">

        <div class="profile-head">
          <span class="avatar avatar-lg"><?= htmlspecialchars(initials($account['full_name'])) ?></span>
          <div>
            <div class="user-name"><?= htmlspecialchars($account['full_name']) ?><?= $isMe ? ' <span class="admin-table-sub">(you)</span>' : '' ?></div>
            <div class="user-role"><?= htmlspecialchars(role_display($account['role'], $account['account_type'] ?? null)) ?><?= $isProtected ? ' · Protected account' : '' ?></div>
          </div>
        </div>
        <p class="admin-hint">Changes made here are saved straight away — they don't need approval.</p>

        <div class="acct-grid">
          <div>
            <label class="admin-label" for="full_name">Full Name <span class="req">*</span></label>
            <input type="text" id="full_name" name="full_name" class="admin-input" required value="<?= htmlspecialchars((string) ($acct['full_name'] ?? '')) ?>">
          </div>
          <div>
            <label class="admin-label" for="role">Role <span class="req">*</span></label>
            <select id="role" name="role" class="admin-input"<?= $roleLocked ? ' disabled' : '' ?>>
              <?php foreach (ROLES as $key => $label): ?>
                <option value="<?= $key ?>" <?= $roleValue === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($roleLocked): ?><p class="admin-hint"><?= $isMe ? "You can't change your own role." : "This account is protected: it stays a Super Admin." ?></p><?php endif; ?>
          </div>
        </div>

        <?php include __DIR__ . '/../../includes/account-fields.php'; ?>

        <label class="admin-checkbox user-edit-verified" data-verified-box<?= ($acct['account_type'] ?? '') === 'student' ? '' : ' hidden' ?>>
          <input type="checkbox" name="verified" <?= $verified ? 'checked' : '' ?>> School ID verified (matches the registrar's list)
        </label>

        <div class="admin-actions admin-actions-start">
          <a href="<?= htmlspecialchars($back) ?>" class="btn btn-outline">Cancel</a>
          <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
      </form>
    </div>
  </main>

</div>

<script>
// "School ID verified" only for students (account.js shows / hides the other student fields).
document.querySelectorAll('input[name="account_type"]').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelector('[data-verified-box]').hidden = !(r.checked && r.value === 'student');
  });
});
</script>
<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/account.js?v=<?= filemtime(__DIR__ . '/../assets/js/account.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
