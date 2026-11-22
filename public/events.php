<?php
/**
 * Event Calendar — the one place to browse events. A month calendar with a
 * single filter bar; clicking a day shows every event on that date.
 *   ?month=2026-09        the month shown (default: this month)
 *   ?date=2026-09-30      the selected day (default: today, or the 1st event of the month)
 *   ?q=keyword &category=<id> &organization=<id> &audience=students|faculty|outsiders
 *   &venue_type=on_campus|off_campus|online
 *   ?mine=1               the logged-in user's registered events (a list)
 * The header / dashboard search boxes, "View All" links and the
 * organizations page all land here.
 */
require_once __DIR__ . '/../config/supabase.php';

$mine = get_param('mine') !== '';
$user = $mine ? require_login() : current_user();

$categories = get_categories();
$organizations = get_organizations();

// ---- My Events: a list of the user's registrations ----------------------
$events = [];
if ($mine) {
    $registrations = get_my_registrations(0);
    $evaluated = get_my_evaluated_event_ids();
    $events = array_map(function ($r) use ($evaluated) {
        $ev = $r['events'];
        $ev['my_label'] = match (true) {
            $r['status'] === 'attended' && in_array($ev['id'], $evaluated, true) => 'Completed',
            $r['status'] === 'attended' => 'Evaluate',
            $ev['status'] === 'cancelled' => 'Cancelled',
            default => 'Registered',
        };
        return $ev;
    }, $registrations);
}

// ---- Calendar -------------------------------------------------------------
$filters = [
    'q' => get_param('q'),
    'category' => is_uuid(get_param('category')) ? get_param('category') : '',
    'organization' => is_uuid(get_param('organization')) ? get_param('organization') : '',
    'audience' => in_array(get_param('audience'), ['students', 'faculty', 'outsiders'], true) ? get_param('audience') : '',
    'venue_type' => isset(VENUE_TYPES[get_param('venue_type')]) ? get_param('venue_type') : '',
];
$isFiltered = implode('', $filters) !== '';
$today = date('Y-m-d');

$selected = preg_match('/^\d{4}-\d{2}-\d{2}$/', get_param('date')) && strtotime(get_param('date')) ? get_param('date') : '';
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', get_param('month')) ? get_param('month') : ($selected !== '' ? substr($selected, 0, 7) : '');

// Searching or filtering without choosing a month (e.g. an organization's "All events"
// link): jump to the next matching event, or the latest past one.
$matches = [];
if (!$mine && ($filters['q'] !== '' || ($isFiltered && $month === ''))) {
    $matches = find_events($filters, '', '', 200);
    if ($month === '' && $matches) {
        $upcoming = array_values(array_filter($matches, fn($e) => ($e['end_date'] ?? $e['start_date']) >= $today));
        $jump = $upcoming[0] ?? end($matches);
        $selected = max($jump['start_date'], $today <= ($jump['end_date'] ?? $jump['start_date']) ? $today : $jump['start_date']);
        $month = substr($selected, 0, 7);
    }
}
if ($month === '') $month = date('Y-m');

$first = new DateTimeImmutable($month . '-01');
$gridStart = $first->modify('-' . (int) $first->format('w') . ' days');                  // Sunday on/before the 1st
$last = $first->modify('last day of this month');
$gridEnd = $last->modify('+' . (6 - (int) $last->format('w')) . ' days');                // Saturday on/after the last day

