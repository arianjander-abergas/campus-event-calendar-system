<?php
require_once __DIR__ . '/../config/supabase.php';

$events = supabase_request('events');
// Live counters (Events, Organizations, Active Users, Registrations) — refreshed
// every 30s in the browser by main.js wireLiveStats().
$stats = get_site_stats();
$todayEvents = get_events_today();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campus Event Hub — La Consolacion University Philippines</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<?php include __DIR__ . '/../includes/header.php'; ?>

<section class="hero">
  <div class="hero-bg" style="background-image:url('assets/images/campus-hero.jpg');"></div>
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <p class="hero-eyebrow">LA CONSOLACION UNIVERSITY PHILIPPINES</p>
    <h1 class="hero-title">Create, Innovate,<br>Transform &amp; Empower</h1>
    <p class="hero-desc">Campus Event Hub is your one-stop platform for discovering campus events, registering, and getting involved.</p>
    <button class="btn btn-primary">EXPLORE EVENTS</button>
  </div>
  <div class="hero-badge">
    <span class="circle">📣</span>
    STAY UPDATED
  </div>
</section>

<div class="container">

  <section class="feature-strip">
    <div class="feature-item">
      <span class="feature-icon">📅</span>
      <div>
        <div class="feature-title">All Campus Event</div>
        <div class="feature-sub">Find academic, innovative and more.</div>
      </div>
    </div>
    <div class="feature-item">
      <span class="feature-icon">👥</span>
      <div>
        <div class="feature-title">Easy Participation</div>
        <div class="feature-sub">Register in just a few clicks.</div>
      </div>
    </div>
    <div class="feature-item">
      <span class="feature-icon">🔔</span>
      <div>
        <div class="feature-title">Stay Updated</div>
        <div class="feature-sub">Get real-time updates and reminders.</div>
      </div>
    </div>
    <div class="feature-item">
      <span class="feature-icon">🎓</span>
      <div>
        <div class="feature-title">Earn Certificates</div>
        <div class="feature-sub">Get recognized for your participation.</div>
      </div>
    </div>
    <div class="stats-block">
      <?php foreach ($stats as $s): ?>
        <div class="stat">
          <div class="stat-value" data-stat="<?= htmlspecialchars($s['key']) ?>"><?= htmlspecialchars($s['value']) ?></div>
          <div class="stat-label"><?= htmlspecialchars($s['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="events-section">
    <div class="events-grid" id="events-grid">
      <?php if (empty($events)): ?>
        <p class="events-empty">No events yet. Check back soon!</p>
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

    <aside class="upcoming-panel">
      <h3>Upcoming Today</h3>
      <?php if (empty($todayEvents)): ?>
        <p class="u-meta">No events happening today.</p>
      <?php endif; ?>
      <?php foreach ($todayEvents as $ev): ?>
        <a class="upcoming-item" href="event-details.php?id=<?= urlencode($ev['id']) ?>">
          <div class="upcoming-thumb" style="background-image:url('<?= htmlspecialchars($ev['poster_url'] ?? '') ?>');"></div>
          <div class="upcoming-info">
            <div class="u-title"><?= htmlspecialchars($ev['title']) ?></div>
            <div class="u-meta"><?= htmlspecialchars(trim(format_event_time($ev['start_time']) . ' | ' . ($ev['venue'] ?: 'Online'), ' |')) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </aside>
  </section>

  <section class="trusted-strip">
    <div class="trusted-logos">
      <span>⛨</span><span>⛨</span><span>⛨</span><span>⛨</span>
    </div>
    <div class="trusted-title">TRUSTED BY CAMPUS ORGANIZATIONS</div>
    <div class="trusted-copy">Campus Event Hub helps us connect, organize and engage more students in campus activities.</div>
  </section>

  <div class="site-footer-space"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="assets/js/supabase-client.js"></script>
<script src="assets/js/main.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
