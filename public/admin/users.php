<?php
/**
 * Super Admins: the accounts, and who has which role. Roles (profiles.role):
 *   Student / Faculty (user) — browse, register, evaluate
 *   Admin (moderator)        — runs their own organization's events; new
 *                              events need a Super Admin's approval
 *   Super Admin (admin)      — everything, and approvals
 * The database only lets Super Admins change roles, so the Roles page is how
 * someone becomes (or stops being) an Admin or a Super Admin. Each account
 * can also be edited (user-edit.php) or deleted, and a student's School ID
 * corrected or verified. The protected account (profiles.is_super_admin —
 * Arian Abergas) can't be demoted or deleted.
 *
 * Three pages (sidebar: Users, Roles, Approvals):
 *   users.php                 Users — the accounts and their details (School ID,
 *                             course, department); Edit / Delete. ?q= &type=
 *   users.php?tab=roles       Roles — what each role can do, how many have it,
 *                             and the place to change someone's role. ?q= &role=
 *   users.php?tab=approvals   Approvals — what waits for a Super Admin:
 *       &kind=events     Admins' new events (published once approved)
 *       &kind=profiles   profile edits (saved once approved)
 *       &status=approved|rejected  decided ones (default: waiting)
 * Users and Roles list 20 accounts per page (&page=).
 */
require_once __DIR__ . '/../../config/supabase.php';

$tab = match (get_param('tab')) { 'approvals' => 'approvals', 'roles' => 'roles', default => 'accounts' };
// Approvals: Super Admins (everything, and they decide) and Admins (their own
// organization's events and their own profile edits — the database's rules
// limit what they see). Users and Roles: Super Admins only.
$user = $tab === 'approvals' ? require_staff() : require_admin();
$isSuper = is_super_admin_user($user);

$kind = get_param('kind') === 'profiles' ? 'profiles' : 'events';
$decision = in_array(get_param('status'), ['approved', 'rejected'], true) ? get_param('status') : 'pending';
$approvalsUrl = fn(string $k, string $s = 'pending', int $page = 1): string
    => 'users.php?' . http_build_query(['tab' => 'approvals', 'kind' => $k] + ($s !== 'pending' ? ['status' => $s] : []) + ($page > 1 ? ['page' => $page] : []));

// Users: search + student / faculty. Roles: search + role.
$q = get_param('q');
$roleFilter = $tab === 'roles' && isset(ROLES[get_param('role')]) ? get_param('role') : '';
$typeFilter = $tab === 'accounts' && isset(ACCOUNT_TYPES[get_param('type')]) ? get_param('type') : '';
$page = max(1, (int) get_param('page', '1'));
$listUrl = fn(array $changes = []): string => 'users.php' . (($qs = http_build_query(array_filter($changes + [
    'tab' => $tab === 'roles' ? 'roles' : '', 'q' => $q, 'role' => $roleFilter, 'type' => $typeFilter, 'page' => $page > 1 ? $page : '',
], fn($v) => $v !== '' && $v !== null))) !== '' ? '?' . $qs : '');
$back = $listUrl(); // where a change on this list comes back to (same filters, same page)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    if (!$isSuper) {
        $result = ['ok' => false, 'message' => 'Only a Super Admin can do that.'];
    } elseif ($action === 'set_role') {
        $result = set_user_role_admin(post_param('id'), post_param('role'));
    } elseif ($action === 'delete_account') {
        $result = delete_account_admin(post_param('id'));
    } elseif ($action === 'set_school_id' && is_uuid(post_param('id'))) {
        $result = set_school_id_admin(post_param('id'), post_param('school_id'), isset($_POST['verified']));
    } elseif ($action === 'review_event' || $action === 'review_profile') {
        $approve = post_param('decision') === 'approve';
        $result = $action === 'review_event'
            ? review_event_admin(post_param('id'), $approve, post_param('note'))
            : review_profile_request(post_param('id'), $approve, post_param('note'));
    } elseif ($action === 'note_event' || $action === 'note_profile') {
        $result = save_approval_note($action === 'note_event' ? 'event' : 'profile', post_param('id'), post_param('note'));
    } else {
        $result = ['ok' => false, 'message' => 'Unknown action.'];
    }
    if (str_starts_with($action, 'review_') || str_starts_with($action, 'note_')) {
        // Back to the same approvals list (only links to this page's Approvals tab are accepted).
        $back = preg_match('~^users\.php\?tab=approvals(&[\w%.+\-=&]*)?$~', post_param('back')) ? post_param('back') : $approvalsUrl(str_ends_with($action, '_event') ? 'events' : 'profiles');
    }
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $back);
    exit;
}

