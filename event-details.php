<?php
require_once __DIR__ . '/config/supabase.php';

$user = require_login(); // redirects to login.php if not authenticated

$eventId = $_GET['id'] ?? null;

// ---- Handle "Register Now!" (PRG pattern: redirect after POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register' && $eventId) {
    $result = register_for_event($eventId);
    $flashKey = $result['ok'] ? 'registered' : 'register_error';
    header('Location: event-details.php?id=' . urlencode($eventId) . '&' . $flashKey . '=1');
    exit;
}

$event = null;
if ($eventId) {
    $rows = supabase_request('events', 'select=*,categories(name),organizations(name,logo_url)&id=eq.' . urlencode($eventId) . '&limit=1');
    if (!empty($rows)) {
        $event = $rows[0];
    }
}

// Falls back to the same placeholder content shown in the design if no id
// is given, the id doesn't match a row, or Supabase is unreachable — the
// page still looks right on its own (mirrors supabase_mock_data()'s intent).
if (!$event) {
    $event = [
        'id' => $eventId,
        'title' => 'JPSSITE PACE LEVEL UP v.6.2',
        'description' => "Level up, innovators! The grid is officially open, and we're ready to break the simulation. Whether you're a coder, a creator, or a tech enthusiast, it's time to rise above the static and reach new heights of excellence.",
        'start_date' => 'TBA',
        'venue' => 'AVR, CITE Building',
        'registration_limit' => null,
        'categories' => ['name' => 'Seminar'],
        'organizations' => ['name' => 'JPSSITE Organization', 'logo_url' => 'assets/org-jpssite.jpg'],
    ];
}

$categoryName = $event['categories']['name'] ?? 'Event';
$orgName = $event['organizations']['name'] ?? 'Organizer';
$orgLogo = $event['organizations']['logo_url'] ?? 'assets/org-jpssite.jpg';
$seatsLabel = !empty($event['registration_limit']) ? $event['registration_limit'] . ' slots available' : 'Free / Slots available';

$alreadyRegistered = $eventId ? is_registered($eventId) : false;
$registeredCount = $eventId ? get_registration_count($eventId) : 0;

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) { $out .= substr($p, 0, 1); }
    return strtoupper($out) ?: 'U';
}

$heroImages = ['assets/event-hero-1.jpg', 'assets/event-hero-2.jpg', 'assets/event-hero-3.jpg'];

// Placeholder — event_programs / event_schedule_items aren't in the current
// schema yet (see README note further down). Swap these for real queries
// once those tables exist; the markup below is already shaped for it.
$programs = [
    ['badge' => 'Seminar', 'image' => 'assets/program-seminar.jpg', 'title' => 'JPSSITE PACE LEVEL UP v.6.2', 'subtitle' => 'Innovating the future through generative AI and low-code solutions', 'time' => '0:00 AM - 0:00 AM'],
    ['badge' => 'Contest', 'image' => 'assets/program-contest.jpg', 'title' => 'Tawag ng JPSSITE', 'subtitle' => 'The Talent Showdown', 'time' => '0:00 PM - 0:00 PM'],
    ['badge' => 'Social', 'image' => 'assets/program-social.jpg', 'title' => 'Live Band: Rise Through Rhythm', 'subtitle' => 'Close out the day with music', 'time' => '0:00 PM - 0:00 PM'],
];

