<?php
/**
 * Admins and the event's moderators: who registered for an event, attendance (one by one or several at
 * once), a CSV export, and links to the printable attendance sheet and the
 * evaluation results. Marking someone Attended records the time and asks
 * them to evaluate the event.
 */
require_once __DIR__ . '/../../config/supabase.php';

$eventId = get_param('event');
[$user, $event] = require_event_manager($eventId); // admins, or moderators of the event's organization
$back = 'event-registrations.php?event=' . urlencode($eventId);

// ---- Attendance: one row (id) or the picked rows (ids[]) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_status') {
    $ids = is_array($_POST['ids'] ?? null) ? array_filter($_POST['ids'], 'is_string') : [post_param('id')];
    $result = set_registration_status_admin($event, $ids, post_param('status'));
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    header('Location: ' . $back);
    exit;
}

$registrations = get_event_registrations_admin($eventId);
$statusLabels = ['registered' => 'Registered', 'attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled', 'waitlisted' => 'Waitlisted'];
$evaluators = get_event_evaluator_ids($eventId);
// Attended + evaluated = the person's participation is Completed.
$evaluationLabel = fn(array $r): string => $r['status'] !== 'attended' ? '' : (in_array($r['user_id'], $evaluators, true) ? 'Completed' : 'Pending');

// ---- CSV export (opens in Excel / Google Sheets) ----
if (get_param('export') === 'csv') {
    send_csv('registrations-' . slugify($event['title'], 'event') . '.csv',
        ['Name', 'School ID', 'Year Level', 'Course', 'Organization', 'Status', 'Registered At', 'Time In', 'Evaluation'],
        array_map(fn($r) => [
            $r['profiles']['full_name'] ?? '',
            $r['profiles']['school_ids']['school_id'] ?? '',
            student_year_line($r['profiles'] ?? []),
            $r['profiles']['course'] ?? '',
            $r['profiles']['organizations']['name'] ?? '',
            $statusLabels[$r['status']] ?? $r['status'],
            date('Y-m-d H:i', strtotime($r['registered_at'])),
            !empty($r['attended_at']) ? date('Y-m-d H:i', strtotime($r['attended_at'])) : '',
            $evaluationLabel($r),
        ], $registrations));
}

