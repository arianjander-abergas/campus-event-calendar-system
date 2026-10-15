<?php
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login(); // redirects to login.php if not authenticated

$events = get_upcoming_events(9);            // carousel: today's events first, 3 per page
$myRegistrations = get_my_registrations(5);
$trending = get_trending_events();
$calendarEvents = get_calendar_events();    // small calendar: click a day to list its events
$notifications = get_my_notifications();     // bell + Announcements panel
$toEvaluate = get_my_pending_evaluations();  // attended, not evaluated yet

$activeNav = 'dashboard';
$topbarSearch = true;
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="neu">

<div class="dashboard-shell">

  <?php include __DIR__ . '/../../includes/nav.php'; ?>
  <?php include __DIR__ . '/../../includes/topbar.php'; ?>

  <main class="dash-main">
    <?= render_flashes() ?>

    <div class="dash-greeting">
      <h1>Welcome, <?= htmlspecialchars(explode(' ', trim($user['full_name']))[0]) ?>!</h1>
      <p>Stay updated with campus events and opportunities.</p>
    </div>

    <?php if ($toEvaluate): ?>
      <section class="eval-callout" aria-labelledby="evalCalloutTitle">
        <h2 id="evalCalloutTitle"><?= icon('evaluation', 20) ?> Please evaluate <?= count($toEvaluate) === 1 ? 'an event' : count($toEvaluate) . ' events' ?> you attended</h2>
        <div class="eval-callout-list">
          <?php foreach (array_slice($toEvaluate, 0, 4) as $ev): ?>
            <a href="evaluate.php?event=<?= urlencode($ev['id']) ?>" class="eval-callout-item">
              <span><?= htmlspecialchars($ev['title']) ?><small><?= htmlspecialchars(event_date_range($ev)) ?></small></span>
              <strong>Evaluate ›</strong>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <div class="section-head">
      <h2>Upcoming events</h2>
      <a href="../events.php" class="view-all">View All</a>
    </div>
    <div class="dash-events-row" data-carousel="#upcomingDots" data-per-page="3">
      <?php if (empty($events)): ?>
        <p class="events-empty">No upcoming events yet.</p>
      <?php endif; ?>
      <?php foreach ($events as $ev): ?>
        <?php include __DIR__ . '/../../includes/event-card.php'; ?>
      <?php endforeach; ?>
    </div>
    <div class="carousel-dots" id="upcomingDots"></div>

    <div class="two-col-lists">
      <div class="list-card" id="registered-events">
        <div class="section-head">
          <h2>Your registered events</h2>
          <a href="../events.php?mine=1" class="view-all">View All</a>
        </div>

        <?php if (empty($myRegistrations)): ?>
          <p class="mini-meta">You haven't registered for any events yet. <a href="../events.php">Find one</a>.</p>
        <?php endif; ?>
        <?php foreach ($myRegistrations as $reg):
          $ev = $reg['events'];
          // "Not Available" once the event is cancelled or already over.
          $unavailable = in_array($ev['status'], ['cancelled', 'completed'], true)
              || ($ev['end_date'] ?? $ev['start_date']) < date('Y-m-d');
          // Attended but not evaluated yet: the row opens the evaluation form.
          $toEvaluate = $reg['status'] === 'attended' && !has_evaluated($ev['id']);
          $rowHref = $toEvaluate ? 'evaluate.php?event=' . urlencode($ev['id']) : '../event-details.php?id=' . urlencode($ev['id']);
        ?>
          <a class="mini-row" href="<?= htmlspecialchars($rowHref) ?>">
            <div class="mini-thumb" style="background-image:url('<?= css_url(event_poster_url($ev['poster_url'] ?? null)) ?>');"></div>
            <div class="mini-info">
              <div class="mini-title"><?= htmlspecialchars($ev['title']) ?></div>
              <div class="mini-meta"><?= icon('calendar', 13) ?> <?= htmlspecialchars(event_date_range($ev)) ?> &nbsp; <?= icon('location', 13) ?> <?= htmlspecialchars(event_place($ev)) ?></div>
            </div>
            <?php if ($toEvaluate): ?>
              <span class="mini-badge badge-evaluate">Evaluate</span>
            <?php elseif ($reg['status'] === 'attended'): ?>
              <span class="mini-badge badge-completed" title="Attended and evaluated">Completed</span>
            <?php elseif ($ev['status'] === 'cancelled'): ?>
              <span class="mini-badge badge-unavailable">Cancelled</span>
            <?php elseif ($unavailable): ?>
              <span class="mini-badge badge-muted">Ended</span>
            <?php else: ?>
              <span class="mini-badge badge-registered">Registered</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="list-card" id="trending-events">
        <div class="section-head">
          <h2>Trending events</h2>
          <a href="../events.php" class="view-all">Calendar</a>
        </div>

        <?php if (empty($trending)): ?>
          <p class="mini-meta">No upcoming events yet.</p>
        <?php endif; ?>
        <?php foreach ($trending as $ev): ?>
          <a class="mini-row" href="../event-details.php?id=<?= urlencode($ev['id']) ?>">
            <div class="mini-thumb" style="background-image:url('<?= css_url(event_poster_url($ev['poster_url'] ?? null)) ?>');"></div>
            <div class="mini-info">
              <div class="mini-title"><?= htmlspecialchars($ev['title']) ?></div>
              <div class="mini-meta"><?= icon('calendar', 13) ?> <?= htmlspecialchars(event_date_range($ev)) ?> &nbsp; <?= icon('location', 13) ?> <?= htmlspecialchars(event_place($ev)) ?></div>
            </div>
            <span class="mini-going"><?= icon('people', 13) ?> <?= (int) $ev['going'] ?> going</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

  <!-- Right rail -->
  <aside class="right-rail">
    <div class="rail-card" id="dashboard-calendar-card">
      <div class="cal-head">
        <h3 class="cal-head-title">Calendar</h3>
        <div class="cal-head-month">
          <button type="button" id="calPrev" aria-label="Previous month">‹</button>
          <span id="calendar-month" aria-live="polite"></span>
          <button type="button" id="calNext" aria-label="Next month">›</button>
        </div>
      </div>
      <div class="cal-grid" id="calendar-grid" data-event-url="../event-details.php?id=" data-calendar-url="../events.php">
        <!-- filled by assets/js/main.js -->
      </div>
      <div class="cal-day-list" id="calendar-day-list" aria-live="polite">
        <p>Click a date to see its events.</p>
      </div>
    </div>

    <div class="rail-card">
      <h3>Announcements</h3>
      <?php if (empty($notifications)): ?>
        <p class="rail-empty">No announcements yet.</p>
      <?php endif; ?>
      <?php foreach (array_slice($notifications, 0, 5) as $n): ?>
        <?php $annHref = !empty($n['event_id']) ? '../event-details.php?id=' . urlencode($n['event_id']) : null; ?>
        <<?= $annHref ? 'a href="' . htmlspecialchars($annHref) . '"' : 'div' ?> class="announcement-item<?= ($n['status'] ?? '') !== 'read' ? ' unread' : '' ?>">
          <span class="ann-icon info" aria-hidden="true"><?= icon('megaphone', 18) ?></span>
          <div>
            <div class="ann-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="ann-date"><?= htmlspecialchars(format_event_date($n['created_at'])) ?></div>
          </div>
        </<?= $annHref ? 'a' : 'div' ?>>
      <?php endforeach; ?>
    </div>
  </aside>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script src="../assets/js/sidebar.js"></script>
<script src="../assets/js/ui.js"></script>
<script>window.CALENDAR_EVENTS = <?= json_encode($calendarEvents) ?>;</script>
<script src="../assets/js/main.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
