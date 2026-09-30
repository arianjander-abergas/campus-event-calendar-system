<?php
/**
 * Admins and the event's moderators: printable attendance for one event (A4).
 *   ?mode=sheet  (default) attendance sheet to sign at the event: everyone
 *                registered, a blank signature column and extra rows for walk-ins
 *   ?mode=report attendance report after the event: attendees with their
 *                time in, plus registered / attended / no-show totals
 */
require_once __DIR__ . '/../../config/supabase.php';

$eventId = get_param('event');
[$user, $event] = require_event_manager($eventId); // admins, or moderators of the event's organization

$mode = get_param('mode') === 'report' ? 'report' : 'sheet';
$blankRows = $mode === 'sheet' ? max(0, min(40, (int) (get_param('blank') !== '' ? get_param('blank') : 5))) : 0;

$registrations = get_event_registrations_admin($eventId);
$counts = array_count_values(array_column($registrations, 'status'));
$signedUp = ($counts['registered'] ?? 0) + ($counts['attended'] ?? 0) + ($counts['no_show'] ?? 0);
$people = array_values(array_filter($registrations, fn($r) => $mode === 'report'
    ? $r['status'] === 'attended'
    : in_array($r['status'], ['registered', 'attended'], true)));
usort($people, fn($a, $b) => strcasecmp($a['profiles']['full_name'] ?? '', $b['profiles']['full_name'] ?? ''));

$yearCourse = function (array $p): string {
    $year = $p['year_level'] ?? null;
    $label = $year ? $year . (['', 'st', 'nd', 'rd'][$year] ?? 'th') . ' Year' : '';
    return trim($label . ' ' . ($p['course'] ?? '')) ?: '—';
};
$timeIn = fn(?string $ts): string => $ts ? date('g:i A', strtotime($ts)) : '';

