<?php
/** Admin: who registered for an event, attendance, and a CSV export. */
require_once __DIR__ . '/../../config/supabase.php';

$user = require_admin();

$eventId = get_param('event');
$event = is_uuid($eventId) ? get_event_admin($eventId) : null;
if (!$event) {
    flash('error', "That event doesn't exist anymore.");
    header('Location: event-organizer.php');
    exit;
}
$back = 'event-registrations.php?event=' . urlencode($eventId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_param('action') === 'set_status') {
    $result = set_registration_status_admin(post_param('id'), post_param('status'));
    if (!$result['ok']) flash('error', $result['message']);
    header('Location: ' . $back);
    exit;
}

$registrations = get_event_registrations_admin($eventId);
$statusLabels = ['registered' => 'Registered', 'attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled', 'waitlisted' => 'Waitlisted'];
$yearLabel = fn($y) => $y ? $y . (['', 'st', 'nd', 'rd'][$y] ?? 'th') . ' Year' : '';

// ---- CSV export (opens in Excel / Google Sheets) ----
if (get_param('export') === 'csv') {
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($event['title'])), '-') ?: 'event';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registrations-' . $slug . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the names as UTF-8
    fputcsv($out, ['Name', 'Year Level', 'Course', 'Organization', 'Status', 'Registered At']);
    foreach ($registrations as $r) {
        fputcsv($out, [
            $r['profiles']['full_name'] ?? '',
            $yearLabel($r['profiles']['year_level'] ?? null),
            $r['profiles']['course'] ?? '',
            $r['profiles']['organizations']['name'] ?? '',
            $statusLabels[$r['status']] ?? $r['status'],
            date('Y-m-d H:i', strtotime($r['registered_at'])),
        ]);
    }
    fclose($out);
    exit;
}

$totals = array_count_values(array_column($registrations, 'status'));
$activeNav = 'admin-events';
$breadcrumbs = [['Manage Events', 'event-organizer.php'], [$event['title'], 'event-organizer.php?edit=' . urlencode($eventId)], ['Registrations', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Registrations: <?= htmlspecialchars($event['title']) ?> — Campus Event Hub</title>
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
        <h1 class="page-title">Registrations</h1>
        <p class="page-sub"><?= htmlspecialchars($event['title']) ?> · <?= htmlspecialchars(event_when($event)) ?></p>
      </div>
      <?php if ($registrations): ?>
        <a href="<?= htmlspecialchars($back) ?>&amp;export=csv" class="btn btn-outline">Download CSV</a>
      <?php endif; ?>
    </div>

    <?= render_flashes() ?>

    <div class="admin-stat-row">
      <div class="admin-stat"><strong><?= ($totals['registered'] ?? 0) + ($totals['attended'] ?? 0) ?><?= !empty($event['registration_limit']) ? ' / ' . (int) $event['registration_limit'] : '' ?></strong><span>Going</span></div>
      <div class="admin-stat"><strong><?= $totals['attended'] ?? 0 ?></strong><span>Attended</span></div>
      <div class="admin-stat"><strong><?= $totals['no_show'] ?? 0 ?></strong><span>No-show</span></div>
      <div class="admin-stat"><strong><?= $totals['cancelled'] ?? 0 ?></strong><span>Cancelled</span></div>
    </div>

    <div class="admin-table-wrap">
      <table class="admin-table">
        <thead>
          <tr><th>Name</th><th>Year &amp; Course</th><th>Organization</th><th>Registered</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr>
        </thead>
        <tbody>
          <?php if (empty($registrations)): ?>
            <tr><td colspan="6" class="admin-table-empty">No one has registered yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($registrations as $r): ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['profiles']['full_name'] ?? 'Unknown') ?></strong></td>
              <td><?= htmlspecialchars(trim($yearLabel($r['profiles']['year_level'] ?? null) . ' ' . ($r['profiles']['course'] ?? ''))) ?: '—' ?></td>
              <td><?= htmlspecialchars($r['profiles']['organizations']['name'] ?? '—') ?></td>
              <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['registered_at']))) ?></td>
              <td><span class="admin-status admin-status-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($statusLabels[$r['status']] ?? $r['status']) ?></span></td>
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

<script src="../assets/js/sidebar.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
