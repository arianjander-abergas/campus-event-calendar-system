<?php
/**
 * Admin: the school calendar — the dates of each semester. The dashboard
 * and reports count an event in the school year and semester its start date
 * falls in. Dates outside every term follow the default calendar
 * (1st semester Aug–Dec, 2nd semester Jan–May, summer Jun–Jul).
 */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$editId = get_param('edit');
$editTerm = $editId !== '' ? get_term_admin($editId) : null;
$errors = [];

// ---- Save (create or update) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'save_term') {
    $existingId = post_param('id');
    $fields = [
        'school_year' => post_param('school_year'),
        'semester' => post_param('semester'),
        'start_date' => post_param('start_date'),
        'end_date' => post_param('end_date'),
    ];
    $validDate = fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

    if (!preg_match('/^(\d{4})-(\d{4})$/', $fields['school_year'], $m) || (int) $m[2] !== (int) $m[1] + 1) {
        $errors[] = 'School year must be two consecutive years, like 2026-2027.';
    }
    if (!isset(SEMESTERS[$fields['semester']])) $errors[] = 'Please choose a semester.';
    if (!$validDate($fields['start_date']) || !$validDate($fields['end_date'])) {
        $errors[] = 'Please enter both the start and end dates.';
    } elseif ($fields['end_date'] < $fields['start_date']) {
        $errors[] = "The end date can't be before the start date.";
    }

    if (!$errors) {
        $result = save_term_admin(is_uuid($existingId) ? $existingId : null, $fields);
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: school-calendar.php');
            exit;
        }
        $errors[] = $result['message'];
    }
    $editTerm = $fields + ['id' => $existingId];
    $editId = $existingId;
}