$totals = array_count_values(array_column($registrations, 'status'));
$evaluationCount = count($evaluators);
$activeNav = 'manage-update';
$breadcrumbs = [['Update Events', 'event-organizer.php?view=update'], [$event['title'], 'event-organizer.php?edit=' . urlencode($eventId)], ['Registrations', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrations: <?= htmlspecialchars($event['title']) ?> — Campus Event Hub</title>
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
        <h1 class="page-title">Registrations</h1>
        <p class="page-sub"><?= htmlspecialchars($event['title']) ?> · <?= htmlspecialchars(event_when($event)) ?></p>
      </div>
      <div class="page-head-actions">
        <a href="import.php?type=attendance&amp;event=<?= urlencode($eventId) ?>" class="btn btn-outline">Import attendance (Excel)</a>
        <a href="attendance-print.php?event=<?= urlencode($eventId) ?>" class="btn btn-outline">Print attendance</a>
        <a href="event-evaluations.php?event=<?= urlencode($eventId) ?>" class="btn btn-outline">Evaluation results</a>
        <?php if ($registrations): ?>
          <a href="<?= htmlspecialchars($back) ?>&amp;export=csv" class="btn btn-outline">Download CSV</a>
        <?php endif; ?>
      </div>
    </div>

    <?= render_flashes() ?>

    <div class="admin-stat-row">
      <div class="admin-stat"><strong><?= ($totals['registered'] ?? 0) + ($totals['attended'] ?? 0) ?><?= !empty($event['registration_limit']) ? ' / ' . (int) $event['registration_limit'] : '' ?></strong><span>Going</span></div>
      <div class="admin-stat"><strong><?= $totals['attended'] ?? 0 ?></strong><span>Attended</span></div>
      <div class="admin-stat"><strong><?= $totals['no_show'] ?? 0 ?></strong><span>No-show</span></div>
      <div class="admin-stat"><strong><?= $totals['cancelled'] ?? 0 ?></strong><span>Cancelled</span></div>
      <div class="admin-stat"><strong><?= $evaluationCount ?></strong><span>Evaluations</span></div>
    </div>

    <?php if ($registrations): ?>
      <!-- Bulk attendance: the checkboxes in the table belong to this form (form="bulkForm"). -->
      <form method="POST" action="<?= htmlspecialchars($back) ?>" id="bulkForm" class="bulk-bar">
        <input type="hidden" name="action" value="set_status">
        <span>Mark picked people as:</span>
        <button type="submit" name="status" value="attended" class="btn-sm btn-sm-accent">Attended</button>
        <button type="submit" name="status" value="no_show" class="btn-sm">No-show</button>
        <button type="submit" name="status" value="registered" class="btn-sm">Reset</button>
        <span class="admin-hint" style="margin:0">People marked Attended get a notification asking them to evaluate the event.</span>
      </form>
    <?php endif; ?>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr>
            <th><?php if ($registrations): ?><input type="checkbox" aria-label="Pick everyone" data-check-all><?php endif; ?></th>
            <th>Name</th><th>School ID</th><th>Year &amp; Course</th><th>Organization</th><th>Registered</th><th>Time in</th><th>Status</th><th>Evaluation</th><th><span class="visually-hidden">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($registrations)): ?>
            <tr><td colspan="10" class="admin-table-empty">No one has registered yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($registrations as $r): ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?= htmlspecialchars($r['id']) ?>" form="bulkForm" aria-label="Pick <?= htmlspecialchars($r['profiles']['full_name'] ?? 'this person') ?>"></td>
              <td><strong><?= htmlspecialchars($r['profiles']['full_name'] ?? 'Unknown') ?></strong><?= ($r['profiles']['account_type'] ?? '') === 'faculty' ? ' <span class="admin-table-sub">Faculty</span>' : '' ?></td>
              <td class="nowrap"><?php $sid = $r['profiles']['school_ids'] ?? null; ?><?= !empty($sid['school_id']) ? htmlspecialchars($sid['school_id']) . (!empty($sid['verified_at']) ? ' <span class="verify-badge is-verified" title="Verified with the registrar list">✓</span>' : '') : '—' ?></td>
              <td><?= htmlspecialchars(trim(student_year_line($r['profiles'] ?? []) . ' ' . ($r['profiles']['course'] ?? ''))) ?: '—' ?></td>
              <td><?= htmlspecialchars($r['profiles']['organizations']['name'] ?? '—') ?></td>
              <td class="nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['registered_at']))) ?></td>
              <td class="nowrap"><?= !empty($r['attended_at']) ? htmlspecialchars(date('M j, g:i A', strtotime($r['attended_at']))) : '—' ?></td>
              <td><span class="admin-status admin-status-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
              <td><?php $evl = $evaluationLabel($r); ?><?= $evl === '' ? '—' : '<span class="admin-status admin-status-' . ($evl === 'Completed' ? 'completed' : 'draft') . '">' . $evl . '</span>' ?></td>
              <td class="admin-row-actions">
                <form method="POST" action="<?= htmlspecialchars($back) ?>">
                  <input type="hidden" name="action" value="set_status">
                  <input type="hidden" name="id" value="<?= htmlspecialchars($r['id']) ?>">
                  <?php if ($r['status'] !== 'attended'): ?><button type="submit" name="status" value="attended" class="btn-sm btn-sm-accent">Attended</button><?php endif; ?>
                  <?php if ($r['status'] !== 'no_show'): ?><button type="submit" name="status" value="no_show" class="btn-sm">No-show</button><?php endif; ?>
                  <?php if ($r['status'] !== 'registered'): ?><button type="submit" name="status" value="registered" class="btn-sm">Reset</button><?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>

</div>

<script>
// "Pick everyone" checkbox in the table header.
document.querySelectorAll('[data-check-all]').forEach(function (all) {
  all.addEventListener('change', function () {
    document.querySelectorAll('input[name="ids[]"]').forEach(function (box) { box.checked = all.checked; });
  });
});
</script>
<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/ui.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