$all = get_all_profiles_admin();
$names = array_column($all, 'full_name', 'id');
$protected = array_flip(protected_account_ids()); // can't be demoted or deleted

if ($tab === 'approvals') {
    // What's waiting (sub-tab counts, the sidebar's count) and the list shown.
    $waitingEvents = get_event_approvals_admin('pending');
    $approvalsReady = supabase_last_error() === '';
    $waitingProfiles = $approvalsReady ? get_profile_requests_admin('pending') : [];
    $_SESSION['approvals_waiting'] = ['count' => count($waitingEvents) + count($waitingProfiles), 'at' => time()];
    $departments = get_departments();
    $organizations = get_organizations();
    $rows = $decision === 'pending'
        ? ($kind === 'events' ? $waitingEvents : $waitingProfiles)
        : ($approvalsReady ? ($kind === 'events' ? get_event_approvals_admin($decision) : get_profile_requests_admin($decision)) : []);
    $pager = paginate($rows, 10, (int) get_param('page', '1'));
    $pageUrl = fn(int $p): string => $approvalsUrl($kind, $decision, $p);
    $here = $approvalsUrl($kind, $decision, $pager['page']);
} else {
    $profiles = array_values(array_filter($all, function ($p) use ($q, $roleFilter, $typeFilter) {
        if ($roleFilter !== '' && $p['role'] !== $roleFilter) return false;
        if ($typeFilter !== '' && ($p['account_type'] ?? '') !== $typeFilter) return false;
        return matches_search($q, [$p['full_name'] ?? '', $p['course'] ?? '', $p['organizations']['name'] ?? '', $p['departments']['name'] ?? '', $p['school_ids']['school_id'] ?? '']);
    }));
    $listPager = paginate($profiles, 20, $page);
    $listPageUrl = fn(int $p): string => $listUrl(['page' => $p > 1 ? $p : '']);
}
$roleCounts = array_count_values(array_column($all, 'role'));
$typeCounts = array_count_values(array_filter(array_column($all, 'account_type')));
$editSchoolId = get_param('school_id_for');

// Roles page: each role in three lines (the full comparison is folded under the cards).
$roleSummaries = [
    'user' => ['Browse the Event Calendar and register', 'Evaluate the events they attended', 'Profile changes wait for a Super Admin'],
    'moderator' => ['Run their own organization\'s events', 'Schedules, registrations, attendance, officers', 'New events wait for a Super Admin\'s approval'],
    'admin' => ['Everything, for every organization', 'Reports, users, roles, categories, school calendar', 'Approves Admins\' events and profile edits'],
];