$byDate = [];
if (!$mine) {
    foreach (find_events($filters, $gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')) as $ev) {
        foreach (event_dates($ev) as $d) {
            $byDate[$d][] = $ev;
        }
    }
}
// Events running a week or longer are listed once above the calendar
// ("Ongoing") instead of filling every day cell; the day panel still lists them.
$ongoing = [];
foreach ($byDate as $dayEvents) {
    foreach ($dayEvents as $ev) {
        if (is_long_event($ev)) $ongoing[$ev['id']] = $ev;
    }
}
if ($selected === '' || substr($selected, 0, 7) !== $month) {
    // Today when it's in this month, else the month's first day with events, else the 1st.
    $monthDays = array_filter(array_keys($byDate), fn($d) => substr($d, 0, 7) === $month);
    sort($monthDays);
    $selected = substr($today, 0, 7) === $month ? $today : ($monthDays[0] ?? $first->format('Y-m-d'));
}

$counts = get_registration_counts();
$query = fn(array $extra): string => 'events.php?' . http_build_query(array_filter($filters + $extra, fn($v) => $v !== '' && $v !== null));
$prevMonth = $first->modify('-1 month')->format('Y-m');
$nextMonth = $first->modify('+1 month')->format('Y-m');

$activeNav = $mine ? 'my-events' : 'events';
$breadcrumbs = [['Home', BASE_URL . '/index.php'], [$mine ? 'My Events' : 'Event Calendar', null]];
$heading = $mine ? 'My Events' : 'Event Calendar';

/** "9:00 AM – 12:00 PM", "All day", or "Sep 30 – Oct 2" for multi-day events. */
function calendar_time_line(array $ev): string
{
    $multi = !empty($ev['end_date']) && $ev['end_date'] !== $ev['start_date'];
    $time = event_time_range($ev);
    if ($multi) {
        // Years only when the event crosses into another year ("Sep 1, 2026 – Dec 31, 2031").
        $fmt = substr($ev['start_date'], 0, 4) === substr($ev['end_date'], 0, 4) ? 'M j' : 'M j, Y';
        return date($fmt, strtotime($ev['start_date'])) . ' – ' . date($fmt, strtotime($ev['end_date'])) . ($time !== '' ? ' · ' . $time : '');
    }
    return $time !== '' ? $time : 'All day';
}
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php if ($mine): ?>
<meta name="robots" content="noindex">
<?php else: ?>
<?= seo_head_tags('Event Calendar — Campus Event Hub | LCUP', 'The La Consolacion University Philippines (LCUP) event calendar: every seminar, workshop, sports and cultural event by date, with venue, time and registration details.', 'events.php') ?>
<?php endif; ?>
<?= app_head_tags() ?>
<title><?= htmlspecialchars($heading) ?> — Campus Event Hub</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/calendar.css?v=<?= filemtime(__DIR__ . '/assets/css/calendar.css') ?>">
</head>
<body class="neu">

<div class="dashboard-shell no-rail">

<?php include __DIR__ . '/../includes/nav.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<main class="dash-main public-main">
  <?= render_flashes() ?>

  <div class="page-head">
    <div>
      <h1><?= htmlspecialchars($heading) ?></h1>
      <p class="page-sub">
        <?php if ($mine): ?>
          Events you've registered for. Open one to see its schedule, cancel, or evaluate it after you attend.
        <?php else: ?>
          Every campus event on one calendar. Click a date to see all of that day's events.
        <?php endif; ?>
      </p>
    </div>
    <?php if ($mine): ?>
      <a href="events.php" class="btn btn-outline">Open the calendar</a>
    <?php elseif ($user): ?>
      <a href="events.php?mine=1" class="btn btn-outline">My registered events</a>
    <?php endif; ?>
  </div>

  <?php if ($mine): ?>
    <div class="events-grid events-grid-page">
      <?php if (empty($events)): ?>
        <div class="events-empty">You haven't registered for any events yet. <a href="events.php">Find one on the calendar</a>.</div>
      <?php endif; ?>
      <?php foreach ($events as $ev): ?>
        <?php include __DIR__ . '/../includes/event-card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php else: ?>

  <!-- The one filter bar: keyword + category, organization, audience, venue -->
  <form class="filter-bar cal-filters" method="get" action="events.php" role="search">
    <input type="hidden" name="month" value="<?= htmlspecialchars($filters['q'] === '' ? $month : '') ?>">
    <input type="search" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Search events, organizations, or keywords" aria-label="Keyword">
    <select name="category" aria-label="Category">
      <option value="">All categories</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= htmlspecialchars($c['id']) ?>" <?= $filters['category'] === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="organization" aria-label="Organization">
      <option value="">All organizations</option>
      <?php foreach ($organizations as $o): ?>
        <option value="<?= htmlspecialchars($o['id']) ?>" <?= $filters['organization'] === $o['id'] ? 'selected' : '' ?>><?= htmlspecialchars(organization_label($o)) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="audience" aria-label="Audience">
      <option value="">Any audience</option>
      <option value="students" <?= $filters['audience'] === 'students' ? 'selected' : '' ?>>For students</option>
      <option value="faculty" <?= $filters['audience'] === 'faculty' ? 'selected' : '' ?>>For faculty</option>
      <?php if (audiences_ready()): ?><option value="outsiders" <?= $filters['audience'] === 'outsiders' ? 'selected' : '' ?>>Open to outsiders</option><?php endif; ?>
    </select>
    <select name="venue_type" aria-label="Venue type">
      <option value="">Any venue</option>
      <?php foreach (VENUE_TYPES as $key => $label): ?>
        <option value="<?= $key ?>" <?= $filters['venue_type'] === $key ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-accent">Apply</button>
    <?php if ($isFiltered): ?>
      <a href="events.php?month=<?= htmlspecialchars($month) ?>" class="filter-clear">Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($filters['q'] !== ''): ?>
    <div class="cal-matches">
      <strong><?= count($matches) ?> event<?= count($matches) === 1 ? '' : 's' ?> match "<?= htmlspecialchars($filters['q']) ?>"</strong>
      <?php foreach (array_slice($matches, 0, 12) as $m): ?>
        <a href="<?= htmlspecialchars($query(['month' => substr($m['start_date'], 0, 7), 'date' => $m['start_date']])) ?>"><?= htmlspecialchars(date('M j', strtotime($m['start_date']))) ?> · <?= htmlspecialchars($m['title']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($ongoing): ?>
    <section class="cal-ongoing" aria-labelledby="calOngoingTitle">
      <h2 id="calOngoingTitle">Ongoing <span><?= count($ongoing) ?> event<?= count($ongoing) === 1 ? '' : 's' ?> running a week or more</span></h2>
      <?php
        // The first few are shown; the rest open with "Show N more" (no script needed).
        $ongoingShown = 6;
        $ongoingItem = fn(array $ev): string => '<a class="cal-ongoing-item" href="event-details.php?id=' . urlencode($ev['id']) . '" data-event-modal>'
            . '<strong>' . htmlspecialchars($ev['title']) . '</strong>'
            . '<span>' . htmlspecialchars(event_date_range($ev)) . ' · ' . htmlspecialchars(event_place($ev)) . '</span></a>';
        $ongoingList = array_values($ongoing);
      ?>
      <div class="cal-ongoing-list">
        <?php foreach (array_slice($ongoingList, 0, $ongoingShown) as $ev): ?>
          <?= $ongoingItem($ev) ?>
        <?php endforeach; ?>
      </div>
      <?php if (count($ongoingList) > $ongoingShown): ?>
        <details class="cal-ongoing-more">
          <summary>Show <?= count($ongoingList) - $ongoingShown ?> more</summary>
          <div class="cal-ongoing-list">
            <?php foreach (array_slice($ongoingList, $ongoingShown) as $ev): ?>
              <?= $ongoingItem($ev) ?>
            <?php endforeach; ?>
          </div>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="cal-layout">
    <section class="cal-card" aria-labelledby="calMonthTitle">
      <div class="cal-toolbar">
        <a href="<?= htmlspecialchars($query(['month' => $prevMonth])) ?>" class="cal-step" aria-label="Previous month">‹</a>
        <h2 id="calMonthTitle"><?= htmlspecialchars($first->format('F Y')) ?></h2>
        <a href="<?= htmlspecialchars($query(['month' => $nextMonth])) ?>" class="cal-step" aria-label="Next month">›</a>
        <?php if ($month !== date('Y-m')): ?>
          <a href="<?= htmlspecialchars($query(['month' => date('Y-m')])) ?>" class="btn btn-outline cal-today-btn">Today</a>
        <?php endif; ?>
      </div>

      <table class="cal-table" data-calendar>
        <thead>
          <tr><?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?><th scope="col"><?= $dow ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
            $iso = $d->format('Y-m-d');
            $dayEvents = $byDate[$iso] ?? [];
            $chipEvents = array_values(array_filter($dayEvents, fn($e) => !is_long_event($e))); // ongoing ones are in the strip above
            $classes = array_filter([
              'cal-cell',
              $d->format('Y-m') !== $month ? 'is-outside' : '',
              $iso === $today ? 'is-today' : '',
              $iso === $selected ? 'is-selected' : '',
              $chipEvents ? 'has-events' : '',
              $iso < $today ? 'is-past' : '',
            ]);
          ?>
            <?php if ($d->format('w') === '0'): ?><tr><?php endif; ?>
            <td class="<?= implode(' ', $classes) ?>">
              <a href="<?= htmlspecialchars($query(['month' => $d->format('Y-m'), 'date' => $iso])) ?>" class="cal-day" data-date="<?= $iso ?>"
                 aria-label="<?= htmlspecialchars($d->format('l, F j') . ': ' . (count($dayEvents) ?: 'no') . ' event' . (count($dayEvents) === 1 ? '' : 's')) ?>"<?= $iso === $selected ? ' aria-current="date"' : '' ?>>
                <span class="cal-num"><?= $d->format('j') ?></span>
                <?php foreach (array_slice($chipEvents, 0, 3) as $ev): ?>
                  <?php $phase = event_phase($ev); ?>
                  <?php $level = event_level($ev); ?>
                  <span class="cal-chip cal-level-<?= $level ?> cal-chip-<?= $phase ?>" title="<?= htmlspecialchars($ev['title'] . ' — ' . EVENT_LEVELS[$level] . ' · ' . ['upcoming' => 'Upcoming', 'ongoing' => 'Happening now', 'done' => 'Completed'][$phase]) ?>"><i class="cal-phase-dot" aria-hidden="true"></i><?= htmlspecialchars($ev['title']) ?></span>
                <?php endforeach; ?>
                <?php if (count($chipEvents) > 3): ?><span class="cal-more">+<?= count($chipEvents) - 3 ?> more</span><?php endif; ?>
                <?php if ($chipEvents): ?><span class="cal-dot" aria-hidden="true"><?= count($chipEvents) ?></span><?php endif; ?>
              </a>
            </td>
            <?php if ($d->format('w') === '6'): ?></tr><?php endif; ?>
          <?php endfor; ?>
        </tbody>
      </table>

      <div class="cal-legend" aria-hidden="true">
        <?php foreach (EVENT_LEVELS as $key => $label): ?>
          <span><i class="cal-chip cal-level-<?= $key ?>"></i> <?= $label ?></span>
        <?php endforeach; ?>
        <span class="cal-legend-gap"><i class="cal-phase-dot cal-phase-upcoming"></i> Upcoming</span>
        <span><i class="cal-phase-dot cal-phase-ongoing"></i> Happening now</span>
        <span><i class="cal-phase-dot cal-phase-done"></i> Completed</span>
      </div>
    </section>

    <!-- All events of the clicked day, in a pop-up (assets/js/calendar.js). One section per day
         that has events; the script shows the clicked one. Open on load when the link chose a date. -->
    <dialog class="event-modal cal-day-panel" data-day-dialog aria-label="Events on this date"<?= get_param('date') !== '' ? ' open' : '' ?>>
      <button type="button" class="event-modal-close" data-day-close aria-label="Close">&times;</button>
      <div class="event-modal-body">
      <?php foreach ($byDate as $iso => $dayEvents): ?>
        <section class="cal-day-events" id="day-<?= $iso ?>"<?= $iso === $selected ? '' : ' hidden' ?>>
          <header class="cal-day-head">
            <h2><?= htmlspecialchars(date('l, F j, Y', strtotime($iso))) ?></h2>
            <p class="cal-day-count"><?= count($dayEvents) ?> event<?= count($dayEvents) === 1 ? '' : 's' ?><?= $iso === $today ? ' · Today' : '' ?></p>
          </header>
          <div class="cal-day-list-wrap">
          <?php foreach ($dayEvents as $ev):
            $phase = event_phase($ev);
            $levels = year_levels_label($ev['year_levels'] ?? []);
          ?>
            <a class="cal-event cal-event-<?= $phase ?>" href="event-details.php?id=<?= urlencode($ev['id']) ?>" data-event-modal>
              <span class="cal-event-time"><?= htmlspecialchars(calendar_time_line($ev)) ?></span>
              <strong class="cal-event-title"><?= htmlspecialchars($ev['title']) ?></strong>
              <span class="cal-event-meta"><?= icon(($ev['venue_type'] ?? '') === 'online' ? 'online' : 'location', 13) ?> <?= htmlspecialchars(event_place($ev)) ?></span>
              <?php if (!empty($ev['organizations']['name'])): ?>
                <span class="cal-event-meta"><?= icon('organizations', 13) ?> <?= htmlspecialchars($ev['organizations']['name']) ?></span>
              <?php endif; ?>
              <span class="cal-event-meta"><?= icon('audience', 13) ?> For <?= htmlspecialchars(event_audience_label($ev)) ?><?= $levels !== '' ? ' · ' . htmlspecialchars($levels) : '' ?></span>
              <span class="cal-event-tags">
                <span class="cal-tag cal-tag-level cal-level-<?= event_level($ev) ?>"><?= EVENT_LEVELS[event_level($ev)] ?></span>
                <span class="cal-tag"><?= htmlspecialchars(event_category_label($ev)) ?></span>
                <?php if (is_required_for_me($ev['id'])): ?><span class="cal-tag cal-tag-required">Required for you</span><?php endif; ?>
                <?php if (($ev['status'] ?? '') === 'completed'): ?><span class="cal-tag cal-tag-done">Completed</span>
                <?php elseif ($phase === 'done'): ?><span class="cal-tag cal-tag-muted"><?= ($ev['status'] ?? '') === 'cancelled' ? 'Cancelled' : 'Ended' ?></span>
                <?php elseif ($phase === 'ongoing'): ?><span class="cal-tag cal-tag-today">Happening now</span>
                <?php else: ?><span class="cal-tag cal-tag-upcoming">Upcoming</span><?php endif; ?>
                <span class="cal-tag cal-tag-plain"><?= (int) ($counts[$ev['id']] ?? 0) ?> going</span>
              </span>
            </a>
          <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>
      <section class="cal-day-events" id="day-empty"<?= isset($byDate[$selected]) ? ' hidden' : '' ?>>
        <header class="cal-day-head">
          <h2 data-empty-date><?= htmlspecialchars(date('l, F j, Y', strtotime($selected))) ?></h2>
          <p class="cal-day-count">No events on this date<?= $isFiltered ? ' that match your filters' : '' ?>.</p>
        </header>
        <div class="cal-day-list-wrap">
          <p class="cal-day-hint">Dates with events are marked on the calendar. Use ‹ › to see other months.</p>
        </div>
      </section>
      </div>
    </dialog>
  </div>
  <?php endif; ?>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script src="assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/assets/js/sidebar.js') ?>"></script>
<script src="assets/js/ui.js?v=<?= filemtime(__DIR__ . '/assets/js/ui.js') ?>"></script>
<script src="assets/js/calendar.js?v=<?= filemtime(__DIR__ . '/assets/js/calendar.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