$org = $event['organizations'] ?? null;
$department = $org['departments'] ?? null;
$title = $mode === 'report' ? 'Attendance Report' : 'Attendance Sheet';
$self = 'attendance-print.php?event=' . urlencode($eventId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $title ?>: <?= htmlspecialchars($event['title']) ?> — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/print.css">
</head>
<body>

<nav class="print-toolbar" aria-label="Print options">
  <a href="event-registrations.php?event=<?= urlencode($eventId) ?>" class="tb-back">‹ Back to registrations</a>
  <div class="tb-modes" role="tablist">
    <a href="<?= htmlspecialchars($self) ?>&amp;mode=sheet" class="<?= $mode === 'sheet' ? 'active' : '' ?>" role="tab" aria-selected="<?= $mode === 'sheet' ? 'true' : 'false' ?>">Attendance sheet (for signing)</a>
    <a href="<?= htmlspecialchars($self) ?>&amp;mode=report" class="<?= $mode === 'report' ? 'active' : '' ?>" role="tab" aria-selected="<?= $mode === 'report' ? 'true' : 'false' ?>">Attendance report</a>
  </div>
  <?php if ($mode === 'sheet'): ?>
    <form method="get" action="attendance-print.php" class="tb-blank">
      <input type="hidden" name="event" value="<?= htmlspecialchars($eventId) ?>">
      <label>Walk-in rows <input type="number" name="blank" min="0" max="40" value="<?= $blankRows ?>"></label>
      <button type="submit">Update</button>
    </form>
  <?php endif; ?>
  <button type="button" class="tb-print" onclick="window.print()">Print</button>
</nav>

<main class="sheet">
  <header class="sheet-head">
    <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="sheet-logo">
    <div class="sheet-head-text">
      <strong class="sheet-school"><?= htmlspecialchars(SCHOOL_NAME) ?></strong>
      <?php if (!empty($department['name'])): ?><span><?= htmlspecialchars($department['name']) ?> Department</span><?php endif; ?>
      <?php if (!empty($org['name'])): ?><span><?= htmlspecialchars($org['name']) ?></span><?php endif; ?>
      <h1><?= $title ?></h1>
    </div>
    <?php if (!empty($org['logo_url'])): ?>
      <img src="<?= htmlspecialchars(asset_url($org['logo_url'])) ?>" alt="<?= htmlspecialchars($org['name'] ?? '') ?> logo" class="sheet-logo">
    <?php else: ?>
      <span class="sheet-logo" aria-hidden="true"></span>
    <?php endif; ?>
  </header>

  <dl class="sheet-details">
    <div><dt>Event</dt><dd><?= htmlspecialchars($event['title']) ?></dd></div>
    <div><dt>Date &amp; time</dt><dd><?= htmlspecialchars(event_when($event)) ?></dd></div>
    <div><dt>Venue</dt><dd><?= htmlspecialchars(event_place($event)) ?><?= !empty($event['address']) ? ', ' . htmlspecialchars($event['address']) : '' ?> (<?= htmlspecialchars(label_of(VENUE_TYPES, $event['venue_type'] ?? null)) ?>)</dd></div>
    <div><dt>Audience</dt><dd><?= htmlspecialchars(label_of(EVENT_AUDIENCES, $event['audience'] ?? null)) ?></dd></div>
    <div><dt>Category</dt><dd><?= htmlspecialchars($event['categories']['name'] ?? label_of(EVENT_TYPES, $event['event_type'] ?? null)) ?></dd></div>
  </dl>

  <table class="sheet-table">
    <thead>
      <tr>
        <th class="col-no">No.</th>
        <th>Name</th>
        <th>Year &amp; Course</th>
        <th>Organization</th>
        <th class="col-time">Time in</th>
        <?php if ($mode === 'sheet'): ?><th class="col-sign">Signature</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($people as $i => $r): $p = $r['profiles'] ?? []; ?>
        <tr>
          <td class="col-no"><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($p['full_name'] ?? 'Unknown') ?></td>
          <td><?= htmlspecialchars($yearCourse($p)) ?></td>
          <td><?= htmlspecialchars($p['organizations']['name'] ?? '—') ?></td>
          <td class="col-time"><?= htmlspecialchars($r['status'] === 'attended' ? $timeIn($r['attended_at'] ?? null) : '') ?></td>
          <?php if ($mode === 'sheet'): ?><td class="col-sign"></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < $blankRows; $i++): ?>
        <tr class="blank-row"><td class="col-no"><?= count($people) + $i + 1 ?></td><td></td><td></td><td></td><td class="col-time"></td><td class="col-sign"></td></tr>
      <?php endfor; ?>
      <?php if (!$people && !$blankRows): ?>
        <tr><td colspan="<?= $mode === 'sheet' ? 6 : 5 ?>" class="sheet-empty"><?= $mode === 'report' ? 'Nobody is marked as attended yet.' : 'Nobody has registered yet.' ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($mode === 'report'): ?>
    <p class="sheet-summary">
      Registered: <strong><?= $signedUp ?></strong> ·
      Attended: <strong><?= $counts['attended'] ?? 0 ?></strong> ·
      No-show: <strong><?= $counts['no_show'] ?? 0 ?></strong> ·
      Attendance rate: <strong><?= format_percent($signedUp > 0 ? ($counts['attended'] ?? 0) / $signedUp : null) ?></strong>
      <?php if (!empty($counts['registered'])): ?> · Not yet marked: <strong><?= $counts['registered'] ?></strong><?php endif; ?>
    </p>
  <?php endif; ?>

  <div class="sheet-signatures">
    <div><strong><?= htmlspecialchars($user['full_name']) ?></strong><span>Prepared by</span></div>
    <div><strong>&nbsp;</strong><span>Noted by (Organization Adviser)</span></div>
  </div>
  <p class="sheet-foot">Campus Event Hub · Printed <?= date('F j, Y g:i A') ?></p>
</main>

</body>
</html>
