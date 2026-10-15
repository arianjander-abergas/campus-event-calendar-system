<?php
/**
 * Admin: event reports. Filter by school year, semester, department,
 * organization, audience and venue type (or a date range), then see:
 * totals, events per semester / month / week, per department and
 * organization, and the full event list. Print-ready ("Print report" prints
 * the totals and tables only, no graphs) and downloadable as an Excel
 * workbook. Numbers come from the event_report view.
 *
 *   ?school_year=2026-2027|all &semester=1st|2nd|summer &department=<id>
 *   &organization=<id> &audience=students|faculty|all
 *   &venue_type=on_campus|off_campus|online &from=Y-m-d &to=Y-m-d &export=xlsx
 */
require_once __DIR__ . '/../../config/supabase.php';
require_once __DIR__ . '/../../includes/charts.php';
require_once __DIR__ . '/../../includes/spreadsheet.php';

$user = require_admin();

$current = school_period_of();
$departments = get_departments();
$organizations = get_organizations();
$schoolYears = get_report_school_years();

$param = fn(string $key): string => get_param($key);
$isDate = fn(string $v): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$filters = [
    'school_year' => $param('school_year') === 'all' ? 'all'
        : (preg_match('/^\d{4}-\d{4}$/', $param('school_year')) ? $param('school_year') : $current['school_year']),
    'semester' => isset(SEMESTERS[$param('semester')]) ? $param('semester') : '',
    'department' => in_array($param('department'), array_column($departments, 'id'), true) ? $param('department') : '',
    'organization' => in_array($param('organization'), array_column($organizations, 'id'), true) ? $param('organization') : '',
    'audience' => isset(EVENT_AUDIENCES[$param('audience')]) ? $param('audience') : '',
    'venue_type' => isset(VENUE_TYPES[$param('venue_type')]) ? $param('venue_type') : '',
    'from' => $isDate($param('from')) ? $param('from') : '',
    'to' => $isDate($param('to')) ? $param('to') : '',
];
if (!in_array($filters['school_year'], $schoolYears, true) && $filters['school_year'] !== 'all') {
    $schoolYears[] = $filters['school_year'];
    rsort($schoolYears);
}

$rows = get_event_report($filters);
$totals = report_totals($rows);
$held = array_values(array_filter($rows, fn($r) => $r['status'] !== 'cancelled'));

