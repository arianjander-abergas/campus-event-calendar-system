<?php
/**
 * Admin: the database update this version needs
 * (supabase/migrations/20260930120000_solis_suggestions.sql), ready to copy
 * into Supabase → SQL Editor. "Check again" re-reads the database; once the
 * update is in, the "Database update needed" banner disappears.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

const DB_UPDATE_FILE = '20260930120000_solis_suggestions.sql';
$sql = (string) @file_get_contents(__DIR__ . '/../../supabase/migrations/' . DB_UPDATE_FILE);
$projectRef = preg_match('~https://([a-z0-9]+)\.supabase\.co~', SUPABASE_URL, $m) ? $m[1] : '';
$sqlEditorUrl = 'https://supabase.com/dashboard/project/' . $projectRef . '/sql/new';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'check') {
    reload_session_profile();
    flash(schema_outdated() ? 'error' : 'success', schema_outdated()
        ? 'The database still doesn\'t have the update. Run the SQL below, then check again.'
        : 'The database is up to date. Everything is switched on.');
    header('Location: database-update.php');
    exit;
}

$outdated = schema_outdated();
$activeNav = '';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Database update', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database update — Campus Event Hub</title>
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
        <h1 class="page-title">Database update</h1>
        <p class="page-sub"><?= $outdated ? 'Needed: this version of the site adds new database columns and rules.' : 'Done: the database is up to date.' ?></p>
      </div>
      <form method="POST" action="database-update.php">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn btn-outline">Check again</button>
      </form>
    </div>

    <?php foreach (take_flashes() as $f): ?>
      <div class="flash flash-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'error' : 'info') ?>" role="status"><?= htmlspecialchars($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if ($outdated): ?>
      <div class="admin-panel">
        <h2 class="db-steps-title">How to run it (about a minute)</h2>
        <ol class="db-steps">
          <li>Click <strong>Copy the SQL</strong> below.</li>
          <li>Click <strong>Open the Supabase SQL Editor</strong> and log in to Supabase if asked (project "Thesis").</li>
          <li>Paste into the editor and click <strong>Run</strong>. It should end with "Success. No rows returned" (or a small result).</li>
          <li>Come back here and click <strong>Check again</strong>.</li>
        </ol>
        <p class="admin-hint">It's safe to run more than once. It only adds things (new columns, the moderator role, School IDs, year levels, the duplicate / double-booking checks and auto-completion) — no existing account or event is deleted. Events that are already over are marked Completed.</p>
        <div class="db-actions">
          <button type="button" class="btn btn-primary" data-copy-sql>Copy the SQL</button>
          <a href="<?= htmlspecialchars($sqlEditorUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline">Open the Supabase SQL Editor</a>
          <span class="db-copied" data-copied hidden>Copied!</span>
        </div>
      </div>
    <?php endif; ?>

    <details class="admin-panel"<?= $outdated ? ' open' : '' ?>>
      <summary class="db-sql-summary">The SQL (supabase/migrations/<?= DB_UPDATE_FILE ?>)</summary>
      <?php if ($sql === ''): ?>
        <p class="admin-muted">The file supabase/migrations/<?= DB_UPDATE_FILE ?> wasn't found.</p>
      <?php else: ?>
        <textarea class="admin-input db-sql" id="dbSql" rows="18" readonly spellcheck="false"><?= htmlspecialchars($sql) ?></textarea>
      <?php endif; ?>
    </details>
  </main>

</div>

<script>
document.querySelector('[data-copy-sql]')?.addEventListener('click', function () {
  var box = document.getElementById('dbSql');
  var done = function () { var n = document.querySelector('[data-copied]'); n.hidden = false; setTimeout(function () { n.hidden = true; }, 2500); };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(box.value).then(done);
  } else {
    box.select();
    document.execCommand('copy'); // http://localhost isn't always a "secure context"
    done();
  }
});
</script>
<script src="../assets/js/sidebar.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
