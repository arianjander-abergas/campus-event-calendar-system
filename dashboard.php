<?php
require_once __DIR__ . '/config/supabase.php';

$user = require_login(); // redirects to login.php if not authenticated
$events = supabase_request('events');
$announcements = supabase_request('announcements');

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
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="dashboard-shell">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-top">
      <a href="index.php" class="brand">
        <span class="brand-icon">📅</span>
        <span class="brand-text">
          <span class="campus">CAMPUS</span>
          <span class="hub">EVENT HUB</span>
        </span>
      </a>
      <button class="sidebar-toggle" aria-label="Toggle sidebar">☰</button>
    </div>

    <nav class="side-nav">
      <a href="index.php"><span class="icon">🏠</span> Home</a>
      <a href="dashboard.php" class="active"><span class="icon">🗂️</span> Dashboard</a>
      <a href="#event"><span class="icon">🎟️</span> Event <span style="margin-left:auto;">⌄</span></a>
    </nav>
  </aside>

  <!-- Top bar -->
  <header class="dash-topbar">
    <div class="search-box">
      <span>🔍</span>
      <input type="text" placeholder="Search for events, organizations, or keywords">
    </div>
    <div class="topbar-right">
      <div class="bell">🔔<span class="dot">2</span></div>
      <div class="user-chip">
        <div class="avatar"><?= htmlspecialchars(initials($user['full_name'])) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($user['full_name']) ?></div>
          <div class="user-role"><?= htmlspecialchars($user['role']) ?></div>
        </div>
        <a href="logout.php" style="margin-left:8px; font-size:0.78rem; color:var(--text-muted);">Log out</a>
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
      <?php foreach (array_slice($events, 0, 3) as $ev):
        // categories comes from a joined table, so it may be null/missing
        $categoryName = $ev['categories']['name'] ?? 'Event';
      ?>
        <article class="event-card">
          <div class="event-thumb" style="background-image:url('<?= htmlspecialchars($ev['poster_url']) ?>');">
            <span class="event-tag <?= $categoryName === 'Event' ? 'tag-event' : 'tag-seminar' ?>">
              <?= htmlspecialchars($categoryName) ?>
            </span>
          </div>
          <div class="event-body">
            <h3 class="event-name"><?= htmlspecialchars($ev['title']) ?></h3>
            <div class="event-meta">📅 <?= htmlspecialchars($ev['start_date']) ?></div>
            <div class="event-meta">📍 <?= htmlspecialchars($ev['venue']) ?></div>
            <!-- attendee_count no longer lives on the events row; it needs a
                 separate count query (e.g. registrations count per event_id).
                 Until that's wired up we just omit the "going" line. -->
          </div>
        </article>
      <?php endforeach; ?>
    </div>
    <div class="carousel-dots">
      <span class="active"></span><span></span><span></span>
    </div>

    <div class="two-col-lists">
      <div class="list-card">
        <div class="section-head">
          <h2>Your registered Events</h2>
          <a href="#" class="view-all">View All</a>
        </div>

        <div class="mini-row">
          <div class="mini-thumb" style="background-image:url('assets/event-pace.jpg');"></div>
          <div class="mini-info">
            <div class="mini-title">JPSSITE PACE LEVEL UP v.6.2</div>
            <div class="mini-meta">📅 Month 00 0000 &nbsp; 📍 AVR 1, CITE Building</div>
          </div>
          <span class="mini-badge badge-registered">Registered</span>
        </div>

        <div class="mini-row">
          <div class="mini-thumb" style="background-image:url('assets/event-osh.jpg');"></div>
          <div class="mini-info">
            <div class="mini-title">OSH Training or SIES-ACpEs</div>
            <div class="mini-meta">📅 Month 00 0000 &nbsp; 📍 AVR 1, CITE Building</div>
          </div>
          <span class="mini-badge badge-unavailable">Not Available</span>
        </div>

        <div class="mini-row">
          <div class="mini-thumb" style="background-image:url('assets/event-genz.jpg');"></div>
          <div class="mini-info">
            <div class="mini-title">GEN Z Night 2026</div>
            <div class="mini-meta">📅 Month 00 0000 &nbsp; 📍 KALINANGAN Auditorium</div>
          </div>
          <span class="mini-badge badge-registered">Registered</span>
        </div>
      </div>

      <div class="list-card">
        <div class="section-head">
          <h2>Trending Events</h2>
          <a href="#" class="view-all">View All</a>
        </div>

        <div class="mini-row">
          <div class="mini-thumb" style="background-image:url('assets/event-liveband.jpg');"></div>
          <div class="mini-info">
            <div class="mini-title">JPSSITE Live Band Upsite</div>
            <div class="mini-meta">📅 Month 00 0000 &nbsp; 📍 AVR 1, CITE Building</div>
          </div>
          <span class="mini-going">👥 000 going</span>
        </div>

        <div class="mini-row">
          <div class="mini-thumb" style="background-image:url('assets/event-tawag.jpg');"></div>
          <div class="mini-info">
            <div class="mini-title">Tawag ng JPSSite</div>
            <div class="mini-meta">📅 Month 00 0000 &nbsp; 📍 AVR 1, CITE Building</div>
          </div>
          <span class="mini-going">👥 000 going</span>
        </div>

        <div class="mini-row">
          <div class="mini-thumb" style="background-image:url('assets/event-genz.jpg');"></div>
          <div class="mini-info">
            <div class="mini-title">GEN Z Night 2026</div>
            <div class="mini-meta">📅 Month 00 0000 &nbsp; 📍 KALINANGAN Auditorium</div>
          </div>
          <span class="mini-going">👥 000 going</span>
        </div>
      </div>
    </div>
  </main>

  <!-- Right rail -->
  <aside class="right-rail">
    <div class="rail-card">
      <div class="cal-head">
        <span>Calendar</span>
        <span>May 2026</span>
        <span style="display:flex; gap:4px;"><button>‹</button><button>›</button></span>
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
      <?php foreach ($announcements as $a): ?>
        <div class="announcement-item">
          <span class="ann-icon <?= htmlspecialchars($a['type']) ?>">
            <?= $a['type'] === 'alert' ? '⚠️' : ($a['type'] === 'reminder' ? '📄' : '📣') ?>
          </span>
          <div>
            <div class="ann-title"><?= htmlspecialchars($a['title']) ?></div>
            <div class="ann-date"><?= htmlspecialchars($a['created_at']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </aside>

</div>

<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="js/supabase-client.js"></script>
<script src="js/main.js"></script>
</body>
</html>