// ---- Describe the filters (page subtitle, printout, Excel file name) ----
$deptName = array_column($departments, null, 'id')[$filters['department']] ?? null;
$orgName = array_column($organizations, 'name', 'id')[$filters['organization']] ?? '';
$scope = array_filter([
    $filters['school_year'] === 'all' ? 'All school years' : 'SY ' . $filters['school_year'],
    $filters['semester'] !== '' ? SEMESTERS[$filters['semester']] : '',
    $filters['from'] !== '' || $filters['to'] !== '' ? trim(($filters['from'] !== '' ? format_event_date($filters['from']) : '…') . ' to ' . ($filters['to'] !== '' ? format_event_date($filters['to']) : '…')) : '',
    $deptName ? 'Department: ' . department_label($deptName) : '',
    $orgName !== '' ? 'Organization: ' . $orgName : '',
    $filters['audience'] !== '' ? 'For ' . EVENT_AUDIENCES[$filters['audience']] : '',
    $filters['venue_type'] !== '' ? VENUE_TYPES[$filters['venue_type']] : '',
]);
$scopeText = implode(' · ', $scope);
$isFiltered = $filters['semester'] !== '' || $filters['department'] !== '' || $filters['organization'] !== ''
    || $filters['audience'] !== '' || $filters['venue_type'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''
    || $filters['school_year'] !== $current['school_year'];

// ---- Excel: the event list with every column ----
if (get_param('export') === 'xlsx') {
    send_xlsx('events-report-' . slugify($scopeText) . '.xlsx', 'Events report',
        ['Start date', 'End date', 'Event', 'Status', 'Organization', 'Department', 'Category', 'Audience', 'Venue type', 'Venue',
         'School year', 'Semester', 'Registered', 'Attended', 'No-show', 'Evaluations', 'Average rating', 'Interpretation'],
        array_map(fn($r) => [
            $r['start_date'], $r['end_date'], $r['title'], ucfirst($r['status']), $r['organization_name'] ?? '',
            $r['department_name'] ?? '', $r['category_name'] ?? '', label_of(EVENT_AUDIENCES, $r['audience'], ''),
            label_of(VENUE_TYPES, $r['venue_type'], ''), $r['venue'] ?? '', $r['school_year'], label_of(SEMESTERS, $r['semester'], ''),
            $r['registered_count'], $r['attended_count'], $r['no_show_count'], $r['evaluation_count'],
            $r['average_rating'] ?? '', $r['average_rating'] !== null ? rating_interpretation((float) $r['average_rating']) : '',
        ], $rows));
}

// ---- Months covered: the chosen dates, the school year (or semester), or the events' own range ----
if ($filters['from'] !== '' || $filters['to'] !== '') {
    $rangeFrom = $filters['from'] ?: ($rows[0]['start_date'] ?? date('Y-m-d'));
    $rangeTo = $filters['to'] ?: (end($rows)['start_date'] ?? date('Y-m-d'));
} elseif ($filters['school_year'] !== 'all') {
    [$rangeFrom, $rangeTo] = term_range($filters['school_year'], $filters['semester']);
} else {
    $rangeFrom = $rows[0]['start_date'] ?? date('Y-m-d');
    $rangeTo = end($rows)['start_date'] ?? date('Y-m-d');
}
$months = month_list($rangeFrom, $rangeTo);
if (count($months) > 36) {
    $months = array_keys(group_rows($held, fn($r) => substr($r['start_date'], 0, 7))); // long ranges: only months with events
}

$byMonth = group_rows($rows, fn($r) => substr($r['start_date'], 0, 7));
$byWeek = group_rows($held, fn($r) => week_start($r['start_date']));
ksort($byWeek);
$bySemester = group_rows($rows, fn($r) => $r['school_year'] . '|' . $r['semester']);
$byDepartment = group_rows($rows, fn($r) => $r['department_name'] ?? '');
ksort($byDepartment);
$byOrganization = group_rows($rows, fn($r) => $r['organization_name'] ?? '');
ksort($byOrganization);

$maxPerMonth = 1;
foreach ($months as $m) {
    $maxPerMonth = max($maxPerMonth, report_totals($byMonth[$m] ?? [])['events']);
}
$maxPerWeek = $byWeek ? max(array_map('count', $byWeek)) : 1;

// Chart: events per month by venue type.
$chartColumns = array_map(fn($m) => ['key' => $m, 'label' => month_label($m, true), 'title' => month_label($m), 'current' => $m === date('Y-m')], $months);
$chartSeries = [];
foreach (VENUE_TYPES as $type => $label) {
    $values = [];
    foreach ($held as $r) {
        if ($r['venue_type'] === $type) $values[substr($r['start_date'], 0, 7)] = ($values[substr($r['start_date'], 0, 7)] ?? 0) + 1;
    }
    $chartSeries[] = ['key' => $type, 'label' => $label, 'color' => VENUE_TYPE_COLORS[$type], 'values' => $values];
}

$cellBar = fn(int $value, int $max): string => '<div class="cell-bar"><span>' . $value . '</span><div class="cell-bar-track"><div class="cell-bar-fill" style="width:'
    . ($value > 0 ? max(2, round($value / max(1, $max) * 100)) : 0) . '%"></div></div></div>';

$query = fn(array $extra = []) => 'reports.php?' . http_build_query(array_filter($extra + $filters, fn($v) => $v !== '' && $v !== null));

$activeNav = 'admin-reports';
$breadcrumbs = [['Admin Dashboard', 'dashboard.php'], ['Reports', null]];
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body>

<div class="dashboard-shell no-rail">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <!-- On paper: the school's header instead of the page title -->
    <header class="print-head print-only">
      <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="">
      <div class="print-head-text">
        <strong><?= htmlspecialchars(SCHOOL_NAME) ?></strong>
        <span>Campus Event Hub</span>
        <h1>Events Report</h1>
      </div>
      <span style="width:64px"></span>
    </header>
    <p class="print-meta print-only"><strong>Coverage:</strong> <?= htmlspecialchars($scopeText) ?><br>
      <strong>Generated:</strong> <?= date('F j, Y g:i A') ?> by <?= htmlspecialchars($user['full_name']) ?></p>

    <div class="page-head">
      <div>
        <h1 class="page-title">Reports</h1>
        <p class="page-sub"><?= htmlspecialchars($scopeText) ?></p>
      </div>
      <div class="page-head-actions">
        <a href="<?= htmlspecialchars($query(['export' => 'xlsx'])) ?>" class="btn btn-outline">Download Excel</a>
        <button type="button" class="btn btn-primary" data-print>Print report</button>
      </div>
    </div>

    <?= render_flashes() ?>

    <form class="report-filters" method="get" action="reports.php">
      <label>School year
        <select name="school_year">
          <?php foreach ($schoolYears as $sy): ?>
            <option value="<?= htmlspecialchars($sy) ?>" <?= $filters['school_year'] === $sy ? 'selected' : '' ?>><?= htmlspecialchars($sy) ?><?= $sy === $current['school_year'] ? ' (current)' : '' ?></option>
          <?php endforeach; ?>
          <option value="all" <?= $filters['school_year'] === 'all' ? 'selected' : '' ?>>All school years</option>
        </select>
      </label>
      <label>Semester
        <select name="semester">
          <option value="">Whole school year</option>
          <?php foreach (SEMESTERS as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filters['semester'] === $key ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Department
        <select name="department">
          <option value="">All departments</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= htmlspecialchars($d['id']) ?>" <?= $filters['department'] === $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars(department_label($d)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Organization
        <select name="organization">
          <option value="">All organizations</option>
          <?php foreach ($organizations as $o): ?>
            <option value="<?= htmlspecialchars($o['id']) ?>" <?= $filters['organization'] === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Audience
        <select name="audience">
          <option value="">All audiences</option>
          <?php foreach (EVENT_AUDIENCES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filters['audience'] === $key ? 'selected' : '' ?>>For <?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Venue type
        <select name="venue_type">
          <option value="">All venue types</option>
          <?php foreach (VENUE_TYPES as $key => $label): ?>
            <option value="<?= $key ?>" <?= $filters['venue_type'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($filters['from'] !== '' || $filters['to'] !== ''): ?>
        <input type="hidden" name="from" value="<?= htmlspecialchars($filters['from']) ?>">
        <input type="hidden" name="to" value="<?= htmlspecialchars($filters['to']) ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-accent">Apply</button>
      <?php if ($isFiltered): ?><a href="reports.php" class="filter-clear">Reset</a><?php endif; ?>
    </form>

    <?php if ($filters['from'] !== '' || $filters['to'] !== ''): ?>
      <p class="admin-note no-print">Showing events that start from <?= htmlspecialchars($filters['from'] !== '' ? format_event_date($filters['from']) : 'the beginning') ?> to <?= htmlspecialchars($filters['to'] !== '' ? format_event_date($filters['to']) : 'the latest event') ?>. <a href="<?= htmlspecialchars($query(['school_year' => $filters['school_year'] === 'all' ? $current['school_year'] : $filters['school_year'], 'from' => '', 'to' => ''])) ?>">Show the whole school year</a></p>
    <?php endif; ?>

    <!-- Totals -->
    <div class="kpi-grid kpi-grid-wide">
      <div class="kpi"><span class="kpi-label">Events held</span><span class="kpi-value"><?= $totals['events'] ?></span><span class="kpi-sub"><?= $totals['on_campus'] ?> on-campus · <?= $totals['off_campus'] ?> off-campus · <?= $totals['online'] ?> online</span></div>
      <div class="kpi"><span class="kpi-label">For students / faculty</span><span class="kpi-value"><?= $totals['for_students'] ?> / <?= $totals['for_faculty'] ?></span><span class="kpi-sub">+ <?= $totals['for_all'] ?> for both</span></div>
      <div class="kpi"><span class="kpi-label">Cancelled</span><span class="kpi-value"><?= $totals['cancelled'] ?></span><span class="kpi-sub">not counted elsewhere</span></div>
      <div class="kpi"><span class="kpi-label">Registrations</span><span class="kpi-value"><?= number_format($totals['registered']) ?></span><span class="kpi-sub"><?= number_format($totals['attended']) ?> attended · <?= number_format($totals['no_show']) ?> no-show</span></div>
      <div class="kpi"><span class="kpi-label">Attendance rate</span><span class="kpi-value"><?= format_percent($totals['attendance_rate']) ?></span><span class="kpi-sub">of registrants, ended events</span></div>
      <div class="kpi"><span class="kpi-label">Evaluation</span><span class="kpi-value"><?= format_rating($totals['average_rating']) ?></span><span class="kpi-sub"><?= $totals['average_rating'] !== null ? htmlspecialchars(rating_interpretation($totals['average_rating'])) . ' · ' : '' ?><?= $totals['evaluations'] ?> response<?= $totals['evaluations'] === 1 ? '' : 's' ?></span></div>
    </div>

    <?php if (empty($rows)): ?>
      <section class="admin-panel"><p class="chart-empty">No events match these filters.<?= $isFiltered ? ' <a href="reports.php">Reset the filters</a>.' : '' ?></p></section>
    <?php else: ?>

    <!-- Per month -->
    <section class="admin-panel chart-panel no-print">
      <div class="admin-panel-head">
        <div>
          <h2>Events per month</h2>
          <p>By venue type · <?= htmlspecialchars(month_label($months[0])) ?> to <?= htmlspecialchars(month_label(end($months))) ?></p>
        </div>
      </div>
      <?= chart_legend($chartSeries) ?>
      <?= chart_stacked_columns($chartColumns, $chartSeries, 'Events per month by venue type, ' . $scopeText) ?>
    </section>

    <section class="admin-panel admin-panel-flush">
      <div class="admin-panel-head"><div><h2>Monthly summary</h2><p>Cancelled events are left out of every column except Cancelled.</p></div></div>
      <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
        <table class="admin-table admin-table-compact">
          <thead>
            <tr>
              <th>Month</th><th>Events</th>
              <th class="num">On-campus</th><th class="num">Off-campus</th><th class="num">Online</th>
              <th class="num">Students</th><th class="num">Faculty</th><th class="num">Both</th>
              <th class="num">Attended</th><th class="num">Rating</th><th class="num">Cancelled</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($months as $m): $t = report_totals($byMonth[$m] ?? []); ?>
              <tr>
                <td class="nowrap"><?= htmlspecialchars(month_label($m)) ?></td>
                <td><?= $cellBar($t['events'], $maxPerMonth) ?></td>
                <td class="num"><?= $t['on_campus'] ?></td><td class="num"><?= $t['off_campus'] ?></td><td class="num"><?= $t['online'] ?></td>
                <td class="num"><?= $t['for_students'] ?></td><td class="num"><?= $t['for_faculty'] ?></td><td class="num"><?= $t['for_all'] ?></td>
                <td class="num"><?= $t['attended'] ?></td><td class="num"><?= format_rating($t['average_rating']) ?></td><td class="num"><?= $t['cancelled'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>Total</td><td class="num" style="text-align:left"><?= $totals['events'] ?></td>
              <td class="num"><?= $totals['on_campus'] ?></td><td class="num"><?= $totals['off_campus'] ?></td><td class="num"><?= $totals['online'] ?></td>
              <td class="num"><?= $totals['for_students'] ?></td><td class="num"><?= $totals['for_faculty'] ?></td><td class="num"><?= $totals['for_all'] ?></td>
              <td class="num"><?= $totals['attended'] ?></td><td class="num"><?= format_rating($totals['average_rating']) ?></td><td class="num"><?= $totals['cancelled'] ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <div class="admin-grid-halves">
      <!-- Per semester -->
      <section class="admin-panel admin-panel-flush">
        <div class="admin-panel-head"><div><h2>Per semester</h2><p>Terms follow the <a href="school-calendar.php">school calendar</a>.</p></div></div>
        <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
          <table class="admin-table admin-table-compact">
            <thead><tr><th>Semester</th><th class="num">Events</th><th class="num">On-campus</th><th class="num">Online</th><th class="num">Attended</th><th class="num">Rating</th></tr></thead>
            <tbody>
              <?php foreach ($bySemester as $key => $group): [$sy, $sem] = explode('|', $key); $t = report_totals($group); ?>
                <tr>
                  <td><a class="row-link" href="<?= htmlspecialchars($query(['school_year' => $sy, 'semester' => $sem])) ?>"><?= htmlspecialchars(label_of(SEMESTERS, $sem)) ?></a><div class="admin-table-sub">SY <?= htmlspecialchars($sy) ?></div></td>
                  <td class="num"><strong><?= $t['events'] ?></strong></td><td class="num"><?= $t['on_campus'] ?></td><td class="num"><?= $t['online'] ?></td>
                  <td class="num"><?= $t['attended'] ?></td><td class="num"><?= format_rating($t['average_rating']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- Per week -->
      <section class="admin-panel admin-panel-flush">
        <div class="admin-panel-head"><div><h2>Per week</h2><p>Weeks run Monday to Sunday; only weeks with events are listed.</p></div></div>
        <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
          <table class="admin-table admin-table-compact">
            <thead><tr><th>Week</th><th>Events</th><th class="num">Attended</th></tr></thead>
            <tbody>
              <?php if (empty($byWeek)): ?><tr><td colspan="3" class="admin-table-empty">No events held.</td></tr><?php endif; ?>
              <?php foreach ($byWeek as $monday => $group): $t = report_totals($group); ?>
                <tr>
                  <td class="nowrap"><a class="row-link" href="<?= htmlspecialchars($query(['school_year' => 'all', 'semester' => '', 'from' => $monday, 'to' => date('Y-m-d', strtotime($monday . ' +6 days'))])) ?>"><?= htmlspecialchars(week_label($monday)) ?></a></td>
                  <td><?= $cellBar($t['events'], $maxPerWeek) ?></td>
                  <td class="num"><?= $t['attended'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <div class="admin-grid-halves">
      <?php foreach (['Department' => $byDepartment, 'Organization' => $byOrganization] as $heading => $groups): ?>
        <section class="admin-panel admin-panel-flush">
          <div class="admin-panel-head"><div><h2>Per <?= strtolower($heading) ?></h2></div></div>
          <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
            <table class="admin-table admin-table-compact">
              <thead><tr><th><?= $heading ?></th><th class="num">Events</th><th class="num">Attended</th><th class="num">Rate</th><th class="num">Rating</th></tr></thead>
              <tbody>
                <?php foreach ($groups as $name => $group): $t = report_totals($group); $first = $group[0]; ?>
                  <tr>
                    <td>
                      <?php if ($heading === 'Organization' && !empty($first['organization_logo_url'])): ?>
                        <span class="admin-org-cell"><img src="<?= htmlspecialchars(asset_url($first['organization_logo_url'])) ?>" alt="" class="admin-org-logo"><?= htmlspecialchars($name !== '' ? $name : 'None') ?></span>
                      <?php else: ?>
                        <?= htmlspecialchars($name !== '' ? $name : ($heading === 'Department' ? 'No department' : 'None')) ?>
                      <?php endif; ?>
                    </td>
                    <td class="num"><strong><?= $t['events'] ?></strong></td>
                    <td class="num"><?= $t['attended'] ?></td>
                    <td class="num"><?= format_percent($t['attendance_rate']) ?></td>
                    <td class="num"><?= format_rating($t['average_rating']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endforeach; ?>
    </div>

    <!-- Every event -->
    <section class="admin-panel admin-panel-flush">
      <div class="admin-panel-head"><div><h2>Event list (<?= count($rows) ?>)</h2><p>Rating = mean of all 1–5 answers (5 = Excellent).</p></div></div>
      <div class="admin-table-wrap" style="box-shadow:none;border-radius:0">
        <table class="admin-table admin-table-compact report-events">
          <thead>
            <tr><th>Date</th><th>Event</th><th>Organization</th><th>Audience</th><th>Venue type</th><th class="num">Registered</th><th class="num">Attended</th><th class="num">Evaluations</th><th class="num">Rating</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="nowrap"><?= htmlspecialchars(format_event_date($r['start_date'])) ?></td>
                <td>
                  <a class="row-link" href="../event-details.php?id=<?= urlencode($r['id']) ?>"><?= htmlspecialchars($r['title']) ?></a>
                  <div class="admin-table-sub"><?= htmlspecialchars(implode(' · ', array_filter([$r['category_name'] ?? '', $r['department_code'] ?? $r['department_name'] ?? '']))) ?></div>
                </td>
                <td><?= htmlspecialchars($r['organization_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars(label_of(EVENT_AUDIENCES, $r['audience'])) ?></td>
                <td class="nowrap"><span class="admin-org-cell"><span class="series-dot" style="background:<?= VENUE_TYPE_COLORS[$r['venue_type']] ?? '#999' ?>"></span><?= htmlspecialchars(label_of(VENUE_TYPES, $r['venue_type'])) ?></span></td>
                <td class="num"><?= (int) $r['registered_count'] ?></td>
                <td class="num"><?= (int) $r['attended_count'] ?></td>
                <td class="num"><a href="event-evaluations.php?event=<?= urlencode($r['id']) ?>"><?= (int) $r['evaluation_count'] ?></a></td>
                <td class="num"><?= $r['average_rating'] !== null ? format_rating((float) $r['average_rating']) : '—' ?></td>
                <td><span class="admin-status admin-status-<?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars(ucfirst($r['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <div class="print-signatures print-only">
      <div><strong><?= htmlspecialchars($user['full_name']) ?></strong><small>Prepared by</small></div>
      <div><strong>&nbsp;</strong><small>Noted by</small></div>
    </div>
  </main>

</div>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/ui.js"></script>
<script src="../assets/js/charts.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
