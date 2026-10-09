<?php
/**
 * Admin Dashboard: how many events happen this week, this month, this
 * semester and this school year — split by venue type (on-campus,
 * off-campus, online) and by audience (students, faculty) — plus
 * attendance and evaluation totals. Numbers come from the event_report view.
 */
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/charts.php';

$user = require_admin();

$today = date('Y-m-d');
$period = school_period_of($today);
$schoolYear = $period['school_year'];
$semester = $period['semester'];
[$yearStart, $yearEnd] = term_range($schoolYear);

$weekStart = week_start($today);
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

// One request covers every period shown (a week or month can cross a school-year edge).
$rows = get_event_report(['from' => min($weekStart, $monthStart, $yearStart), 'to' => max($weekEnd, $monthEnd, $yearEnd)]);

$periods = [
    'week' => [
        'label' => 'This week',
        'sub' => week_label($weekStart),
        'rows' => array_filter($rows, fn($r) => $r['start_date'] >= $weekStart && $r['start_date'] <= $weekEnd),
        'link' => 'reports.php?' . http_build_query(['school_year' => 'all', 'from' => $weekStart, 'to' => $weekEnd]),
    ],
    'month' => [
        'label' => 'This month',
        'sub' => date('F Y'),
        'rows' => array_filter($rows, fn($r) => $r['start_date'] >= $monthStart && $r['start_date'] <= $monthEnd),
        'link' => 'reports.php?' . http_build_query(['school_year' => 'all', 'from' => $monthStart, 'to' => $monthEnd]),
    ],
    'semester' => [
        'label' => 'This semester',
        'sub' => period_label($schoolYear, $semester),
        'rows' => array_filter($rows, fn($r) => $r['school_year'] === $schoolYear && $r['semester'] === $semester),
        'link' => 'reports.php?' . http_build_query(['school_year' => $schoolYear, 'semester' => $semester]),
    ],
    'year' => [
        'label' => 'This school year',
        'sub' => 'SY ' . $schoolYear,
        'rows' => array_filter($rows, fn($r) => $r['school_year'] === $schoolYear),
        'link' => 'reports.php?' . http_build_query(['school_year' => $schoolYear]),
    ],
];
foreach ($periods as &$p) {
    $p['totals'] = report_totals($p['rows']);
}
unset($p);
$year = $periods['year']['totals'];

// Events per month of the school year, split by venue type.
$yearRows = $periods['year']['rows'];
$monthColumns = [];
foreach (month_list($yearStart, $yearEnd) as $m) {
    $monthColumns[] = ['key' => $m, 'label' => month_label($m, true), 'title' => month_label($m), 'current' => $m === date('Y-m')];
}
$monthSeries = [];
foreach (VENUE_TYPES as $type => $label) {
    $values = [];
    foreach ($yearRows as $r) {
        if ($r['status'] !== 'cancelled' && $r['venue_type'] === $type) {
            $values[substr($r['start_date'], 0, 7)] = ($values[substr($r['start_date'], 0, 7)] ?? 0) + 1;
        }
    }
    $monthSeries[] = ['key' => $type, 'label' => $label, 'color' => VENUE_TYPE_COLORS[$type], 'values' => $values];
}

// This week's events, and events that ended recently (attendance + evaluations to check).
$thisWeek = array_values(array_filter($periods['week']['rows'], fn($r) => $r['status'] !== 'cancelled'));
$recentlyEnded = array_values(array_filter($yearRows, fn($r) => $r['status'] !== 'cancelled' && report_event_ended($r)));
usort($recentlyEnded, fn($a, $b) => strcmp($b['end_date'], $a['end_date']));
$recentlyEnded = array_slice($recentlyEnded, 0, 6);

