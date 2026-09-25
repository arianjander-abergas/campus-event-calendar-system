<?php
require_once __DIR__ . '/../config/supabase.php';

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
        'description' => "Level up, Innovators! The grid is officially open, and we're ready to break the simulation. Whether you're a code, a creator, or a tech enthusiast. It's time to rise above the static and reach new heights of excellence.",
        'start_date' => 'Month 00 0000',
        'venue' => 'AVR 1, CITE Building',
        'categories' => ['name' => 'Seminar'],
        'organizations' => ['name' => 'JPSSITE Organization', 'logo_url' => null],
    ];
}

// Placeholder images (numbered IMAGE SLOTs — see assets/css/event-details.css).
// An event's own poster / organization logo from Supabase is used when set.
$img = 'assets/images/event-details/';
$posterUrl = !empty($event['poster_url']) ? $event['poster_url'] : $img . 'event-poster.svg';
$orgLogo = !empty($event['organizations']['logo_url']) ? $event['organizations']['logo_url'] : $img . 'org-logo.svg';

$categoryName = $event['categories']['name'] ?? 'Event';
$orgName = $event['organizations']['name'] ?? 'Organizer';
$alreadyRegistered = $eventId ? is_registered($eventId) : false;

// Placeholder — event_programs / event_schedule_items aren't in the current
// schema yet. Swap these for real queries once those tables exist; the
// markup below is already shaped for it.
$programs = [
    ['badge' => 'Seminar', 'image' => $img . 'program-1.svg', 'title' => 'JPSSITE PACE LEVEL UP v.6.2', 'subtitle' => 'Innovating the future through generative AI and low-code solution', 'time' => 'Time 0:00 AM - 00:00 AM'],
    ['badge' => 'Contest', 'image' => $img . 'program-2.svg', 'title' => 'JPSSITE PACE LEVEL UP v.6.2', 'subtitle' => 'Tawag ng JPSSITE : The Talent Showdown', 'time' => 'Time 00:00 AM - 00:00 PM'],
    ['badge' => 'Special', 'image' => $img . 'program-3.svg', 'title' => 'JPSSITE PACE LEVEL UP v.6.2', 'subtitle' => 'Live Band : Rise Through Rhythm', 'time' => 'Time 00:00 AM - 00:00 PM'],
];

