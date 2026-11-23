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
    unset($_SESSION['required_events_missing']); // my_required_event_ids() asks the database again
    $pending = pending_db_updates(true);
    flash($pending ? 'error' : 'success', $pending
        ? 'Still needed: ' . implode(', ', array_keys($pending)) . '. Run the SQL below (oldest first), then check again.'
        : 'The database is up to date. Everything is switched on.');
    header('Location: database-update.php');
    exit;
}

// "Send me a test email": checks config/mail.local.php end to end.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'test_email') {
    $to = (string) ($user['email'] ?? '');
    $sent = smtp_send([$to], 'Test email from Campus Event Hub',
        "This is a test from Database update › Site setup.\n\nIf you can read this, notification emails work.",
        '<p>This is a test from <strong>Database update › Site setup</strong>.</p><p>If you can read this, notification emails work.</p>');
    flash($sent['ok'] ? 'success' : 'error', $sent['ok'] ? 'Test email sent to ' . $to . '. Check the inbox (and spam folder).' : 'The test email failed: ' . $sent['error']);
    header('Location: database-update.php#setup');
    exit;
}

$pending = pending_db_updates(true);
$sqlOf = fn(string $file): string => (string) @file_get_contents(__DIR__ . '/../../supabase/migrations/' . $file);

// ---- Site setup: what's switched on outside the database ----
$projectUrl = 'https://supabase.com/dashboard/project/' . $projectRef;
$confirmEmail = auth_requires_email_confirmation();
$googleOn = auth_provider_enabled('google');
$googleCallback = rtrim(SUPABASE_URL, '/') . '/auth/v1/callback';
$siteRoot = rtrim(app_url(''), '/');
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'], true) || str_starts_with((string) ($_SERVER['HTTP_HOST'] ?? ''), 'localhost:');
// [setting, on (true / false / null = can't be checked from here), what to do, links (label => URL)], in the order to do them.
$setup = [
    ['1. Supabase\'s email sender', null,
        'Do this first. Supabase\'s built-in sender only emails the project\'s own team, so students get no confirmation or password emails until it\'s set: Authentication → Emails → SMTP Settings → Enable custom SMTP. Host smtp.gmail.com, port 465, username and sender email = the Gmail address, password = its App Password (the one in config/mail.local.php), sender name Campus Event Hub.',
        ['Open SMTP settings' => $projectUrl . '/auth/smtp']],
    ['2. Email confirmation for new accounts', $confirmEmail === true,
        $confirmEmail === true ? 'On: new accounts must open the link emailed to their @' . SCHOOL_EMAIL_DOMAIN . ' address before they can log in.'
            : 'Off: new accounts are active straight away, without confirming their email. After step 1, turn on Authentication → Sign In / Providers → Email → "Confirm email" (and save).',
        ['Open the Email provider' => $projectUrl . '/auth/providers']],
    ['3. Where the emailed links go', null,
        'Authentication → URL Configuration: set Site URL to ' . $siteRoot . ' and add ' . $siteRoot . '/** under Redirect URLs, so confirmation, password-reset and Google links come back to this site.',
        ['Open URL Configuration' => $projectUrl . '/auth/url-configuration']],
    ['4. Google sign-in', $googleOn,
        $googleOn ? 'On. Only @' . SCHOOL_EMAIL_DOMAIN . ' Google accounts get in.'
            : 'Off. In Google Cloud Console → APIs & Services → Credentials, create an OAuth client ID (Web application) with the Authorized redirect URI ' . $googleCallback . ', and publish the app (OAuth consent screen → Audience → Publish app) so every school account can use it. Then paste its Client ID and Client secret in Authentication → Sign In / Providers → Google and turn it on. Only @' . SCHOOL_EMAIL_DOMAIN . ' accounts will be let in.',
        $googleOn ? [] : ['Open Google Cloud credentials' => 'https://console.cloud.google.com/apis/credentials', 'Open the Google provider' => $projectUrl . '/auth/providers']],
    ['Email notifications', mail_enabled(),
        mail_enabled() ? 'On: sent from ' . mail_config()['from_email'] . (mail_method() === 'brevo' ? ' through Brevo.' : ' over SMTP (' . mail_config()['host'] . ').') : 'Off: set BREVO_API_KEY (Brevo) or a Gmail address and App Password: see config/mail.local.example.php.',
        []],
    ['Install on phones', $secure,
        $secure ? 'Ready: phones can install the site ("Install app" / "Add to Home screen").' : 'Phones can add a home-screen shortcut; the full "Install app" needs the site on HTTPS.',
        []],
];
$activeNav = '';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Database update', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
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

    <section class="admin-panel" id="setup" aria-labelledby="setupTitle">
      <h2 class="db-steps-title" id="setupTitle">Site setup</h2>
      <p class="admin-muted">Settings outside the database that the school-email accounts, the confirmation emails, Google sign-in and the email notifications need — in Supabase and Google, so they're switched on there (in this order), not from the site.</p>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead><tr><th>Setting</th><th>Status</th><th>What to do</th></tr></thead>
          <tbody>
            <?php foreach ($setup as [$label, $on, $note, $links]): ?>
              <tr>
                <td><strong><?= htmlspecialchars($label) ?></strong></td>
                <td><span class="admin-status admin-status-<?= $on === true ? 'published' : ($on === false ? 'cancelled' : 'draft') ?>"><?= $on === true ? 'On' : ($on === false ? 'Off' : 'Check') ?></span></td>
                <td><?= htmlspecialchars($note) ?> <?= implode(' · ', array_map(fn($text, $url) => '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($text) . '</a>', array_keys($links), $links)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (mail_enabled()): ?>
        <form method="POST" action="database-update.php" class="db-actions">
          <input type="hidden" name="action" value="test_email">
          <button type="submit" class="btn btn-outline">Send me a test email</button>
          <span class="admin-muted">to <?= htmlspecialchars((string) ($user['email'] ?? '')) ?></span>
        </form>
      <?php endif; ?>
    </section>

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