$activeNav = 'admin-dashboard';
$breadcrumbs = [['Admin Dashboard', null]];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard — Campus Event Hub</title>
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
        <h1 class="page-title">Admin Dashboard</h1>
        <p class="page-sub"><?= htmlspecialchars(period_label($schoolYear, $semester)) ?> · Today is <?= date('l, F j, Y') ?></p>
      </div>
      <div class="page-head-actions">
        <a href="reports.php" class="btn btn-outline">Open Reports</a>
        <a href="event-organizer.php" class="btn btn-primary">Create Event</a>
      </div>
    </div>

    <?= render_flashes() ?>

    <!-- Events per period -->
    <div class="kpi-grid">
      <?php foreach ($periods as $p): $t = $p['totals']; ?>
        <a class="kpi" href="<?= htmlspecialchars($p['link']) ?>">
          <span class="kpi-label"><?= htmlspecialchars($p['label']) ?></span>
          <span class="kpi-value"><?= $t['events'] ?></span>
          <span class="kpi-sub"><?= $t['events'] === 1 ? 'event' : 'events' ?> · <?= htmlspecialchars($p['sub']) ?></span>
          <span class="kpi-split">
            <?php foreach (VENUE_TYPES as $type => $label): ?>
              <span><span class="series-dot" style="background:<?= VENUE_TYPE_COLORS[$type] ?>"></span><?= $t[$type] ?> <?= htmlspecialchars(strtolower(explode(' ', $label)[0])) ?></span>
            <?php endforeach; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- The professor's table: each period split by venue type and audience -->
    <section class="admin-panel admin-panel-flush">
      <div class="admin-panel-head">
        <div>
          <h2>Events by venue type and audience</h2>
          <p>Counted by start date. Cancelled events are listed separately and left out of the other columns.</p>
        </div>
      </div>
      <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Period</th>
              <th class="num">Events</th>
              <?php foreach (VENUE_TYPES as $label): ?><th class="num"><?= htmlspecialchars(explode(' ', $label)[0]) ?></th><?php endforeach; ?>
              <th class="num">For students</th>
              <th class="num">For faculty</th>
              <th class="num">For both</th>
              <th class="num">Cancelled</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($periods as $p): $t = $p['totals']; ?>
              <tr>
                <td><a href="<?= htmlspecialchars($p['link']) ?>" class="row-link"><?= htmlspecialchars($p['label']) ?></a><div class="admin-table-sub"><?= htmlspecialchars($p['sub']) ?></div></td>
                <td class="num"><strong><?= $t['events'] ?></strong></td>
                <?php foreach (array_keys(VENUE_TYPES) as $type): ?><td class="num"><?= $t[$type] ?></td><?php endforeach; ?>
                <td class="num"><?= $t['for_students'] ?></td>
                <td class="num"><?= $t['for_faculty'] ?></td>
                <td class="num"><?= $t['for_all'] ?></td>
                <td class="num"><?= $t['cancelled'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div class="admin-grid-2">
      <section class="admin-panel chart-panel">
        <div class="admin-panel-head">
          <div>
            <h2>Events per month</h2>
            <p>SY <?= htmlspecialchars($schoolYear) ?> (<?= htmlspecialchars(format_event_date($yearStart)) ?> – <?= htmlspecialchars(format_event_date($yearEnd)) ?>), by venue type</p>
          </div>
          <a href="reports.php?school_year=<?= urlencode($schoolYear) ?>">Full report ›</a>
        </div>
        <?php if ($year['events'] === 0): ?>
          <p class="chart-empty">No events in this school year yet.</p>
        <?php else: ?>
          <?= chart_legend($monthSeries) ?>
          <?= chart_stacked_columns($monthColumns, $monthSeries, 'Events per month, SY ' . $schoolYear . ', by venue type') ?>
          <details class="chart-table-toggle">
            <summary>Show as table</summary>
            <table class="admin-table admin-table-compact">
              <thead><tr><th>Month</th><?php foreach (VENUE_TYPES as $label): ?><th class="num"><?= htmlspecialchars($label) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
              <tbody>
                <?php foreach ($monthColumns as $c): $sum = 0; ?>
                  <tr>
                    <td><?= htmlspecialchars($c['title']) ?></td>
                    <?php foreach ($monthSeries as $s): $v = (int) ($s['values'][$c['key']] ?? 0); $sum += $v; ?><td class="num"><?= $v ?></td><?php endforeach; ?>
                    <td class="num"><strong><?= $sum ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </details>
        <?php endif; ?>
      </section>

      <section class="admin-panel">
        <div class="admin-panel-head">
          <div>
            <h2>Participation</h2>
            <p>SY <?= htmlspecialchars($schoolYear) ?>, events that were not cancelled</p>
          </div>
        </div>
        <div class="kpi-grid kpi-grid-wide" style="margin-bottom:0">
          <div class="kpi" style="box-shadow:none;background:#f8f9fb"><span class="kpi-label">Registrations</span><span class="kpi-value"><?= number_format($year['registered']) ?></span></div>
          <div class="kpi" style="box-shadow:none;background:#f8f9fb"><span class="kpi-label">Attended</span><span class="kpi-value"><?= number_format($year['attended']) ?></span></div>
          <div class="kpi" style="box-shadow:none;background:#f8f9fb"><span class="kpi-label">Attendance rate</span><span class="kpi-value"><?= format_percent($year['attendance_rate']) ?></span><span class="kpi-sub">of registrants, ended events</span></div>
          <div class="kpi" style="box-shadow:none;background:#f8f9fb"><span class="kpi-label">Evaluations</span><span class="kpi-value"><?= number_format($year['evaluations']) ?></span></div>
          <div class="kpi" style="box-shadow:none;background:#f8f9fb;grid-column:1 / -1">
            <span class="kpi-label">Average rating</span>
            <span class="kpi-value"><?= format_rating($year['average_rating']) ?></span>
            <span class="kpi-sub"><?= $year['average_rating'] !== null ? htmlspecialchars(rating_interpretation($year['average_rating'])) . ' (out of 5)' : 'No evaluations yet' ?></span>
          </div>
        </div>
      </section>
    </div>

    <div class="admin-grid-halves">
      <section class="admin-panel">
        <div class="admin-panel-head">
          <div>
            <h2>This week's events</h2>
            <p><?= htmlspecialchars(week_label($weekStart)) ?></p>
          </div>
          <a href="event-organizer.php">Manage events ›</a>
        </div>
        <?php if (empty($thisWeek)): ?>
          <p class="admin-muted">No events this week.</p>
        <?php endif; ?>
        <?php foreach ($thisWeek as $r): ?>
          <div class="event-line">
            <div class="event-line-date"><strong><?= date('j', strtotime($r['start_date'])) ?></strong><span><?= date('M', strtotime($r['start_date'])) ?></span></div>
            <div class="event-line-info">
              <a href="../event-details.php?id=<?= urlencode($r['id']) ?>"><?= htmlspecialchars($r['title']) ?></a>
              <div><?= htmlspecialchars(implode(' · ', array_filter([$r['organization_name'] ?? '', label_of(VENUE_TYPES, $r['venue_type'], ''), 'For ' . label_of(EVENT_AUDIENCES, $r['audience'], '')]))) ?></div>
            </div>
            <div class="admin-row-actions">
              <a href="event-registrations.php?event=<?= urlencode($r['id']) ?>" class="btn-sm">Attendance</a>
              <a href="attendance-print.php?event=<?= urlencode($r['id']) ?>" class="btn-sm">Print sheet</a>
            </div>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="admin-panel">
        <div class="admin-panel-head">
          <div>
            <h2>Recently ended</h2>
            <p>Check attendance and evaluation results</p>
          </div>
        </div>
        <?php if (empty($recentlyEnded)): ?>
          <p class="admin-muted">No events have ended yet this school year.</p>
        <?php endif; ?>
        <?php foreach ($recentlyEnded as $r): ?>
          <div class="event-line">
            <div class="event-line-date"><strong><?= date('j', strtotime($r['end_date'])) ?></strong><span><?= date('M', strtotime($r['end_date'])) ?></span></div>
            <div class="event-line-info">
              <a href="../event-details.php?id=<?= urlencode($r['id']) ?>"><?= htmlspecialchars($r['title']) ?></a>
              <div>
                <?= (int) $r['attended_count'] ?> of <?= (int) $r['registered_count'] ?> attended
                · <?= (int) $r['evaluation_count'] ?> evaluation<?= (int) $r['evaluation_count'] === 1 ? '' : 's' ?>
                <?php if ($r['average_rating'] !== null): ?> · rated <?= format_rating((float) $r['average_rating']) ?><?php endif; ?>
              </div>
            </div>
            <div class="admin-row-actions">
              <a href="event-registrations.php?event=<?= urlencode($r['id']) ?>" class="btn-sm">Attendance</a>
              <a href="event-evaluations.php?event=<?= urlencode($r['id']) ?>" class="btn-sm btn-sm-accent">Evaluations</a>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/charts.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
