<?php
/**
 * Import & Verify: upload an Excel (.xlsx) or CSV file and compare it with
 * what's in the system, row by row.
 *   ?type=roster      (admins) the registrar's list of enrolled students vs.
 *                     the student accounts: matched, details that differ,
 *                     not registered yet, accounts missing from the list.
 *                     Matched accounts can be marked "verified" in one click.
 *   ?type=attendance  (admins + the event's moderators) an attendance sheet
 *                     (e.g. from a scanner or a Google Form) vs. an event's
 *                     registrations: mark the people on it Attended, see
 *                     walk-ins, and mark registered people who aren't on it
 *                     as No-show.
 * Rows are matched by School ID, or by name when the file has no School ID
 * column. The uploaded rows are kept in the session until another file is
 * uploaded, so the results can be re-checked and acted on.
 */
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/spreadsheet.php';

$user = require_staff();
$isAdmin = ($user['role'] ?? '') === 'admin';

$type = get_param('type', $isAdmin ? 'roster' : 'attendance');
$type = ($type === 'roster' && $isAdmin) ? 'roster' : 'attendance';
$eventId = get_param('event');
$event = null;
if ($type === 'attendance' && $eventId !== '') {
    $event = is_uuid($eventId) ? get_event_admin($eventId) : null;
    if (!$event || !can_manage_event($event, $user)) {
        flash('error', $event ? "You can only import attendance for your organization's events." : "That event doesn't exist anymore.");
        header('Location: import.php?type=attendance');
        exit;
    }
}
$self = 'import.php?' . http_build_query(array_filter(['type' => $type, 'event' => $event['id'] ?? '']));

// ---- Templates: a header row + an example row ----
if (get_param('template') !== '') {
    if (get_param('template') === 'attendance') {
        send_csv('attendance-template.csv', ['School ID', 'Name', 'Time In'], [[SCHOOL_ID_EXAMPLE, 'Juan Dela Cruz', '8:05 AM']]);
    }
    send_csv('student-list-template.csv', ['School ID', 'Last Name', 'First Name', 'Middle Name', 'Course', 'Year Level'], [[SCHOOL_ID_EXAMPLE, 'Dela Cruz', 'Juan', 'Santos', 'BSIT', '3']]);
}

