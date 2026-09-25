<?php
require_once __DIR__ . '/../../config/supabase.php';

$user = require_login(); // redirects to login.php if not authenticated
// Upcoming events: anything happening today first, then the next dates.
$events = get_upcoming_events(3);
$myRegistrations = get_my_registrations();
$trending = get_trending_events();
// Dates for the calendar dots (main.js buildCalendar()).
$calendarEvents = array_map(fn($e) => ['title' => $e['title'], 'start' => $e['start_date'], 'end' => $e['end_date']],
    supabase_request('events', 'select=title,start_date,end_date&status=eq.published'));
// There is no `announcements` table in Supabase — the real table is
// `notifications` (event_id/user_id nullable, title, message, status,
// created_at). Pull the most recent ones for this panel.
$announcements = supabase_request('notifications', 'select=*&order=created_at.desc&limit=6', 'GET', null, false, $_SESSION['access_token'] ?? null);

// Small helper to pull initials for the avatar bubble
function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= substr($p, 0, 1); }
    return strtoupper($out) ?: 'U';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — Campus Event Hub</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<div class="dashboard-shell sidebar-pinned">

  <!-- Sidebar
   -->
  <?php include __DIR__ . '/../../includes/nav.php'; ?>

  <!-- Top bar -->
  <header class="dash-topbar">
    <div class="search-box">
      <span>🔍</span>
      <input type="text" placeholder="Search for events, organizations, or keywords">
    </div>
    <div class="topbar-right">
      <?php $unread = count(array_filter($announcements, fn($n) => ($n['status'] ?? '') !== 'read')); ?>
      <div class="bell">🔔<?php if ($unread > 0): ?><span class="dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?></div>
      <div class="user-chip">
        <div class="avatar"><?= htmlspecialchars(initials($user['full_name'])) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="user-role"><?= htmlspecialchars(role_display($user['role'])) ?></div>
        </div>
        <a href="../auth/logout.php" style="margin-left:8px; font-size:0.78rem; color:var(--text-muted);">Log out</a>
      </div>
    </div>
  </header>

  <!-- Main content -->
  <main class="dash-main">
    <div class="dash-greeting">
      <h1>Welcome, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?> !</h1>
      <p>stay updated with campus and opportunities</p>
    </div>

    <div class="section-head">
      <h2>Upcoming events</h2>
      <a href="#" class="view-all">View All</a>
    </div>
    <div class="dash-events-row">
      <?php if (empty($events)): ?>
        <p class="events-empty">No upcoming events yet.</p>
      <?php endif; ?>
      <?php foreach ($events as $ev):
        // categories comes from a joined table, so it may be null/missing
        $categoryName = $ev['categories']['name'] ?? 'Event';
      ?>
        <article class="event-card" data-event-id="<?= htmlspecialchars((string)$ev['id']) ?>">
          <div class="event-thumb" style="background-image:url('<?= htmlspecialchars($ev['poster_url'] ?? '') ?>');">
            <span class="event-tag <?= $categoryName === 'Event' ? 'tag-event' : 'tag-seminar' ?>">
              <?= htmlspecialchars($categoryName) ?>
            </span>
          </div>
          <div class="event-body">
            <h3 class="event-name"><?= htmlspecialchars($ev['title']) ?></h3>
            <div class="event-meta">📅 <?= htmlspecialchars(format_event_date($ev['start_date'])) ?></div>
            <div class="event-meta">📍 <?= htmlspecialchars($ev['venue'] ?: 'Online') ?></div>
            <div class="event-meta">👥 <?= get_registration_counts()[$ev['id']] ?? 0 ?> going</div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="carousel-dots">
      <span class="active"></span><span></span><span></span>
    </div>

    <div class="two-col-lists">
      <div class="list-card" id="registered-events">
        <div class="section-head">
          <h2>Your registered Events</h2>
          <a href="#" class="view-all">View All</a>
        </div>

        <?php if (empty($myRegistrations)): ?>
          <p class="mini-meta">You haven't registered for any events yet.</p>
        <?php endif; ?>
        <?php foreach ($myRegistrations as $reg):
          $ev = $reg['events'];
          // "Not Available" once the event is cancelled or already over.
          $unavailable = in_array($ev['status'], ['cancelled', 'completed'], true)
              || ($ev['end_date'] ?? $ev['start_date']) < date('Y-m-d');
        ?>
          <a class="mini-row" href="../event-details.php?id=<?= urlencode($ev['id']) ?>">
            <div class="mini-thumb" style="background-image:url('<?= htmlspecialchars($ev['poster_url'] ?? '') ?>');"></div>
            <div class="mini-info">
              <div class="mini-title"><?= htmlspecialchars($ev['title']) ?></div>
              <div class="mini-meta">📅 <?= htmlspecialchars(format_event_date($ev['start_date'])) ?> &nbsp; 📍 <?= htmlspecialchars($ev['venue'] ?: 'Online') ?></div>
            </div>
            <?php if ($reg['status'] === 'attended'): ?>
              <span class="mini-badge badge-registered">Attended</span>
            <?php elseif ($unavailable): ?>
              <span class="mini-badge badge-unavailable">Not Available</span>
            <?php else: ?>
              <span class="mini-badge badge-registered">Registered</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="list-card" id="trending-events">
        <div class="section-head">
          <h2>Trending Events</h2>
          <a href="#" class="view-all">View All</a>
        </div>

        <?php if (empty($trending)): ?>
          <p class="mini-meta">No upcoming events yet.</p>
        <?php endif; ?>
        <?php foreach ($trending as $ev): ?>
          <a class="mini-row" href="../event-details.php?id=<?= urlencode($ev['id']) ?>">
            <div class="mini-thumb" style="background-image:url('<?= htmlspecialchars($ev['poster_url'] ?? '') ?>');"></div>
            <div class="mini-info">
              <div class="mini-title"><?= htmlspecialchars($ev['title']) ?></div>
              <div class="mini-meta">📅 <?= htmlspecialchars(format_event_date($ev['start_date'])) ?> &nbsp; 📍 <?= htmlspecialchars($ev['venue'] ?: 'Online') ?></div>
            </div>
            <span class="mini-going">👥 <?= (int) $ev['going'] ?> going</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </main>

  <!-- Right rail -->
  <aside class="right-rail">
    <div class="rail-card" id="dashboard-calendar-card">
      <div class="cal-head">
        <span>Calendar</span>
        <span id="calendar-month"></span>
        <span style="display:flex; gap:4px;"><button type="button" id="calPrev" aria-label="Previous month">‹</button><button type="button" id="calNext" aria-label="Next month">›</button></span>
      </div>
      <div class="cal-grid" id="calendar-grid">
        <!-- filled by js/main.js -->
      </div>
    </div>

    <div class="rail-card filters-card">
      <h3>Filters</h3>
      <select><option>All Event Types</option></select>
      <select><option>All Organizations</option></select>
      <select><option>All Colleges</option></select>
      <button class="btn btn-accent">Apply Filters</button>
    </div>

    <div class="rail-card">
      <h3>Announcements</h3>
      <?php if (empty($announcements)): ?>
        <p style="font-size:0.8rem; color:var(--text-muted);">No announcements yet.</p>
      <?php endif; ?>
      <?php foreach ($announcements as $a):
        $type = $a['type'] ?? 'info'; // notifications has no 'type' column; default the icon
      ?>
        <div class="announcement-item">
          <span class="ann-icon <?= htmlspecialchars($type) ?>">
            <?= $type === 'alert' ? '⚠️' : ($type === 'reminder' ? '📄' : '📣') ?>
          </span>
          <div>
            <div class="ann-title"><?= htmlspecialchars($a['title']) ?></div>
            <div class="ann-date"><?= htmlspecialchars(format_event_date($a['created_at'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>

</div>

<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="../assets/js/supabase-client.js"></script>
<script src="../assets/js/sidebar.js"></script>
<script>window.CALENDAR_EVENTS = <?= json_encode($calendarEvents) ?>;</script>
<script src="../assets/js/main.js"></script>
</body>
</html>