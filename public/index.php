<?php
require_once __DIR__ . '/../config/supabase.php';

$events = get_upcoming_events(8);          // today's events first, then the next dates
$stats = get_site_stats();                 // refreshed every 30s by main.js wireLiveStats()
$todayEvents = get_events_today();
$organizations = get_organizations();

$activeNav = 'home';
$topbarSearch = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campus Event Hub — La Consolacion University Philippines</title>
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="neu">

<div class="dashboard-shell no-rail">

<?php include __DIR__ . '/../includes/nav.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<main class="dash-main public-main">
<section class="hero">
  <div class="hero-bg" style="background-image:url('assets/images/lcup-campus.jpg');"></div>
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <p class="hero-eyebrow">LA CONSOLACION UNIVERSITY PHILIPPINES</p>
    <h1 class="hero-title">Create, Innovate,<br>Transform &amp; Empower</h1>
    <p class="hero-desc">Campus Event Hub is your one-stop platform for discovering campus events, registering, and getting involved.</p>
    <a href="events.php" class="btn btn-primary">EXPLORE EVENTS</a>
  </div>
  <div class="hero-badge">
    <span class="circle"><?= icon('megaphone', 22) ?></span>
    STAY UPDATED
  </div>
</section>

<div class="container">
  <?= render_flashes() ?>

  <section class="feature-strip">
    <div class="feature-item">
      <span class="feature-icon"><?= icon('calendar', 22) ?></span>
      <div>
        <div class="feature-title">All Campus Events</div>
        <div class="feature-sub">Find academic, innovative and more.</div>
      </div>
    </div>
    <div class="feature-item">
      <span class="feature-icon"><?= icon('people', 22) ?></span>
      <div>
        <div class="feature-title">Easy Participation</div>
        <div class="feature-sub">Register in just a few clicks.</div>
      </div>
    </div>
    <div class="feature-item">
      <span class="feature-icon"><?= icon('bell', 22) ?></span>
      <div>
        <div class="feature-title">Stay Updated</div>
        <div class="feature-sub">Get notified about new and changed events.</div>
      </div>
    </div>
    <div class="feature-item">
      <span class="feature-icon"><?= icon('certificate', 22) ?></span>
      <div>
        <div class="feature-title">Earn Certificates</div>
        <div class="feature-sub">Get recognized for your participation.</div>
      </div>
    </div>
    <div class="stats-block" data-stats-url="api/stats.php">
      <?php foreach ($stats as $s): ?>
        <div class="stat">
          <div class="stat-value" data-stat="<?= htmlspecialchars($s['key']) ?>"><?= htmlspecialchars($s['value']) ?></div>
          <div class="stat-label"><?= htmlspecialchars($s['label']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="section-head section-head-lg">
    <h2>Upcoming Events</h2>
    <a href="events.php" class="view-all">View All</a>
  </div>
  <section class="events-section">
    <div class="events-grid" id="events-grid">
      <?php if (empty($events)): ?>
        <p class="events-empty">No upcoming events yet. Check back soon!</p>
      <?php endif; ?>
      <?php foreach ($events as $ev): ?>
        <?php include __DIR__ . '/../includes/event-card.php'; ?>
      <?php endforeach; ?>
    </div>

    <aside class="upcoming-panel">
      <h3>Happening Today</h3>
      <?php if (empty($todayEvents)): ?>
        <p class="u-meta">No events happening today.</p>
      <?php endif; ?>
      <?php foreach ($todayEvents as $ev): ?>
        <a class="upcoming-item" href="event-details.php?id=<?= urlencode($ev['id']) ?>">
          <div class="upcoming-thumb" style="background-image:url('<?= css_url(event_poster_url($ev['poster_url'] ?? null)) ?>');"></div>
          <div class="upcoming-info">
            <div class="u-title"><?= htmlspecialchars($ev['title']) ?></div>
            <div class="u-meta"><?= htmlspecialchars(trim(format_event_time($ev['start_time']) . ' | ' . event_place($ev), ' |')) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </aside>
  </section>

  <section class="about-section" id="about">
    <div class="about-text">
      <p class="about-eyebrow">ABOUT US</p>
      <h2>One place for every campus event</h2>
      <p>Campus Event Hub brings the events of La Consolacion University Philippines' student organizations together in one place. Students can browse seminars, contests and activities, register in a few clicks, and get notified when something changes.</p>
      <p>Organizers publish their events, share the program schedule, keep track of who registered, and mark attendance — so recognition and certificates go to the people who were there.</p>
      <div class="about-actions">
        <a href="events.php" class="btn btn-primary">Browse events</a>
        <?php if (!current_user()): ?>
          <a href="auth/register.php" class="btn btn-outline">Create an account</a>
        <?php endif; ?>
      </div>
    </div>
    <ul class="about-points">
      <li><span><?= icon('search', 24) ?></span><div><strong>Discover</strong>Search events by keyword, organization, category or type.</div></li>
      <li><span><?= icon('register', 24) ?></span><div><strong>Register</strong>Save your seat before the deadline — capacity is tracked live.</div></li>
      <li><span><?= icon('bell', 24) ?></span><div><strong>Stay informed</strong>Get notified about new events, updates and cancellations.</div></li>
      <li><span><?= icon('organizations', 24) ?></span><div><strong>Meet the organizers</strong>See each organization's officers and upcoming events.</div></li>
    </ul>
  </section>

  <?php if ($organizations): ?>
  <section class="trusted-strip">
    <div class="trusted-logos">
      <?php foreach (array_slice($organizations, 0, 5) as $o): ?>
        <a href="organizations.php#org-<?= htmlspecialchars($o['id']) ?>" title="<?= htmlspecialchars($o['name']) ?>"><?= htmlspecialchars(initials($o['name'])) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="trusted-title">TRUSTED BY CAMPUS ORGANIZATIONS</div>
    <div class="trusted-copy">Campus Event Hub helps us connect, organize and engage more students in campus activities. <a href="organizations.php">Meet the organizations →</a></div>
  </section>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</main>

</div>

<script src="assets/js/sidebar.js"></script>
<script src="assets/js/main.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
