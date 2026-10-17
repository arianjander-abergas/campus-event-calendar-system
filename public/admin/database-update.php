<?php
/**
 * Super Admin: the database updates this version needs (DB_UPDATES —
 * supabase/migrations), each marked Done or Needed, with the SQL of the
 * needed ones ready to copy into Supabase → SQL Editor. "Check again"
 * re-reads the database; once every update is in, the "Database update
 * needed" banner disappears.
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$projectRef = preg_match('~https://([a-z0-9]+)\.supabase\.co~', SUPABASE_URL, $m) ? $m[1] : '';
$sqlEditorUrl = 'https://supabase.com/dashboard/project/' . $projectRef . '/sql/new';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'check') {
    reload_session_profile();
    $pending = pending_db_updates(true);
    flash($pending ? 'error' : 'success', $pending
        ? 'Still needed: ' . implode(', ', array_keys($pending)) . '. Run the SQL below (oldest first), then check again.'
        : 'The database is up to date. Everything is switched on.');
    header('Location: database-update.php');
    exit;
}

$pending = pending_db_updates(true);
$sqlOf = fn(string $file): string => (string) @file_get_contents(__DIR__ . '/../../supabase/migrations/' . $file);
$activeNav = '';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Database update', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
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
        <p class="page-sub"><?= $pending ? 'Needed: ' . count($pending) . ' of ' . count(DB_UPDATES) . ' updates ' . (count($pending) === 1 ? 'hasn\'t' : 'haven\'t') . ' been run yet.' : 'Done: the database is up to date.' ?></p>
      </div>
      <form method="POST" action="database-update.php">
        <input type="hidden" name="action" value="check">
        <button type="submit" class="btn btn-outline">Check again</button>
      </form>
    </div>

    <?php foreach (take_flashes() as $f): ?>
      <div class="flash flash-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'error' ? 'error' : 'info') ?>" role="status"><?= htmlspecialchars($f['message']) ?></div>
    <?php endforeach; ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Update</th><th>Switches on</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach (DB_UPDATES as $file => [$label]): ?>
            <tr>
              <td><code><?= htmlspecialchars($file) ?></code></td>
              <td><?= htmlspecialchars($label) ?></td>
              <td><span class="admin-status admin-status-<?= isset($pending[$file]) ? 'cancelled' : 'published' ?>"><?= isset($pending[$file]) ? 'Needed' : 'Done' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pending): ?>
      <div class="admin-panel">
        <h2 class="db-steps-title">How to run <?= count($pending) === 1 ? 'it' : 'them' ?> (about a minute each)</h2>
        <ol class="db-steps">
          <li>Click <strong>Copy the SQL</strong> on the first update below.</li>
          <li>Click <strong>Open the Supabase SQL Editor</strong> and log in to Supabase if asked (project "Thesis").</li>
          <li>Paste into the editor and click <strong>Run</strong>. It should end with "Success. No rows returned" (or a small result).</li>
          <li><?= count($pending) > 1 ? 'Do the same for the next update, in this order. Then come' : 'Come' ?> back here and click <strong>Check again</strong>.</li>
        </ol>
        <p class="admin-hint">Each update is safe to run more than once. They only add things (columns, tables, rules) — no existing account or event is deleted.</p>
      </div>

      <?php $n = 0; foreach ($pending as $file => $label): $n++; $sql = $sqlOf($file); ?>
        <div class="admin-panel">
          <h2 class="db-steps-title"><?= $n ?>. <?= htmlspecialchars($label) ?></h2>
          <p class="admin-muted">supabase/migrations/<?= htmlspecialchars($file) ?></p>
          <?php if ($sql === ''): ?>
            <p class="admin-muted">That file wasn't found.</p>
          <?php else: ?>
            <div class="db-actions">
              <button type="button" class="btn btn-primary" data-copy-sql="dbSql<?= $n ?>">Copy the SQL</button>
              <a href="<?= htmlspecialchars($sqlEditorUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline">Open the Supabase SQL Editor</a>
              <span class="db-copied" data-copied hidden>Copied!</span>
            </div>
            <details>
              <summary class="db-sql-summary">Show the SQL</summary>
              <textarea class="admin-input db-sql" id="dbSql<?= $n ?>" rows="16" readonly spellcheck="false"><?= htmlspecialchars($sql) ?></textarea>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>

</div>

<script>
document.querySelectorAll('[data-copy-sql]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var box = document.getElementById(btn.getAttribute('data-copy-sql'));
    var note = btn.parentNode.querySelector('[data-copied]');
    var done = function () { note.hidden = false; setTimeout(function () { note.hidden = true; }, 2500); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(box.value).then(done);
    } else {
      box.closest('details').open = true; // a folded-away box can't be selected
      box.select();
      document.execCommand('copy'); // http://localhost isn't always a "secure context"
      done();
    }
  });
});
</script>
<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