// The uploaded file (kept in the session) and its comparison.
session_start_once();
$import = $_SESSION['import'] ?? null;
if ($import && ($import['type'] !== $type || ($type === 'attendance' && ($import['event_id'] ?? '') !== ($event['id'] ?? '')))) {
    $import = null; // belongs to the other tab / another event
}
$compare = function () use (&$import, $type, $event): array {
    $hasId = isset($import['columns']['school_id']);
    return $type === 'roster'
        ? compare_roster($import['records'], get_student_accounts_admin(), $hasId)
        : compare_attendance($import['records'], get_event_registrations_admin($event['id']), $hasId);
};

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_param('action');

    if ($action === 'upload') {
        $file = $_FILES['file'] ?? [];
        if ($type === 'attendance' && !$event) {
            flash('error', 'Choose the event first.');
        } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Choose an Excel (.xlsx) or CSV file to upload.');
        } elseif ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            flash('error', 'The file could not be uploaded. Please try again.');
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            flash('error', 'Files must be 5 MB or smaller.');
        } else {
            $read = read_spreadsheet($file['tmp_name'], (string) $file['name']);
            if (!$read['ok']) {
                flash('error', $read['message']);
            } else {
                $table = spreadsheet_records($read['rows']);
                if (!isset($table['columns']['school_id']) && !isset($table['columns']['full_name']) && !isset($table['columns']['last_name'])) {
                    flash('error', 'The first row must name the columns — at least "School ID" or "Name" (or "Last Name" + "First Name"). Download the template to see the layout.');
                } elseif (!$table['records']) {
                    flash('error', 'No rows with a School ID or name were found under the header row.');
                } else {
                    $_SESSION['import'] = [
                        'type' => $type,
                        'event_id' => $event['id'] ?? null,
                        'file' => basename((string) $file['name']),
                        'columns' => $table['columns'],
                        'records' => $table['records'],
                        'at' => date('M j, Y g:i A'),
                    ];
                    flash('success', count($table['records']) . ' rows read from ' . basename((string) $file['name']) . '. Here is how they compare.');
                }
            }
        }
    } elseif ($action === 'clear') {
        unset($_SESSION['import']);
    } elseif ($import && $action === 'verify' && $type === 'roster') {
        $result = $compare();
        $ids = array_map(fn($m) => $m['account']['id'], array_filter($result['matched'], fn($m) => empty($m['account']['school_ids']['verified_at']) && !empty($m['account']['school_ids']['school_id'])));
        $done = mark_school_ids_verified($ids);
        flash($done['ok'] ? 'success' : 'error', $done['message']);
    } elseif ($import && $event && in_array($action, ['mark_attended', 'mark_no_show'], true)) {
        $result = $compare();
        if ($action === 'mark_attended') {
            $ids = array_map(fn($m) => $m['registration']['id'], $result['to_mark']);
            $done = $ids ? set_registration_status_admin($event, $ids, 'attended') : ['ok' => false, 'message' => 'Nobody left to mark.'];
        } else {
            $ids = array_map(fn($m) => $m['registration']['id'], $result['absent']);
            $done = $event['start_date'] > date('Y-m-d')
                ? ['ok' => false, 'message' => "The event hasn't started yet — mark no-shows after it."]
                : ($ids ? set_registration_status_admin($event, $ids, 'no_show') : ['ok' => false, 'message' => 'Nobody left to mark.']);
        }
        flash($done['ok'] ? 'success' : 'error', $done['message']);
    }
    header('Location: ' . $self);
    exit;
}

$result = $import ? $compare() : null;

// ---- Comparison as CSV ----
if ($result && get_param('export') === 'csv') {
    $labels = $type === 'roster'
        ? ['matched' => 'Matched', 'mismatch' => 'Details differ', 'no_account' => 'No account', 'duplicate' => 'Duplicate in file', 'not_in_file' => 'Account not in file']
        : ['to_mark' => 'Registered - on sheet', 'already' => 'Already attended', 'cancelled' => 'Cancelled - on sheet', 'walk_in' => 'Not registered', 'duplicate' => 'Duplicate in file', 'absent' => 'Registered - not on sheet'];
    $rows = [];
    foreach ($labels as $key => $label) {
        foreach ($result[$key] as $m) {
            $person = $m['account'] ?? ($m['registration']['profiles'] ?? null);
            $rows[] = [$label, $m['record']['row'] ?? '', $m['record']['school_id'] ?? '', $m['record']['name'] ?? '',
                $person['full_name'] ?? '', $person['school_ids']['school_id'] ?? '', implode(' ', $m['notes'])];
        }
    }
    send_csv('comparison-' . ($type === 'roster' ? 'student-list' : slugify($event['title'] ?? 'event', 'event')) . '.csv',
        ['Result', 'File row', 'School ID (file)', 'Name (file)', 'Name (system)', 'School ID (system)', 'Notes'], $rows);
}

$events = $type === 'attendance' ? array_values(array_filter(get_all_events_admin(), fn($e) => in_array($e['status'], ['published', 'completed'], true))) : [];
$columnNames = ['school_id' => 'School ID', 'full_name' => 'Name', 'last_name' => 'Last Name', 'first_name' => 'First Name', 'middle_name' => 'Middle Name', 'course' => 'Course', 'year_level' => 'Year Level', 'email' => 'Email'];

$activeNav = 'import';
$breadcrumbs = [$isAdmin ? ['Admin Dashboard', 'dashboard.php'] : ['Dashboard', '../enduser/dashboard.php'], ['Import & Verify', null]];