// ---- Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'delete_term') {
    $result = delete_term_admin(post_param('id'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: school-calendar.php');
    exit;
}

$today = school_period_of();
$terms = get_academic_terms();
$byYear = group_rows($terms, fn($t) => $t['school_year']);
krsort($byYear);

// Events per term (cancelled ones left out), for the table.
$eventCounts = [];
foreach (supabase_user_request('event_report', 'select=school_year,semester,status&status=neq.cancelled') as $r) {
    $key = $r['school_year'] . '|' . $r['semester'];
    $eventCounts[$key] = ($eventCounts[$key] ?? 0) + 1;
}

// New term: suggest the first semester of the current school year that isn't set yet.
if (!$editTerm) {
    $suggestYear = $today['school_year'];
    $taken = array_column($byYear[$suggestYear] ?? [], 'semester');
    $missing = array_values(array_diff(array_keys(SEMESTERS), $taken));
    if (!$missing) {
        $first = (int) substr($suggestYear, 0, 4) + 1;
        $suggestYear = $first . '-' . ($first + 1);
        $missing = array_keys(SEMESTERS);
    }
    [$start, $end] = default_term_range($suggestYear, $missing[0]);
    $f = ['school_year' => $suggestYear, 'semester' => $missing[0], 'start_date' => $start, 'end_date' => $end];
} else {
    $f = $editTerm;
}
$val = fn(string $key) => htmlspecialchars((string) ($f[$key] ?? ''));
$isEditing = !empty($f['id']);

$activeNav = 'admin-calendar';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['School Calendar', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>School Calendar — Campus Event Hub</title>
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
        <h1 class="page-title">School Calendar</h1>
        <p class="page-sub">Today (<?= date('M j, Y') ?>) falls in <?= htmlspecialchars(period_label($today['school_year'], $today['semester'])) ?>.</p>
      </div>
    </div>

    <?= render_flashes() ?>
    <?php foreach ($errors as $err): ?><div class="flash flash-error" role="alert"><?= htmlspecialchars($err) ?></div><?php endforeach; ?>

    <p class="admin-note">The dashboard and reports count each event in the school year and semester its <strong>start date</strong> falls in.
      Set each term's actual dates here. Dates outside every term follow the default calendar:
      1st Semester August–December, 2nd Semester January–May, Summer June–July.</p>

    <div class="admin-split">
      <form method="POST" action="school-calendar.php<?= $isEditing ? '?edit=' . urlencode($f['id']) : '' ?>" class="admin-card" data-autoreset>
        <input type="hidden" name="action" value="save_term">
        <?php if ($isEditing): ?><input type="hidden" name="id" value="<?= $val('id') ?>"><?php endif; ?>
        <h2 class="admin-card-title"><?= $isEditing ? 'Edit Term' : 'Add Term' ?></h2>

        <label class="admin-label" for="school_year">School Year <span class="req">*</span></label>
        <input type="text" id="school_year" name="school_year" class="admin-input" placeholder="2026-2027" pattern="\d{4}-\d{4}" value="<?= $val('school_year') ?>" required>

        <label class="admin-label" for="semester">Semester <span class="req">*</span></label>
        <select id="semester" name="semester" class="admin-input" required>
          <?php foreach (SEMESTERS as $key => $label): ?>
            <option value="<?= $key ?>" <?= ($f['semester'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>

        <div class="admin-two-col">
          <div>
            <label class="admin-label" for="start_date">First Day <span class="req">*</span></label>
            <input type="date" id="start_date" name="start_date" class="admin-input" value="<?= $val('start_date') ?>" required>
          </div>
          <div>
            <label class="admin-label" for="end_date">Last Day <span class="req">*</span></label>
            <input type="date" id="end_date" name="end_date" class="admin-input" value="<?= $val('end_date') ?>" required>
          </div>
        </div>
        <p class="admin-hint">Terms can't overlap. Leave gaps (like a semestral break) if you want; events in a gap follow the default calendar.</p>

        <div class="admin-actions admin-actions-start">
          <button type="submit" class="btn btn-primary"><?= $isEditing ? 'Save Changes' : 'Add Term' ?></button>
          <?php if ($isEditing): ?><a href="school-calendar.php" class="btn btn-outline">Cancel</a><?php endif; ?>
        </div>
      </form>

      <div>
        <?php if (empty($byYear)): ?>
          <div class="admin-table-wrap"><p class="admin-table-empty">No terms yet — every date follows the default calendar.</p></div>
        <?php endif; ?>
        <?php foreach ($byYear as $sy => $yearTerms): usort($yearTerms, fn($a, $b) => strcmp($a['start_date'], $b['start_date'])); ?>
          <h2 class="admin-year-heading">SY <?= htmlspecialchars($sy) ?><?= $sy === $today['school_year'] ? ' (current)' : '' ?></h2>
          <div class="admin-table-wrap">
            <table class="admin-table">
              <thead><tr><th>Semester</th><th>Dates</th><th class="num">Days</th><th class="num">Events</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
              <tbody>
                <?php foreach ($yearTerms as $t):
                  $days = (int) round((strtotime($t['end_date']) - strtotime($t['start_date'])) / 86400) + 1;
                  $isNow = $sy === $today['school_year'] && $t['semester'] === $today['semester'];
                ?>
                  <tr>
                    <td><strong><?= htmlspecialchars(label_of(SEMESTERS, $t['semester'])) ?></strong><?= $isNow ? ' <span class="admin-status admin-status-published">Now</span>' : '' ?></td>
                    <td class="nowrap"><?= htmlspecialchars(format_event_date($t['start_date'])) ?> – <?= htmlspecialchars(format_event_date($t['end_date'])) ?></td>
                    <td class="num"><?= $days ?></td>
                    <td class="num"><a href="reports.php?school_year=<?= urlencode($sy) ?>&amp;semester=<?= urlencode($t['semester']) ?>"><?= $eventCounts[$sy . '|' . $t['semester']] ?? 0 ?></a></td>
                    <td class="admin-row-actions">
                      <a href="school-calendar.php?edit=<?= urlencode($t['id']) ?>" class="btn-sm">Edit</a>
                      <form method="POST" action="school-calendar.php" data-confirm="Delete this term? Its dates will follow the default calendar." data-confirm-title="Delete term" data-confirm-ok="Delete" data-confirm-tone="danger">
                        <input type="hidden" name="action" value="delete_term">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($t['id']) ?>">
                        <button type="submit" class="btn-sm btn-sm-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/../assets/js/sidebar.js') ?>"></script>
<script src="../assets/js/ui.js?v=<?= filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
<script src="../assets/js/forms.js?v=<?= filemtime(__DIR__ . '/../assets/js/forms.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