$morningProgram = ['Registration', 'Augustian Prayer', 'National Anthem', 'Orientation for Emergency Plan', 'Introduction', 'Seminar Speaker', 'Q & A', 'Awarding of Certificate', 'Ice Breaker', 'Reminders', 'Lunch Break'];
$afternoonProgram = ['Registration', 'Tawag ng JPSSITE', 'Game / Activities', 'Break', 'Game / Activities', 'Live Band', 'Awarding Ceremony', 'Closing remark', 'Closing Prayer'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Event Details — Campus Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/event-details.css">
</head>
<body class="event-details-page">

<div class="dashboard-shell sidebar-pinned">

  <!-- Sidebar (same as dashboard.php, "Event" highlighted) -->
  <?php include __DIR__ . '/../../includes/nav.php'; ?>

  <!-- Top bar -->
  <header class="dash-topbar ed-topbar">
    <nav class="ed-breadcrumb" aria-label="Breadcrumb">
      <a href="enduser/dashboard.php">Dashboard</a>
      <span class="ed-crumb-sep">&gt;</span>
      <a href="enduser/dashboard.php">Events</a>
      <span class="ed-crumb-sep">&gt;</span>
      <span class="ed-crumb-current">Event Details</span>
    </nav>

    <div class="ed-topbar-right">
      <div class="ed-notif">
        <button class="ed-bell" id="notifBtn" type="button" aria-label="Notifications">
          <!-- IMAGE SLOT 9: Notification bell | file: assets/images/event-details/icon-bell.svg | 26×26 -->
          <img src="<?= $img ?>icon-bell.svg" alt="" width="26" height="26">
          <span class="ed-bell-count" id="notifDot" hidden>0</span>
        </button>
        <div class="ed-notif-dropdown" id="notifDropdown" hidden>
          <div class="ed-notif-head">Notifications</div>
          <div class="ed-notif-list" id="notifList"><div class="ed-notif-empty">No notifications yet.</div></div>
        </div>
      </div>

      <details class="ed-user-menu">
        <summary>
          <!-- IMAGE SLOT 10: User avatar | file: assets/images/event-details/avatar.svg | 50×50 -->
          <img src="<?= $img ?>avatar.svg" alt="" class="ed-avatar" width="50" height="50">
          <span class="ed-user-text">
            <span class="ed-user-name"><?= htmlspecialchars($user['full_name']) ?></span>
            <span class="ed-user-role"><?= htmlspecialchars(role_display($user['role'])) ?></span>
          </span>
          <!-- IMAGE SLOT 11: User menu arrow | file: assets/images/event-details/icon-chevron-down.svg | 18×18 -->
          <img src="<?= $img ?>icon-chevron-down.svg" alt="" class="ed-user-chevron" width="18" height="18">
        </summary>
        <div class="ed-user-dropdown">
          <a href="auth/logout.php">Log out</a>
        </div>
      </details>
    </div>
  </header>

  <!-- Main content -->
  <main class="dash-main ed-main">
    <a href="enduser/dashboard.php" class="ed-back">
      <!-- IMAGE SLOT 6: Back arrow | file: assets/images/event-details/icon-back.svg | 30×18 -->
      <img src="<?= $img ?>icon-back.svg" alt="" width="30" height="18">
      Back to All Events
    </a>

    <?php if (isset($_GET['registered'])): ?>
      <div class="ed-flash ed-flash-ok">You're registered! We'll notify you with any updates.</div>
    <?php elseif (isset($_GET['register_error'])): ?>
      <div class="ed-flash ed-flash-error">Something went wrong registering you. Please try again.</div>
    <?php endif; ?>

    <!-- Event hero card -->
    <section class="ed-hero">
      <!-- IMAGE SLOT 1: Event poster (used when the event has no poster_url) | file: assets/images/event-details/event-poster.svg | 340×345 -->
      <img src="<?= htmlspecialchars($posterUrl) ?>" alt="<?= htmlspecialchars($event['title']) ?> poster" class="ed-poster">

      <div class="ed-hero-info">
        <span class="ed-category"><?= htmlspecialchars($categoryName) ?></span>
        <h1 class="ed-title"><?= htmlspecialchars($event['title']) ?></h1>
        <p class="ed-desc"><?= htmlspecialchars($event['description'] ?? '') ?></p>

        <div class="ed-meta">
          <?php if (!empty($event['venue'])): ?>
          <div class="ed-meta-row">
            <!-- IMAGE SLOT 7: Location icon | file: assets/images/event-details/icon-location.svg | 12×12 -->
            <img src="<?= $img ?>icon-location.svg" alt="" width="12" height="12">
            <?= htmlspecialchars($event['venue']) ?>
          </div>
          <?php endif; ?>
          <div class="ed-meta-row">
            <!-- IMAGE SLOT 8: Calendar icon | file: assets/images/event-details/icon-calendar.svg | 12×12 -->
            <img src="<?= $img ?>icon-calendar.svg" alt="" width="12" height="12">
            <?= htmlspecialchars($event['start_date'] ?? 'TBA') ?>
          </div>
        </div>

        <div class="ed-organizer">
          <!-- IMAGE SLOT 2: Organization logo (used when the org has no logo_url) | file: assets/images/event-details/org-logo.svg | 72×72 -->
          <img src="<?= htmlspecialchars($orgLogo) ?>" alt="" class="ed-org-logo">
          <div class="ed-org-text">
            <span>Organize by</span>
            <span><?= htmlspecialchars($orgName) ?></span>
          </div>
        </div>

        <div class="ed-register">
          <?php if ($alreadyRegistered): ?>
            <button class="ed-btn" disabled>Registered ✓</button>
          <?php else: ?>
            <form method="POST" action="event-details.php?id=<?= urlencode((string)$eventId) ?>">
              <input type="hidden" name="action" value="register">
              <button type="submit" class="ed-btn">Register Now!</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- Programs -->
    <h2 class="ed-section-title">Programs</h2>
    <div class="ed-programs">
      <?php foreach ($programs as $i => $p): ?>
      <article class="ed-program-card">
        <!-- IMAGE SLOT <?= $i + 3 ?>: Program <?= $i + 1 ?> image | file: <?= htmlspecialchars($p['image']) ?> | 225×200 -->
        <img src="<?= htmlspecialchars($p['image']) ?>" alt="" class="ed-program-img">
        <div class="ed-program-body">
          <span class="ed-program-badge"><?= htmlspecialchars($p['badge']) ?></span>
          <h3 class="ed-program-title"><?= htmlspecialchars($p['title']) ?></h3>
          <p class="ed-program-sub"><?= htmlspecialchars($p['subtitle']) ?></p>
          <p class="ed-program-time"><?= htmlspecialchars($p['time']) ?></p>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </main>

  <!-- Right rail: schedule -->
  <aside class="right-rail ed-rail">
    <div class="ed-schedule">
      <h2 class="ed-schedule-title">Schedule</h2>

      <div class="ed-schedule-head">Morning Program</div>
      <ol class="ed-timeline">
        <?php foreach ($morningProgram as $item): ?>
        <li><span class="ed-tl-label"><?= htmlspecialchars($item) ?></span><span class="ed-tl-dot"></span><span class="ed-tl-time">0:00 - 0:00 AM</span></li>
        <?php endforeach; ?>
      </ol>

      <div class="ed-schedule-head">Afternoon Program</div>
      <ol class="ed-timeline">
        <?php foreach ($afternoonProgram as $item): ?>
        <li><span class="ed-tl-label"><?= htmlspecialchars($item) ?></span><span class="ed-tl-dot"></span><span class="ed-tl-time">0:00 - 0:00 PM</span></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </aside>

</div>

<script>window.CURRENT_USER_ID = <?= json_encode($user['id']) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="assets/js/supabase-client.js"></script>
<script src="assets/js/event-details.js"></script>
<script src="assets/js/sidebar.js"></script>
</body>
</html>
