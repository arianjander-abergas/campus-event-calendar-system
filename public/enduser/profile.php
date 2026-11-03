<?php
/**
 * My Profile: the account's details (name, student / faculty, department,
 * organization, School ID, course, highest year level, regular / irregular).
 *   Edit my profile  -> edit-profile.php; changes wait for the super admin's
 *                       approval (the request's status is shown here)
 *   Change password  -> a pop-up
 * Accounts missing a required detail are sent to edit-profile.php?complete=1
 * first (require_login()).
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login();
$departments = get_departments();
$organizations = get_organizations();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    $errors = [];

    if ($action === 'withdraw_request') {
        $result = withdraw_profile_request(post_param('id'));
        flash($result['ok'] ? 'success' : 'error', $result['message']);
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
        remember_form('password', [], $errors); // shown in the Change password pop-up
    }
    header('Location: profile.php');
    exit;
}

// A password change that failed opens the pop-up again with the reason.
$passwordErrors = recall_form('password')['errors'] ?? [];

// The latest change request: waiting, or decided in the last two weeks.
$request = get_my_profile_request();
if ($request && $request['status'] !== 'pending'
    && (!in_array($request['status'], ['approved', 'rejected'], true) || strtotime((string) $request['reviewed_at']) < time() - 14 * 86400)) {
    $request = null;
}

$isStudent = ($user['account_type'] ?? '') === 'student';
$value = fn(string $key): string => profile_value_label($key, $user[$key] ?? null, $departments, $organizations);
$details = [
    'Full name' => $user['full_name'],
    'Email' => $user['email'] ?? '—',
    'Account' => role_display($user['role'], $user['account_type'] ?? null)
        . (is_super_admin_user($user) ? ' · Super admin' : (($user['role'] ?? '') !== 'user' && !empty($user['account_type']) ? ' · ' . label_of(ACCOUNT_TYPES, $user['account_type']) : '')),
    'Department' => $value('department_id'),
    'Organization' => $value('organization_id'),
];
if ($isStudent) {
    $details += [
        'School ID' => $value('school_id'),
        'Course' => $value('course'),
        'Highest year level' => $value('year_level'),
        'Student status' => $value('student_type') . (($user['student_type'] ?? '') === 'irregular' ? ' · subjects in ' . $value('irregular_year_levels') : ''),
    ];
}

$activeNav = 'profile';
$breadcrumbs = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['My Profile', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title>My Profile — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="neu">

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <h1 class="page-title">My Profile</h1>
    <?= render_flashes() ?>

    <div class="profile-layout">
      <?php if ($request && $request['status'] === 'pending'): ?>
        <!-- Changes waiting for a Super Admin -->
        <section class="admin-card profile-request is-pending" aria-labelledby="request-title">
          <h2 class="admin-card-title" id="request-title">Changes waiting for approval</h2>
          <p class="admin-hint">Sent <?= htmlspecialchars(format_timestamp($request['created_at'])) ?>. A Super Admin will approve or reject them; until then your profile keeps its current details.</p>
          <?php if (!empty($request['review_note'])): ?>
            <p class="admin-note"><strong>Note from a Super Admin:</strong> “<?= htmlspecialchars($request['review_note']) ?>” — <a href="edit-profile.php">change the request</a>.</p>
          <?php endif; ?>
          <div class="admin-table-wrap">
            <table class="admin-table admin-table-compact">
              <thead><tr><th>Detail</th><th>Now</th><th>Change to</th></tr></thead>
              <tbody>
                <?php foreach ($request['changes'] as $key => $new): if (!isset(PROFILE_DETAIL_LABELS[$key])) continue; ?>
                  <tr>
                    <td><?= htmlspecialchars(PROFILE_DETAIL_LABELS[$key]) ?></td>
                    <td><?= htmlspecialchars($value($key)) ?></td>
                    <td><strong><?= htmlspecialchars(profile_value_label($key, $new, $departments, $organizations)) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="admin-actions admin-actions-start">
            <a href="edit-profile.php" class="btn btn-outline">Change the request</a>
            <form method="POST" action="profile.php" data-confirm="Withdraw these changes? Your profile stays as it is." data-confirm-title="Withdraw changes" data-confirm-ok="Withdraw" data-confirm-cancel="Keep waiting">
              <input type="hidden" name="action" value="withdraw_request">
              <input type="hidden" name="id" value="<?= htmlspecialchars($request['id']) ?>">
              <button type="submit" class="btn btn-muted">Withdraw</button>
            </form>
          </div>
        </section>
      <?php elseif ($request): ?>
        <div class="flash flash-<?= $request['status'] === 'approved' ? 'success' : 'error' ?>" role="status">
          <?= $request['status'] === 'approved'
            ? 'Your profile changes were approved on ' . htmlspecialchars(format_timestamp($request['reviewed_at'])) . '.'
            : 'Your profile changes sent on ' . htmlspecialchars(format_timestamp($request['created_at'])) . ' weren\'t approved.' ?>
          <?php if (!empty($request['review_note'])): ?> Note: “<?= htmlspecialchars($request['review_note']) ?>”<?php endif; ?>
        </div>
      <?php endif; ?>

      <section class="admin-card" aria-labelledby="details-title">
        <h2 class="admin-card-title" id="details-title">Your details</h2>

        <div class="profile-head">
          <span class="avatar avatar-lg"><?= htmlspecialchars(initials($user['full_name'])) ?></span>
          <div>
            <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="user-role"><?= htmlspecialchars($user['email'] ?? '') ?> · <?= htmlspecialchars(role_display($user['role'], $user['account_type'] ?? null)) ?></div>
            <?php if ($isStudent && !empty($user['school_id'])): ?>
              <div class="profile-year">School ID: <strong><?= htmlspecialchars($user['school_id']) ?></strong>
                <?= !empty($user['school_id_verified']) ? '<span class="verify-badge is-verified">Verified</span>' : '<span class="verify-badge">Not verified yet</span>' ?></div>
            <?php endif; ?>
          </div>
          <div class="profile-head-actions">
            <a href="edit-profile.php" class="btn btn-primary">Edit my profile</a>
            <button type="button" class="btn btn-outline" data-password-open aria-haspopup="dialog">Change password</button>
          </div>
        </div>

        <dl class="profile-details">
          <?php foreach ($details as $label => $text): ?>
            <div><dt><?= htmlspecialchars($label) ?></dt><dd><?= htmlspecialchars($text) ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <?php if (profile_edits_need_approval($user)): ?>
          <p class="admin-hint">Changes to your details are checked by a Super Admin before they're saved.</p>
        <?php endif; ?>
      </section>
    </div>

    <!-- Change password: pop-up opened by the button in "Your details" -->
    <dialog class="acct-modal admin-modal" id="password-modal" aria-labelledby="password-modal-title"<?= $passwordErrors ? ' data-open' : '' ?>>
      <form method="POST" action="profile.php" data-autoreset autocomplete="off" novalidate>
        <input type="hidden" name="action" value="change_password">
        <div class="admin-modal-head">
          <h2 id="password-modal-title">Change password</h2>
          <button type="button" class="admin-modal-close" data-modal-close aria-label="Close"><?= icon('close', 18) ?></button>
        </div>
        <p>Use at least 6 characters. You'll log in with the new password from now on.</p>
        <p class="acct-modal-error" role="alert" data-password-error<?= $passwordErrors ? '' : ' hidden' ?>><?= htmlspecialchars(implode(' ', $passwordErrors)) ?></p>

        <label class="admin-label" for="password">New Password</label>
        <input type="password" id="password" name="password" class="admin-input" minlength="6" required autocomplete="new-password">
        <label class="admin-label" for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" class="admin-input" minlength="6" required autocomplete="new-password">
        <label class="password-show"><input type="checkbox" data-password-show> Show passwords</label>

        <div class="acct-modal-actions">
          <button type="button" class="btn btn-outline" data-modal-close>Cancel</button>
          <button type="submit" class="btn btn-primary">Change password</button>
        </div>
      </form>
    </dialog>
  </main>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
// Change password pop-up. The passwords are checked here (a message in the
// pop-up instead of the browser's bubbles) and again by the server.
(function () {
  var modal = document.getElementById('password-modal');
  if (!modal) return;
  var form = modal.querySelector('form');
  var error = modal.querySelector('[data-password-error]');
  var show = modal.querySelector('[data-password-show]');
  var fields = [form.elements.password, form.elements.confirm_password];

  function open() { if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', ''); }
  function close() { if (typeof modal.close === 'function') modal.close(); else modal.removeAttribute('open'); }
  function showError(message, field) {
    error.textContent = message;
    error.hidden = !message;
    fields.forEach(function (f) {
      f.classList.toggle('is-invalid', f === field);
      if (f === field) f.setAttribute('aria-invalid', 'true'); else f.removeAttribute('aria-invalid');
    });
    if (field) field.focus();
  }

  document.querySelectorAll('[data-password-open]').forEach(function (b) {
    b.addEventListener('click', function () { open(); fields[0].focus(); });
  });
  modal.querySelectorAll('[data-modal-close]').forEach(function (b) { b.addEventListener('click', close); });
  // A click on the dark backdrop (outside the box) closes it too.
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  // Closed any way (also Esc): nothing typed is kept.
  modal.addEventListener('close', function () { form.reset(); showError(''); });
  form.addEventListener('reset', function () { fields.forEach(function (f) { f.type = 'password'; }); });
  show.addEventListener('change', function () { fields.forEach(function (f) { f.type = show.checked ? 'text' : 'password'; }); });
  fields.forEach(function (f) { f.addEventListener('input', function () { if (!error.hidden) showError(''); }); });

  form.addEventListener('submit', function (e) {
    var message = '', field = null;
    if (fields[0].value.length < 6) { message = 'Your new password must be at least 6 characters.'; field = fields[0]; }
    else if (fields[1].value !== fields[0].value) { message = 'The new passwords do not match.'; field = fields[1]; }
    if (!message) return;
    e.preventDefault();
    showError(message, field);
  });

  // A change the server turned down: open again with its message.
  if (modal.hasAttribute('data-open')) { open(); fields[0].focus(); }
})();
</script>
<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
