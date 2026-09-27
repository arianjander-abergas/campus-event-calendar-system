<?php
/**
 * Admin: every account and its role. Since the database only lets admins
 * change roles, this page is how someone becomes (or stops being) an admin.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_role') {
    $result = set_user_role_admin(post_param('id'), post_param('role'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: users.php' . (get_param('q') !== '' ? '?q=' . urlencode(get_param('q')) : ''));
    exit;
}

$q = get_param('q');
$profiles = get_all_profiles_admin();
if ($q !== '') {
    $profiles = array_values(array_filter($profiles, fn($p) =>
        stripos($p['full_name'] ?? '', $q) !== false || stripos($p['course'] ?? '', $q) !== false || stripos($p['organizations']['name'] ?? '', $q) !== false));
}
$adminCount = count(array_filter($profiles, fn($p) => $p['role'] === 'admin'));

$activeNav = 'admin-users';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Users', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Users — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <div class="page-head">
      <div>
        <h1 class="page-title">Users</h1>
        <p class="page-sub"><?= count($profiles) ?> account<?= count($profiles) === 1 ? '' : 's' ?><?= $q !== '' ? ' matching "' . htmlspecialchars($q) . '"' : '' ?> · <?= $adminCount ?> admin<?= $adminCount === 1 ? '' : 's' ?></p>
      </div>
      <form method="get" class="admin-search" role="search">
        <input type="search" name="q" class="admin-input" placeholder="Search name, course or organization" value="<?= htmlspecialchars($q) ?>" aria-label="Search users">
        <button type="submit" class="btn btn-outline">Search</button>
      </form>
    </div>

    <?= render_flashes() ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Year &amp; Course</th><th>Organization</th><th>Joined</th><th>Role</th><th><span class="visually-hidden">Actions</span></th></tr>
        </thead>
        <tbody>
          <?php if (empty($profiles)): ?>
            <tr><td colspan="6" class="admin-table-empty">No accounts found.</td></tr>
          <?php endif; ?>
          <?php foreach ($profiles as $p): $isMe = $p['id'] === $user['id']; ?>
            <tr>
              <td><strong><?= htmlspecialchars($p['full_name']) ?></strong><?= $isMe ? ' <span class="admin-table-sub">(you)</span>' : '' ?></td>
              <td><?= htmlspecialchars(trim((!empty($p['year_level']) ? 'Year ' . $p['year_level'] . ' ' : '') . ($p['course'] ?? ''))) ?: '—' ?></td>
              <td><?= htmlspecialchars($p['organizations']['name'] ?? '—') ?></td>
              <td><?= htmlspecialchars(format_event_date($p['created_at'] ?? null)) ?></td>
              <td><span class="admin-status admin-status-<?= $p['role'] === 'admin' ? 'completed' : 'draft' ?>"><?= htmlspecialchars(role_display($p['role'])) ?></span></td>
              <td class="admin-row-actions">
                <?php if (!$isMe): ?>
                  <form method="POST" action="users.php<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
                        onsubmit="return confirm('<?= $p['role'] === 'admin' ? 'Remove admin rights from' : 'Make' ?> <?= htmlspecialchars(addslashes($p['full_name'])) ?><?= $p['role'] === 'admin' ? '' : ' an admin' ?>?');">
                    <input type="hidden" name="action" value="set_role">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($p['id']) ?>">
                    <?php if ($p['role'] === 'admin'): ?>
                      <button type="submit" name="role" value="user" class="btn-sm btn-sm-danger">Remove admin</button>
                    <?php else: ?>
                      <button type="submit" name="role" value="admin" class="btn-sm btn-sm-accent">Make admin</button>
                    <?php endif; ?>
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
<?= presence_script_tag() ?>
</body>
</html>