/** One table of comparison results. */
function render_matches(array $items, string $mode): void
{
    if (!$items) {
        echo '<p class="admin-muted import-none">None.</p>';
        return;
    }
    echo '<div class="admin-table-wrap"><table class="admin-table admin-table-compact"><thead><tr>'
        . '<th class="num">Row</th><th>In the file</th><th>In the system</th><th>Notes</th></tr></thead><tbody>';
    foreach ($items as $m) {
        $r = $m['record'];
        $person = $mode === 'roster' ? $m['account'] : ($m['registration']['profiles'] ?? null);
        $file = $r ? trim(($r['school_id'] !== '' ? $r['school_id'] . ' · ' : '') . $r['name'] . ($r['course'] !== '' ? ' · ' . $r['course'] : '') . ($r['year_level'] ? ' · ' . ordinal($r['year_level']) . ' yr' : '')) : '—';
        $sys = '—';
        if ($person) {
            $sid = $person['school_ids']['school_id'] ?? '';
            $sys = trim(($sid !== '' ? $sid . ' · ' : '') . $person['full_name'] . (!empty($person['course']) ? ' · ' . $person['course'] : '') . (!empty($person['year_level']) ? ' · ' . ordinal((int) $person['year_level']) . ' yr' : ''));
            if ($mode === 'attendance' && !empty($m['registration']['status'])) $sys .= ' · ' . ucfirst(str_replace('_', '-', $m['registration']['status']));
        }
        echo '<tr><td class="num">' . ($r['row'] ?? '—') . '</td><td>' . htmlspecialchars($file) . '</td><td>' . htmlspecialchars($sys)
            . (!empty($person['school_ids']['verified_at']) ? ' <span class="verify-badge is-verified">Verified</span>' : '')
            . '</td><td>' . htmlspecialchars(implode(' ', $m['notes'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Import &amp; Verify — Campus Event Hub</title>
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
        <h1 class="page-title">Import &amp; Verify</h1>
        <p class="page-sub">Upload an Excel or CSV file and check it against the system in seconds.</p>
      </div>
    </div>

    <?php if ($isAdmin): ?>
      <nav class="admin-tabs" aria-label="What to check">
        <a href="import.php?type=roster" class="admin-tab<?= $type === 'roster' ? ' active' : '' ?>">Student list → accounts</a>
        <a href="import.php?type=attendance" class="admin-tab<?= $type === 'attendance' ? ' active' : '' ?>">Attendance sheet → registrations</a>
      </nav>
    <?php endif; ?>

    <?= render_flashes() ?>

    <div class="admin-grid-2 import-top">
      <form method="POST" action="<?= htmlspecialchars($self) ?>" enctype="multipart/form-data" class="admin-panel" data-autoreset autocomplete="off">
        <input type="hidden" name="action" value="upload">
        <div class="admin-panel-head">
          <div>
            <h2><?= $type === 'roster' ? 'Check the registrar\'s student list' : 'Check an attendance sheet' ?></h2>
            <p><?= $type === 'roster'
              ? 'Compares each student on the list with the accounts: same School ID, name, course and year level. Matching accounts can then be marked verified.'
              : 'Compares the people on the sheet with the event\'s registrations, so you can mark them Attended in one click.' ?></p>
          </div>
        </div>

        <?php if ($type === 'attendance'): ?>
          <label class="admin-label" for="eventPick">Event <span class="req">*</span></label>
          <select id="eventPick" class="admin-input" onchange="if (this.value) location.href = 'import.php?type=attendance&event=' + encodeURIComponent(this.value);">
            <option value="">Choose an event</option>
            <?php foreach ($events as $e): ?>
              <option value="<?= htmlspecialchars($e['id']) ?>" <?= ($event['id'] ?? '') === $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['title'] . ' — ' . format_event_date($e['start_date'])) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$events): ?><p class="admin-hint">No published events<?= $isAdmin ? '' : ' for your organization' ?> yet.</p><?php endif; ?>
        <?php endif; ?>

        <?php if ($type === 'roster' || $event): ?>
          <label class="admin-label" for="file">Excel or CSV file <span class="req">*</span></label>
          <input type="file" id="file" name="file" class="admin-input" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
          <p class="admin-hint">
            .xlsx or .csv, up to 5 MB; the first sheet is read. The first row names the columns:
            <strong>School ID</strong> and/or <strong>Name</strong> (or Last Name + First Name)<?= $type === 'roster' ? ', and optionally Course and Year Level' : '' ?>.
            <a href="import.php?type=<?= $type ?>&amp;template=<?= $type ?>">Download a template</a>.
          </p>
          <div class="admin-actions admin-actions-start">
            <button type="submit" class="btn btn-primary">Upload and compare</button>
          </div>
        <?php endif; ?>
      </form>

      <div class="admin-panel import-help">
        <h2>How matching works</h2>
        <ul>
          <li>Rows are matched by <strong>School ID</strong> (spaces and letter case don't matter).</li>
          <li>Without a School ID column, by <strong>name</strong> in any order — "Dela Cruz, Juan P." matches "Juan Dela Cruz".</li>
          <li>The same School ID twice in the file is flagged as a <strong>duplicate</strong>.</li>
          <?php if ($type === 'roster'): ?>
            <li><strong>Verified</strong> School IDs show a badge and can't be changed by the student any more.</li>
          <?php else: ?>
            <li>Marking people Attended records the time and asks them to evaluate the event, the same as on the Registrations page.</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <?php if ($result): ?>
      <?php
        $total = count($import['records']);
        $found = array_map(fn($k) => $columnNames[$k] ?? $k, array_keys($import['columns']));
      ?>
      <section class="admin-panel import-results">
        <div class="admin-panel-head">
          <div>
            <h2>Results: <?= htmlspecialchars($import['file']) ?></h2>
            <p><?= $total ?> row<?= $total === 1 ? '' : 's' ?> · uploaded <?= htmlspecialchars($import['at']) ?> · columns found: <?= htmlspecialchars(implode(', ', $found)) ?><?= $event ? ' · event: ' . htmlspecialchars($event['title']) : '' ?></p>
          </div>
          <div class="page-head-actions">
            <a href="<?= htmlspecialchars($self) ?>&amp;export=csv" class="btn btn-outline">Download results (CSV)</a>
            <form method="POST" action="<?= htmlspecialchars($self) ?>"><input type="hidden" name="action" value="clear"><button type="submit" class="btn btn-muted">Clear</button></form>
          </div>
        </div>

        <?php if ($type === 'roster'): ?>
          <?php $toVerify = count(array_filter($result['matched'], fn($m) => empty($m['account']['school_ids']['verified_at']) && !empty($m['account']['school_ids']['school_id']))); ?>
          <div class="kpi-grid kpi-grid-wide">
            <div class="kpi"><span class="kpi-label">Matched</span><span class="kpi-value import-ok"><?= count($result['matched']) ?></span><span class="kpi-sub">same ID, name, course, year</span></div>
            <div class="kpi"><span class="kpi-label">Details differ</span><span class="kpi-value import-warn"><?= count($result['mismatch']) ?></span><span class="kpi-sub">check before verifying</span></div>
            <div class="kpi"><span class="kpi-label">No account yet</span><span class="kpi-value"><?= count($result['no_account']) ?></span><span class="kpi-sub">on the list, not signed up</span></div>
            <div class="kpi"><span class="kpi-label">Not on the list</span><span class="kpi-value import-bad"><?= count($result['not_in_file']) ?></span><span class="kpi-sub">student accounts to check</span></div>
            <div class="kpi"><span class="kpi-label">Duplicates in file</span><span class="kpi-value"><?= count($result['duplicate']) ?></span></div>
          </div>
          <?php if ($toVerify > 0): ?>
            <form method="POST" action="<?= htmlspecialchars($self) ?>" class="import-action" onsubmit="return confirm('Mark <?= $toVerify ?> matched account(s) as verified?');">
              <input type="hidden" name="action" value="verify">
              <button type="submit" class="btn btn-primary">Mark <?= $toVerify ?> matched account<?= $toVerify === 1 ? '' : 's' ?> as verified</button>
              <span class="admin-hint">Only accounts whose School ID, name, course and year level all match.</span>
            </form>
          <?php endif; ?>
          <?php foreach (['mismatch' => 'Details differ', 'no_account' => 'On the list but no account', 'not_in_file' => 'Student accounts not on the list', 'duplicate' => 'Duplicates in the file', 'matched' => 'Matched'] as $key => $label): ?>
            <details class="import-group"<?= $result[$key] && $key !== 'matched' ? ' open' : '' ?>>
              <summary><?= $label ?> <span class="import-count"><?= count($result[$key]) ?></span></summary>
              <?php render_matches($result[$key], 'roster'); ?>
            </details>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="kpi-grid kpi-grid-wide">
            <div class="kpi"><span class="kpi-label">To mark Attended</span><span class="kpi-value import-ok"><?= count($result['to_mark']) ?></span><span class="kpi-sub">registered and on the sheet</span></div>
            <div class="kpi"><span class="kpi-label">Already attended</span><span class="kpi-value"><?= count($result['already']) ?></span></div>
            <div class="kpi"><span class="kpi-label">Not registered</span><span class="kpi-value import-warn"><?= count($result['walk_in']) ?></span><span class="kpi-sub">walk-ins on the sheet</span></div>
            <div class="kpi"><span class="kpi-label">Not on the sheet</span><span class="kpi-value import-bad"><?= count($result['absent']) ?></span><span class="kpi-sub">registered, possibly no-show</span></div>
            <div class="kpi"><span class="kpi-label">Needs a look</span><span class="kpi-value"><?= count($result['duplicate']) + count($result['cancelled']) ?></span><span class="kpi-sub">duplicates, cancelled registrations</span></div>
          </div>
          <div class="import-action">
            <?php if ($result['to_mark']): ?>
              <form method="POST" action="<?= htmlspecialchars($self) ?>" onsubmit="return confirm('Mark <?= count($result['to_mark']) ?> people as Attended?');">
                <input type="hidden" name="action" value="mark_attended">
                <button type="submit" class="btn btn-primary">Mark <?= count($result['to_mark']) ?> as Attended</button>
              </form>
            <?php endif; ?>
            <?php if ($result['absent'] && $event['start_date'] <= date('Y-m-d')): ?>
              <form method="POST" action="<?= htmlspecialchars($self) ?>" onsubmit="return confirm('Mark <?= count($result['absent']) ?> registered people who are not on the sheet as No-show?');">
                <input type="hidden" name="action" value="mark_no_show">
                <button type="submit" class="btn btn-outline">Mark <?= count($result['absent']) ?> not on the sheet as No-show</button>
              </form>
            <?php endif; ?>
            <a href="event-registrations.php?event=<?= urlencode($event['id']) ?>" class="btn btn-outline">Open Registrations</a>
          </div>
          <?php foreach (['to_mark' => 'Registered and on the sheet', 'walk_in' => 'On the sheet but not registered', 'absent' => 'Registered but not on the sheet', 'cancelled' => 'Cancelled but on the sheet', 'duplicate' => 'Duplicates in the file', 'already' => 'Already marked Attended'] as $key => $label): ?>
            <details class="import-group"<?= $result[$key] && $key !== 'already' ? ' open' : '' ?>>
              <summary><?= $label ?> <span class="import-count"><?= count($result[$key]) ?></span></summary>
              <?php render_matches($result[$key], 'attendance'); ?>
            </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/forms.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
