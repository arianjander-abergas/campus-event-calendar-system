<?php
/**
 * Admin: every account, its details and its role. Roles:
 *   Student / Faculty (user) — browse, register, evaluate
 *   Moderator — runs their own organization's events
 *   Admin — everything
 * The database only lets admins change roles, so this page is how someone
 * becomes (or stops being) a moderator or an admin. Admins can also correct
 * or verify a student's School ID here.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$q = get_param('q');
$roleFilter = isset(ROLES[get_param('role')]) ? get_param('role') : '';
$typeFilter = isset(ACCOUNT_TYPES[get_param('type')]) ? get_param('type') : '';
$back = 'users.php' . (($qs = http_build_query(array_filter(['q' => $q, 'role' => $roleFilter, 'type' => $typeFilter]))) !== '' ? '?' . $qs : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');
    if ($action === 'set_role') {
        $result = set_user_role_admin(post_param('id'), post_param('role'));
    } elseif ($action === 'set_school_id' && is_uuid(post_param('id'))) {
        $result = set_school_id_admin(post_param('id'), post_param('school_id'), isset($_POST['verified']));
    } else {
        $result = ['ok' => false, 'message' => 'Unknown action.'];
    }
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $back);
    exit;
}

$all = get_all_profiles_admin();
$profiles = array_values(array_filter($all, function ($p) use ($q, $roleFilter, $typeFilter) {
    if ($roleFilter !== '' && $p['role'] !== $roleFilter) return false;
    if ($typeFilter !== '' && ($p['account_type'] ?? '') !== $typeFilter) return false;
    if ($q === '') return true;
    foreach ([$p['full_name'] ?? '', $p['course'] ?? '', $p['organizations']['name'] ?? '', $p['departments']['name'] ?? '', $p['school_ids']['school_id'] ?? ''] as $text) {
        if (stripos($text, $q) !== false) return true;
    }
    return false;
}));
$roleCounts = array_count_values(array_column($all, 'role'));
$editSchoolId = get_param('school_id_for');

$activeNav = 'admin-users';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Users & Roles', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users &amp; Roles — Campus Event Hub</title>
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
        <h1 class="page-title">Users &amp; Roles</h1>
        <p class="page-sub"><?= count($all) ?> accounts · <?= (int) ($roleCounts['admin'] ?? 0) ?> admin<?= ($roleCounts['admin'] ?? 0) == 1 ? '' : 's' ?> · <?= (int) ($roleCounts['moderator'] ?? 0) ?> moderator<?= ($roleCounts['moderator'] ?? 0) == 1 ? '' : 's' ?></p>
      </div>
      <form method="get" class="admin-search users-filter" role="search">
        <input type="search" name="q" class="admin-input" placeholder="Name, School ID, course, org or department" value="<?= htmlspecialchars($q) ?>" aria-label="Search users">
        <select name="role" class="admin-input" aria-label="Role">
          <option value="">Every role</option>
          <?php foreach (ROLES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
        <select name="type" class="admin-input" aria-label="Account type">
          <option value="">Students &amp; faculty</option>
          <?php foreach (ACCOUNT_TYPES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $typeFilter === $key ? 'selected' : '' ?>><?= $label ?>s</option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline">Filter</button>
      </form>
    </div>

    <?= render_flashes() ?>

    <details class="admin-panel roles-panel">
      <summary><strong>What each role can do</strong> <span class="admin-muted">— click to show</span></summary>
      <div class="admin-table-wrap">
        <table class="admin-table admin-table-compact roles-table">
          <thead><tr><th>Can…</th><th>Student / Faculty</th><th>Moderator</th><th>Admin</th></tr></thead>
          <tbody>
            <?php foreach ([
              ['Browse the Event Calendar, register, cancel, evaluate attended events', 'yes', 'yes', 'yes'],
              ['Create, edit, publish and cancel events', 'no', 'Own organization', 'All events'],
              ['Delete events', 'no', 'no', 'yes'],
              ['Edit schedules, see registrations, mark attendance, print attendance sheets', 'no', 'Own organization', 'All events'],
              ['Import attendance from Excel', 'no', 'Own organization', 'All events'],
              ['See evaluation results', 'no', 'Own organization', 'All events'],
              ['Manage organization officers', 'no', 'Own organization', 'All organizations'],
              ['Import the student list and verify School IDs', 'no', 'no', 'yes'],
              ['Admin Dashboard, Reports, Evaluation Form', 'no', 'no', 'yes'],
              ['Categories, departments, organizations, school calendar', 'no', 'no', 'yes'],
              ['Change roles and School IDs', 'no', 'no', 'yes'],
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
      <p class="admin-hint">A moderator needs an organization — their events, officers and attendance imports are limited to it, and they can't move themselves to another organization. The database enforces all of this, not just these pages.</p>
    </details>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>School ID</th><th>Year &amp; Course</th><th>Department / Organization</th><th>Joined</th><th>Role</th><th><span class="visually-hidden">Actions</span></th></tr>
        </thead>
        <tbody>
          <?php if (empty($profiles)): ?>
            <tr><td colspan="7" class="admin-table-empty">No accounts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($profiles as $p):
            $isMe = $p['id'] === $user['id'];
            $sid = $p['school_ids'] ?? null;
            $isStudent = ($p['account_type'] ?? '') === 'student';
            $incomplete = !schema_outdated() && profile_missing_fields($p + ['school_id' => $sid['school_id'] ?? null]);
          ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($p['full_name']) ?></strong><?= $isMe ? ' <span class="admin-table-sub">(you)</span>' : '' ?>
                <?php if (!schema_outdated()): ?><div class="admin-table-sub"><?= htmlspecialchars(label_of(ACCOUNT_TYPES, $p['account_type'] ?? null, 'Type not set')) ?><?= $incomplete ? ' · <span class="text-warn">Profile incomplete</span>' : '' ?></div><?php endif; ?>
              </td>
              <td class="nowrap">
                <?php if ($editSchoolId === $p['id']): ?>
                  <form method="POST" action="<?= htmlspecialchars($back) ?>" class="inline-edit" autocomplete="off">
                    <input type="hidden" name="action" value="set_school_id">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <input type="text" name="school_id" class="admin-input" value="<?= htmlspecialchars($sid['school_id'] ?? '') ?>" placeholder="<?= SCHOOL_ID_EXAMPLE ?>" required maxlength="20" aria-label="School ID">
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
              <td class="nowrap"><?= htmlspecialchars(format_event_date($p['created_at'] ?? null)) ?></td>
              <td><span class="admin-status role-<?= htmlspecialchars($p['role']) ?>"><?= htmlspecialchars(role_display($p['role'], $p['account_type'] ?? null)) ?></span></td>
              <td class="admin-row-actions">
                <?php if (!$isMe): ?>
                  <form method="POST" action="<?= htmlspecialchars($back) ?>" class="role-form"
                        onsubmit="var s=this.role; return confirm('Change <?= htmlspecialchars(addslashes($p['full_name'])) ?> to ' + s.options[s.selectedIndex].text + '?');">
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
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/ui.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