$morningProgram = ['Registration', 'Augustian Prayer', 'National Anthem', 'Orientation for Emergency Plan', 'Introduction', 'Seminar Speaker', 'Q&A', 'Awarding of Certificate', 'Ice Breaker', 'Reminders', 'Lunch Break'];
$afternoonProgram = ['Registration', 'Tawag ng JPSSITE', 'Game / Activities', 'Break', 'Game / Activities', 'Live Band', 'Awarding Ceremony', 'Closing remark', 'Closing Prayer'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Details — Campus Event Hub</title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="dashboard-shell">

  <!-- Sidebar (same as dashboard.php, "Event" highlighted) -->
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
      <a href="dashboard.php"><span class="icon">🗂️</span> Dashboard</a>
      <a href="#event" class="active"><span class="icon">🎟️</span> Event <span style="margin-left:auto;">⌄</span></a>
      <a href="event-details.php<?= $eventId ? '?id=' . urlencode($eventId) : '' ?>" class="side-subitem">Event Details</a>
    </nav>
  </aside>

  <!-- Top bar -->
  <header class="dash-topbar">
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <a href="dashboard.php">Dashboard</a>
      <span class="crumb-sep">›</span>
      <a href="dashboard.php#events">Events</a>
      <span class="crumb-sep">›</span>
      <span class="crumb-current">Event Details</span>
    </nav>
    <div class="topbar-right">
      <div class="notif-wrap">
        <button class="bell" id="notifBtn" type="button" aria-label="Notifications">
          🔔<span class="dot" id="notifDot" hidden>0</span>
        </button>
        <div class="notif-dropdown" id="notifDropdown" hidden>
          <div class="notif-dropdown-head">Notifications</div>
          <div class="notif-list" id="notifList"><div class="notif-empty">No notifications yet.</div></div>
        </div>
      </div>
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
    <a href="dashboard.php" class="back-link">‹ Back to All Events</a>

    <?php if (isset($_GET['registered'])): ?>
      <div class="auth-success" style="margin-bottom:16px;">You're registered! We'll notify you with any updates.</div>
    <?php elseif (isset($_GET['register_error'])): ?>
      <div class="auth-alert" style="margin-bottom:16px;">Something went wrong registering you. Please try again.</div>
    <?php endif; ?>

    <!-- Event hero card -->
    <section class="event-hero">
      <div class="hero-gallery">
        <div class="hero-gallery-img" id="heroImage" style="background-image:url('<?= htmlspecialchars($heroImages[0]) ?>');"></div>
        <div class="hero-dots">
          <?php foreach ($heroImages as $i => $img): ?>
            <button type="button" class="hero-dot <?= $i === 0 ? 'active' : '' ?>" data-src="<?= htmlspecialchars($img) ?>" aria-label="Show image <?= $i + 1 ?>"></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="hero-info">
        <span class="event-tag tag-seminar" style="position:static; display:inline-block;"><?= htmlspecialchars($categoryName) ?></span>
        <h1 class="hero-title"><?= htmlspecialchars($event['title']) ?></h1>
        <p class="hero-desc"><?= htmlspecialchars($event['description']) ?></p>

        <div class="hero-meta">
          <div class="meta-row">👥 <?= htmlspecialchars($seatsLabel) ?> <?= $registeredCount > 0 ? '· ' . $registeredCount . ' registered' : '' ?></div>
          <div class="meta-row">📅 <?= htmlspecialchars($event['start_date'] ?? 'TBA') ?></div>
          <?php if (!empty($event['venue'])): ?>
          <div class="meta-row">📍 <?= htmlspecialchars($event['venue']) ?></div>
          <?php endif; ?>
        </div>

        <div class="hero-organizer">
          <span class="organizer-label">Organize by</span>
          <img src="<?= htmlspecialchars($orgLogo) ?>" alt="" class="organizer-logo">
          <span class="organizer-name"><?= htmlspecialchars($orgName) ?></span>
        </div>

        <?php if ($alreadyRegistered): ?>
          <button class="btn btn-primary" disabled>Registered ✓</button>
        <?php else: ?>
          <form method="POST" action="event-details.php?id=<?= urlencode((string)$eventId) ?>">
            <input type="hidden" name="action" value="register">
            <button type="submit" class="btn btn-primary">Register Now!</button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <!-- Programs -->
    <div class="section-head" style="margin-top:28px;">
      <h2>Programs</h2>
    </div>
    <div class="programs-grid">
      <?php foreach ($programs as $p): ?>
      <article class="event-card">
        <div class="event-thumb" style="background-image:url('<?= htmlspecialchars($p['image']) ?>');">
          <span class="event-tag tag-seminar"><?= htmlspecialchars($p['badge']) ?></span>
        </div>
        <div class="event-body">
          <h3 class="event-name"><?= htmlspecialchars($p['title']) ?></h3>
          <div class="event-meta"><?= htmlspecialchars($p['subtitle']) ?></div>
          <div class="event-meta" style="color:var(--blue-accent); font-weight:600;"><?= htmlspecialchars($p['time']) ?></div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </main>

  <!-- Right rail: schedule -->
  <aside class="right-rail">
    <div class="rail-card schedule-card">
      <div class="schedule-header">Morning Program</div>
      <ol class="schedule-list">
        <?php foreach ($morningProgram as $item): ?>
        <li><span class="schedule-dot"></span><span class="schedule-label"><?= htmlspecialchars($item) ?></span><span class="schedule-time">0:00 - 0:00 AM</span></li>
        <?php endforeach; ?>
      </ol>

      <div class="schedule-header">Afternoon Program</div>
      <ol class="schedule-list">
        <?php foreach ($afternoonProgram as $item): ?>
        <li><span class="schedule-dot"></span><span class="schedule-label"><?= htmlspecialchars($item) ?></span><span class="schedule-time">0:00 - 0:00 PM</span></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </aside>

</div>

<script>window.CURRENT_USER_ID = <?= json_encode($user['id']) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="js/supabase-client.js"></script>
<script src="js/event-details.js"></script>
</body>
</html>
