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

// Everything below comes from the Thesis Supabase project — no local sample data.
$event = null;
if ($eventId) {
    $rows = supabase_request('events', EVENT_CARD_SELECT . '&id=eq.' . urlencode($eventId) . '&limit=1');
    $event = $rows[0] ?? null;
} else {
    // Opened without an id (e.g. the sidebar's Calendar link): show the
    // event happening today, or else the next upcoming one.
    $event = get_upcoming_events(1)[0] ?? null;
    $eventId = $event['id'] ?? null;
}

// Placeholder images (numbered IMAGE SLOTs — see assets/css/event-details.css).
// An event's own poster / organization logo from Supabase is used when set.
$img = 'assets/images/event-details/';
$posterUrl = !empty($event['poster_url']) ? $event['poster_url'] : $img . 'event-poster.svg';
$orgLogo = !empty($event['organizations']['logo_url']) ? $event['organizations']['logo_url'] : $img . 'org-logo.svg';

$categoryName = $event['categories']['name'] ?? 'Event';
$orgName = $event['organizations']['name'] ?? 'Organizer';
$alreadyRegistered = $event ? is_registered($eventId) : false;

// "Sep 20, 2026 | 1:00 PM - 3:00 PM" (time part left off when not set).
function event_when(array $ev): string {
    $time = format_event_time($ev['start_time'] ?? null);
    if ($time !== '' && !empty($ev['end_time'])) {
        $time .= ' - ' . format_event_time($ev['end_time']);
    }
    return format_event_date($ev['start_date'] ?? null) . ($time !== '' ? ' | ' . $time : '');
}

// Schedule: this event's segments from event_programs, in sort_order.
$schedule = $event ? get_event_programs($eventId) : [];

// "Programs" cards: other published events, soonest first, so anything
// happening today always shows up first.
$programCards = get_upcoming_events(3, $eventId);

// Notifications for the bell, fetched with the user's session so the
// notifications RLS policy (user_id = auth.uid()) lets their rows through.
$notifications = supabase_request('notifications', 'select=id,title,message,status,created_at&order=created_at.desc&limit=10', 'GET', null, false, $_SESSION['access_token'] ?? null);
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
  <?php include __DIR__ . '/../includes/nav.php'; ?>

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

    <?php if (!$event): ?>
    <section class="ed-hero ed-not-found">
      <h1 class="ed-title">Event not found</h1>
      <p>This event doesn't exist or isn't published yet. <a href="enduser/dashboard.php">Browse all events</a>.</p>
    </section>
    <?php else: ?>
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
            <?= htmlspecialchars(event_when($event)) ?>
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
    <?php endif; ?>

    <!-- Programs: other published events, happening-today first -->
    <h2 class="ed-section-title">Programs</h2>
    <div class="ed-programs">
      <?php if (empty($programCards)): ?>
        <p class="ed-empty">No other upcoming events yet.</p>
      <?php endif; ?>
      <?php foreach ($programCards as $i => $p):
        $desc = (string) ($p['description'] ?? '');
      ?>
      <a class="ed-program-card" href="event-details.php?id=<?= urlencode($p['id']) ?>">
        <!-- IMAGE SLOTS 3-5: Program card image (used when the event has no poster_url) | file: assets/images/event-details/program-<?= $i + 1 ?>.svg | 225×200 -->
        <img src="<?= htmlspecialchars(!empty($p['poster_url']) ? $p['poster_url'] : $img . 'program-' . ($i + 1) . '.svg') ?>" alt="" class="ed-program-img">
        <div class="ed-program-body">
          <span class="ed-program-badge"><?= htmlspecialchars($p['categories']['name'] ?? 'Event') ?></span>
          <h3 class="ed-program-title"><?= htmlspecialchars($p['title']) ?></h3>
          <p class="ed-program-sub"><?= htmlspecialchars(mb_strimwidth($desc, 0, 90, '…')) ?></p>
          <p class="ed-program-time"><?= htmlspecialchars(event_when($p)) ?></p>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </main>

  <!-- Right rail: schedule -->
  <aside class="right-rail ed-rail">
    <div class="ed-schedule">
      <h2 class="ed-schedule-title">Schedule</h2>

      <?php if (empty($schedule)): ?>
        <p class="ed-empty">No schedule posted yet.</p>
      <?php else: ?>
      <!-- event_programs rows in sort_order; speaker shown on the right -->
      <div class="ed-schedule-head">Event Program</div>
      <ol class="ed-timeline">
        <?php foreach ($schedule as $item): ?>
        <li title="<?= htmlspecialchars($item['description'] ?? '') ?>"><span class="ed-tl-label"><?= htmlspecialchars($item['title']) ?></span><span class="ed-tl-dot"></span><span class="ed-tl-time"><?= htmlspecialchars($item['speaker'] ?? '') ?></span></li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
  </aside>

</div>

<script>
  window.CURRENT_USER_ID = <?= json_encode($user['id']) ?>;
  window.USER_NOTIFICATIONS = <?= json_encode($notifications) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2"></script>
<script src="assets/js/supabase-client.js"></script>
<script src="assets/js/event-details.js"></script>
<script src="assets/js/sidebar.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
