<?php
require_once __DIR__ . '/config/supabase.php';

$events = supabase_request('events');
$stats = supabase_request('stats');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campus Event Hub — La Consolacion University Philippines</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include __DIR__ . '/includes/header.php'; ?>

<section class="hero">
  <div class="hero-bg" style="background-image:url('assets/campus-hero.jpg');"></div>
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
          <div class="stat-value"><?= htmlspecialchars($s['value']) ?></div>
          <div class="stat-label"><?= htmlspecialchars($s['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="events-section">
    <div class="events-grid" id="events-grid">
      <?php foreach ($events as $ev):
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

    <aside class="upcoming-panel">
      <h3>Upcoming Today</h3>
      <div class="upcoming-item">
        <div class="upcoming-thumb" style="background-image:url('assets/event-liveband.jpg');"></div>
        <div class="upcoming-info">
          <div class="u-title">JPSSITE Live Band Upsite</div>
          <div class="u-meta">Month 00 0000 | AVR 1, CITE Building</div>
        </div>
      </div>
      <div class="upcoming-item">
        <div class="upcoming-thumb" style="background-image:url('assets/event-tawag.jpg');"></div>
        <div class="upcoming-info">
          <div class="u-title">Tawag ng JPSSite</div>
          <div class="u-meta">Month 00 0000 | AVR 1, CITE Building</div>
        </div>
      </div>
      <div class="upcoming-item">
        <div class="upcoming-thumb" style="background-image:url('assets/event-genz.jpg');"></div>
        <div class="upcoming-info">
          <div class="u-title">GEN Z Night 2026</div>
          <div class="u-meta">Month 00 0000 | AVR 1, CITE Building</div>
        </div>
      </div>
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
<script src="js/supabase-client.js"></script>
<script src="js/main.js"></script>
</body>
</html>