$activeNav = ['approvals' => 'admin-approvals', 'roles' => 'admin-roles', 'accounts' => 'admin-users'][$tab];
$pageTitle = ['approvals' => 'Approvals', 'roles' => 'Roles', 'accounts' => 'Users'][$tab];
$breadcrumbs = [$isSuper ? ['Admin Dashboard', 'dashboard.php'] : ['Dashboard', '../enduser/dashboard.php'], [$pageTitle, null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title><?= htmlspecialchars($pageTitle) ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <div class="page-head">
      <div>
        <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
        <?php if ($tab === 'approvals'): ?>
          <p class="page-sub"><?= $isSuper ? 'Admins\' new events and profile edits waiting for a Super Admin' : 'Your organization\'s events and your profile edits, and what the Super Admins decided' ?></p>
        <?php elseif ($tab === 'roles'): ?>
          <p class="page-sub">What each role can do, and who has it</p>
        <?php else: ?>
          <p class="page-sub"><?= count($all) ?> accounts · <?= (int) ($typeCounts['student'] ?? 0) ?> student<?= ($typeCounts['student'] ?? 0) == 1 ? '' : 's' ?> · <?= (int) ($typeCounts['faculty'] ?? 0) ?> faculty</p>
        <?php endif; ?>
      </div>
      <?php if ($tab !== 'approvals'): ?>
      <form method="get" class="admin-search users-filter" role="search">
        <?php if ($tab === 'roles'): ?>
          <input type="hidden" name="tab" value="roles">
          <?php if ($roleFilter !== ''): ?><input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter) ?>"><?php endif; ?>
        <?php endif; ?>
        <input type="search" name="q" class="admin-input" placeholder="<?= $tab === 'roles' ? 'Find someone by name, org or department' : 'Name, School ID, course, org or department' ?>" value="<?= htmlspecialchars($q) ?>" aria-label="Search accounts">
        <?php if ($tab === 'accounts'): ?>
          <select name="type" class="admin-input" aria-label="Account type">
            <option value="">Students &amp; faculty</option>
            <?php foreach (ACCOUNT_TYPES as $key => $label): ?>
              <option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= $label ?>s</option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline">Search</button>
      </form>
      <?php endif; ?>
    </div>

    <?= render_flashes() ?>

    <?php if ($tab === 'approvals'): ?>
      <?php include __DIR__ . '/../../includes/approvals.php'; ?>

    <?php elseif ($tab === 'roles'): ?>

    <?php /* The three roles: how many have each, what it can do. A card filters the list below to that role. */ ?>
    <div class="role-cards">
      <?php foreach (ROLES as $key => $label): $on = $roleFilter === $key; ?>
        <a href="<?= htmlspecialchars($listUrl(['role' => $on ? '' : $key, 'page' => ''])) ?>" class="role-card role-card-<?= $key ?><?= $on ? ' is-active' : '' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
          <span class="role-card-head">
            <strong><?= htmlspecialchars($label) ?></strong>
            <span class="role-card-count"><?= (int) ($roleCounts[$key] ?? 0) ?></span>
          </span>
          <ul>
            <?php foreach ($roleSummaries[$key] as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?>
          </ul>
          <span class="role-card-filter"><?= $on ? 'Showing only these · show everyone' : 'Show only ' . htmlspecialchars($label === 'Student / Faculty' ? 'students & faculty' : $label . 's') ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <details class="admin-panel roles-panel">
      <summary><strong>Compare every permission</strong> <span class="admin-muted">— click to show</span></summary>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table-compact roles-table">
          <thead><tr><th>Can…</th><th>Student / Faculty</th><th>Admin</th><th>Super Admin</th></tr></thead>
          <tbody>
            <?php foreach ([
              ['Browse the Event Calendar, register, cancel, evaluate attended events', 'yes', 'yes', 'yes'],
              ['Create, edit and cancel events', 'no', 'Own organization', 'All events'],
              ['Publish new events', 'no', 'After a Super Admin approves', 'yes'],
              ['Save changes to their own profile', 'After a Super Admin approves', 'After a Super Admin approves', 'yes'],
              ['Delete events', 'no', 'no', 'yes'],
              ['Edit schedules, see registrations, mark attendance, print attendance sheets', 'no', 'Own organization', 'All events'],
              ['Import attendance from Excel', 'no', 'Own organization', 'All events'],
              ['See evaluation results', 'no', 'Own organization', 'All events'],
              ['Manage organization officers', 'no', 'Own organization', 'All organizations'],
              ['Import the student list and verify School IDs', 'no', 'no', 'yes'],
              ['Admin Dashboard, Reports', 'no', 'no', 'yes'],
              ['Edit the evaluation form and see its results', 'no', 'Own organization\x27s results', 'yes'],
              ['Categories, departments, organizations, school calendar', 'no', 'no', 'yes'],
              ['Change roles and School IDs, edit and delete accounts', 'no', 'no', 'yes'],
              ['Approve Admins\' new events and profile edits (Approvals)', 'no', 'no', 'yes'],
            ] as [$what, $u, $m, $a]): ?>
              <tr>
                <td><?= htmlspecialchars($what) ?></td>
                <?php foreach ([$u, $m, $a] as $cell): ?>
                  <td><?= $cell === 'yes' ? '<span class="perm-yes">✓</span>' : ($cell === 'no' ? '<span class="perm-no">—</span>' : '<span class="perm-some">' . htmlspecialchars($cell) . '</span>') ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="admin-hint">An Admin needs an organization — their events, officers and attendance imports are limited to it, and they can't move themselves to another organization. <?= $protected ? htmlspecialchars(implode(', ', array_intersect_key($names, $protected))) . '\'s account is protected: it can\'t be demoted or deleted. ' : '' ?>The database enforces all of this, not just these pages.</p>
    </details>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Department / Organization</th><th>Role</th><th>Change role</th></tr>
        </thead>
        <tbody>
          <?php if (empty($listPager['rows'])): ?>
            <tr><td colspan="4" class="admin-table-empty">No accounts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($listPager['rows'] as $p): $isMe = $p['id'] === $user['id']; ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($p['full_name']) ?></strong><?= $isMe ? ' <span class="admin-table-sub">(you)</span>' : '' ?>
                <?php if (!schema_outdated()): ?><div class="admin-table-sub"><?= htmlspecialchars(label_of(ACCOUNT_TYPES, $p['account_type'] ?? null, 'Type not set')) ?></div><?php endif; ?>
              </td>
              <td>
                <?= htmlspecialchars($p['departments']['name'] ?? '—') ?>
                <div class="admin-table-sub"><?= htmlspecialchars($p['organizations']['name'] ?? 'No organization') ?></div>
              </td>
              <td>
                <span class="admin-status role-<?= htmlspecialchars($p['role']) ?>"><?= htmlspecialchars(role_display($p['role'], $p['account_type'] ?? null)) ?></span>
              </td>
              <td class="admin-row-actions">
                <?php if ($isMe): ?>
                  <span class="admin-table-sub">You can't change your own role</span>
                <?php elseif (isset($protected[$p['id']])): ?>
                  <span class="admin-table-sub">Protected</span>
                <?php else: ?>
                  <form method="POST" action="<?= htmlspecialchars($back) ?>" class="role-form"
                        data-confirm="Change <?= htmlspecialchars($p['full_name']) ?> to {select:role}?" data-confirm-title="Change role" data-confirm-ok="Change role">
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <select name="role" class="admin-input role-select" aria-label="Role of <?= htmlspecialchars($p['full_name']) ?>">
                      <?php foreach (ROLES as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $p['role'] === $key ? 'selected' : '' ?> <?= $key === 'moderator' && empty($p['organization_id']) ? 'disabled' : '' ?>><?= $key === 'user' ? role_display('user', $p['account_type'] ?? null) : $label ?><?= $key === 'moderator' && empty($p['organization_id']) ? ' (needs an organization)' : '' ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-sm btn-sm-accent">Save</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($listPager, $listPageUrl, 'account', 'accounts') ?>

    <?php else: ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>School ID</th><th>Year &amp; Course</th><th>Department / Organization</th><th>Role</th><th><span class="visually-hidden">Actions</span></th></tr>
        </thead>
        <tbody>
          <?php if (empty($listPager['rows'])): ?>
            <tr><td colspan="6" class="admin-table-empty">No accounts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($listPager['rows'] as $p):
            $isMe = $p['id'] === $user['id'];
            $sid = $p['school_ids'] ?? null;
            $isStudent = ($p['account_type'] ?? '') === 'student';
            $incomplete = !schema_outdated() && profile_missing_fields($p + ['school_id' => $sid['school_id'] ?? null]);
          ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($p['full_name']) ?></strong><?= $isMe ? ' <span class="admin-table-sub">(you)</span>' : '' ?>
                <div class="admin-table-sub">
                  <?php if (!schema_outdated()): ?><?= htmlspecialchars(label_of(ACCOUNT_TYPES, $p['account_type'] ?? null, 'Type not set')) ?><?= $incomplete ? ' · <span class="text-warn">Profile incomplete</span>' : '' ?> · <?php endif; ?>Joined <?= htmlspecialchars(format_event_date($p['created_at'] ?? null)) ?>
                </div>
              </td>
              <td class="nowrap">
                <?php if ($editSchoolId === $p['id']): ?>
                  <form method="POST" action="<?= htmlspecialchars($back) ?>" class="inline-edit" autocomplete="off">
                    <input type="hidden" name="action" value="set_school_id">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <input type="text" name="school_id" class="admin-input" value="<?= htmlspecialchars($sid['school_id'] ?? '') ?>" placeholder="<?= SCHOOL_ID_EXAMPLE ?>" required maxlength="20" inputmode="numeric" title="Format: <?= SCHOOL_ID_EXAMPLE ?>" aria-label="School ID">
                    <label class="admin-checkbox"><input type="checkbox" name="verified" <?= !empty($sid['verified_at']) ? 'checked' : '' ?>> Verified</label>
                    <button type="submit" class="btn-sm btn-sm-accent">Save</button>
                    <a href="<?= htmlspecialchars($back) ?>" class="btn-sm">Cancel</a>
                  </form>
                <?php elseif (!empty($sid['school_id'])): ?>
                  <?= htmlspecialchars($sid['school_id']) ?>
                  <?= !empty($sid['verified_at']) ? '<span class="verify-badge is-verified">Verified</span>' : '<span class="verify-badge">Unverified</span>' ?>
                  <a href="<?= htmlspecialchars($back . (str_contains($back, '?') ? '&' : '?') . 'school_id_for=' . urlencode($p['id'])) ?>" class="admin-table-sub">Edit</a>
                <?php elseif ($isStudent): ?>
                  <span class="text-warn">Missing</span> <a href="<?= htmlspecialchars($back . (str_contains($back, '?') ? '&' : '?') . 'school_id_for=' . urlencode($p['id'])) ?>" class="admin-table-sub">Add</a>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= htmlspecialchars(trim(student_year_line($p) . ' ' . ($p['course'] ?? ''))) ?: '—' ?></td>
              <td>
                <?= htmlspecialchars($p['departments']['name'] ?? '—') ?>
                <div class="admin-table-sub"><?= htmlspecialchars($p['organizations']['name'] ?? 'No organization') ?></div>
              </td>
              <?php /* Read-only here: roles are changed on the Roles page. */ ?>
              <td><span class="admin-status role-<?= htmlspecialchars($p['role']) ?>"><?= htmlspecialchars(role_display($p['role'], $p['account_type'] ?? null)) ?></span></td>
              <td class="admin-row-actions user-actions">
                <a href="user-edit.php?id=<?= urlencode($p['id']) ?>&amp;back=<?= urlencode($back) ?>" class="btn-sm">Edit</a>
                <?php if (!$isMe && !isset($protected[$p['id']])): ?>
                  <form method="POST" action="<?= htmlspecialchars($back) ?>"
                        data-confirm="Delete <?= htmlspecialchars($p['full_name']) ?>’s account? They won’t be able to log in, and their registrations go with it. This can’t be undone." data-confirm-title="Delete account" data-confirm-ok="Delete account" data-confirm-tone="danger">
                    <input type="hidden" name="action" value="delete_account">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= render_pagination($listPager, $listPageUrl, 'account', 'accounts') ?>
    <p class="admin-hint">To make someone an Admin or a Super Admin, use <a href="users.php?tab=roles">Roles</a>.</p>
    <?php endif; ?>
  </main>

</div>

<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
