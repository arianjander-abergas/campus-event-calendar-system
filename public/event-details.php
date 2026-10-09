<?php
require_once __DIR__ . '/../config/supabase.php';

$user = require_login(); // redirects to login.php if not authenticated

$eventId = get_param('id');
$activeNav = $eventId === '' ? 'event' : 'events'; // sidebar: "Next Event" vs "All Events"

// ---- Register / cancel (redirect after POST so a refresh doesn't resubmit) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_uuid($eventId)) {
    $action = post_param('action');
    if ($action === 'register') {
        $result = register_for_event($eventId);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    } elseif ($action === 'cancel_registration') {
        $result = cancel_registration($eventId);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    }
    header('Location: event-details.php?id=' . urlencode($eventId));
    exit;
}

// Everything below comes from the Thesis Supabase project.
if ($eventId !== '') {
    $event = get_event($eventId);
} else {
    // Opened without an id (sidebar "Next Event"): the event happening
    // today, or else the next upcoming one.
    $event = get_upcoming_events(1)[0] ?? null;
    $eventId = $event['id'] ?? '';
}

// Placeholder images (numbered IMAGE SLOTs — see assets/css/event-details.css).
// An event's own poster / organization logo from Supabase is used when set.
$img = 'assets/images/event-details/';
$posterUrl = asset_url($event['poster_url'] ?? null, $img . 'event-poster.svg');
$orgLogo = asset_url($event['organizations']['logo_url'] ?? null, $img . 'org-logo.svg');
$categoryName = $event['categories']['name'] ?? EVENT_TYPES[$event['event_type'] ?? 'other'] ?? 'Event';
$orgName = $event['organizations']['name'] ?? 'Organizer';

$going = $event ? get_registration_count($eventId) : 0;
$myRegistration = $event ? get_my_registration($eventId) : null;
$reg = $event ? registration_state($event, $myRegistration, $going) : null;

// Attendees evaluate the event once (one standard form for every event).
$attended = ($myRegistration['status'] ?? '') === 'attended';
$evaluated = $attended && has_evaluated($eventId);

// Schedule: this event's segments from event_programs, in sort_order.
$schedule = $event ? get_event_programs($eventId) : [];

// "Programs" cards: other published events, soonest first, so anything
// happening today always shows up first.
$programCards = get_upcoming_events(3, $eventId ?: null);

$notifications = get_my_notifications();
$unreadCount = unread_count($notifications);

// Admins, and moderators of the event's organization, get the management links.
$canManage = $event && can_manage_event($event, $user);
$openTo = $event ? year_levels_label($event['year_levels'] ?? []) : '';

// Banner for events that aren't simply "published and upcoming".
$banner = null;
if ($event) {
    if ($event['status'] === 'cancelled') $banner = ['error', 'This event was cancelled.'];
    elseif ($event['status'] === 'draft') $banner = ['info', "Draft — only admins and its organization's moderators can see this event until it is published."];
    elseif ($event['status'] === 'completed') $banner = ['info', 'Completed — this event has ended.'];
    elseif (($event['end_date'] ?? '') < date('Y-m-d')) $banner = ['info', 'This event has ended.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($event['title'] ?? 'Event Details') ?> — Campus Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/event-details.css?v=<?= filemtime(__DIR__ . '/assets/css/event-details.css') ?>">
</head>
<body class="event-details-page neu">

<div class="dashboard-shell">

  <?php include __DIR__ . '/../includes/nav.php'; ?>

  <!-- Top bar -->
  <header class="dash-topbar ed-topbar">
    <nav class="ed-breadcrumb" aria-label="Breadcrumb">
      <a href="enduser/dashboard.php">Dashboard</a>
      <span class="ed-crumb-sep">&gt;</span>
      <a href="events.php">Events</a>
      <span class="ed-crumb-sep">&gt;</span>
      <span class="ed-crumb-current">Event Details</span>
    </nav>

    <div class="ed-topbar-right">
      <div class="ed-notif" data-notifications>
        <button class="ed-bell" type="button" data-notif-btn aria-label="Notifications" aria-expanded="false">
          <?= icon('bell', 20) ?>
          <span class="ed-bell-count" data-notif-count<?= $unreadCount ? '' : ' hidden' ?>><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
        </button>
        <div class="ed-notif-dropdown" data-notif-dropdown hidden>
          <div class="ed-notif-head">Notifications</div>
          <div class="ed-notif-list" data-notif-list><div class="ed-notif-empty">No notifications yet.</div></div>
        </div>
      </div>

      <details class="ed-user-menu">
        <summary>
          <span class="avatar ed-avatar"><?= htmlspecialchars(initials($user['full_name'] ?? '')) ?></span>
          <span class="ed-user-text">
            <span class="ed-user-name"><?= htmlspecialchars($user['full_name']) ?></span>
            <span class="ed-user-role"><?= htmlspecialchars(role_display($user['role'], $user['account_type'] ?? null)) ?></span>
          </span>
          <?= icon('chevron-down', 12, 'ed-user-chevron') ?>
        </summary>
        <div class="ed-user-dropdown">
          <a href="enduser/profile.php">My Profile</a>
          <a href="events.php?mine=1">My Events</a>
          <a href="auth/logout.php">Log out</a>
        </div>
      </details>
    </div>
  </header>

  <!-- Main content -->
  <main class="dash-main ed-main">
    <a href="events.php" class="ed-back">
      <?= icon('arrow-left', 18) ?>
      Back to All Events
    </a>

    <?php foreach (take_flashes() as $f): ?>
      <div class="ed-flash <?= $f['type'] === 'success' ? 'ed-flash-ok' : ($f['type'] === 'error' ? 'ed-flash-error' : 'ed-flash-info') ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars($f['message']) ?></div>
    <?php endforeach; ?>

    <?php if (!$event): ?>
    <section class="ed-hero ed-not-found">
      <h1 class="ed-title"><?= $eventId === '' ? 'No upcoming events' : 'Event not found' ?></h1>
      <p><?= $eventId === '' ? 'There are no upcoming events right now.' : "This event doesn't exist or isn't published yet." ?> <a href="events.php">Browse all events</a>.</p>
    </section>
    <?php else: ?>
      <?php if ($banner): ?>
        <div class="ed-flash <?= $banner[0] === 'error' ? 'ed-flash-error' : 'ed-flash-info' ?>"><?= htmlspecialchars($banner[1]) ?></div>
      <?php endif; ?>

    <!-- Event hero card -->
    <section class="ed-hero">
      <!-- IMAGE SLOT 1: Event poster (used when the event has no poster_url) | file: assets/images/event-details/event-poster.svg | 340×345 -->
      <img src="<?= htmlspecialchars($posterUrl) ?>" alt="<?= htmlspecialchars($event['title']) ?> poster" class="ed-poster">

      <div class="ed-hero-info">
        <span class="ed-category"><?= htmlspecialchars($categoryName) ?></span>
        <h1 class="ed-title"><?= htmlspecialchars($event['title']) ?></h1>
        <p class="ed-desc"><?= nl2br(htmlspecialchars($event['description'] ?? '')) ?></p>

        <div class="ed-meta">
          <div class="ed-meta-row">
            <?= icon('location', 12) ?>
            <?= htmlspecialchars(event_place($event)) ?><?= !empty($event['address']) ? ' — ' . htmlspecialchars($event['address']) : '' ?>
          </div>
          <div class="ed-meta-row">
            <?= icon('calendar', 12) ?>
            <?= htmlspecialchars(event_when($event)) ?>
          </div>
          <div class="ed-meta-row"><?= icon(($event['venue_type'] ?? '') === 'online' ? 'online' : 'campus', 12) ?> <?= htmlspecialchars(label_of(VENUE_TYPES, $event['venue_type'] ?? null, 'On-campus')) ?></div>
          <div class="ed-meta-row"><?= icon('audience', 12) ?> For <?= htmlspecialchars(label_of(EVENT_AUDIENCES, $event['audience'] ?? null, 'Students')) ?><?= $openTo !== '' && ($event['audience'] ?? '') !== 'faculty' ? ' · Open to ' . htmlspecialchars($openTo) . ' students' : '' ?></div>
          <?php if (!empty($event['online_event_link']) && preg_match('~^https?://~i', $event['online_event_link'])): ?>
            <div class="ed-meta-row"><?= icon('link', 12) ?> <a href="<?= htmlspecialchars($event['online_event_link']) ?>" target="_blank" rel="noopener">Join online</a></div>
          <?php endif; ?>
          <div class="ed-meta-row"><?= icon('people', 12) ?> <?= $going ?> going<?= !empty($event['registration_limit']) ? ' · ' . (int) $event['registration_limit'] . ' slots' : '' ?></div>
          <?php if (!empty($event['contact_email']) || !empty($event['contact_number'])): ?>
            <div class="ed-meta-row"><?= icon('email', 12) ?> <?= htmlspecialchars(implode(' · ', array_filter([$event['contact_email'] ?? '', $event['contact_number'] ?? '']))) ?></div>
          <?php endif; ?>
        </div>

        <div class="ed-organizer">
          <!-- IMAGE SLOT 2: Organization logo (used when the org has no logo_url) | file: assets/images/event-details/org-logo.svg | 72×72 -->
          <img src="<?= htmlspecialchars($orgLogo) ?>" alt="" class="ed-org-logo">
          <div class="ed-org-text">
            <span>Organize by</span>
            <?php if (!empty($event['organizations']['id'])): ?>
              <a href="organizations.php#org-<?= htmlspecialchars($event['organizations']['id']) ?>"><?= htmlspecialchars($orgName) ?></a>
            <?php else: ?>
              <span><?= htmlspecialchars($orgName) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="ed-register">
          <?php if ($reg['note'] !== ''): ?>
            <p class="ed-reg-note"><?= htmlspecialchars($reg['note']) ?></p>
          <?php endif; ?>
          <?php if ($reg['can_register']): ?>
            <form method="POST" action="event-details.php?id=<?= urlencode($eventId) ?>">
              <input type="hidden" name="action" value="register">
              <button type="submit" class="ed-btn">Register Now!</button>
            </form>
          <?php elseif ($reg['state'] === 'registered' || $reg['state'] === 'attended'): ?>
            <div class="ed-reg-actions">
              <?php if ($attended && !$evaluated): ?>
                <a href="enduser/evaluate.php?event=<?= urlencode($eventId) ?>" class="ed-btn ed-btn-evaluate">Evaluate this event</a>
              <?php else: ?>
                <button class="ed-btn" disabled><?= $evaluated ? 'Completed ✓' : ($reg['state'] === 'attended' ? 'Attended ✓' : 'Registered ✓') ?></button>
              <?php endif; ?>
              <?php if ($reg['can_cancel']): ?>
                <form method="POST" action="event-details.php?id=<?= urlencode($eventId) ?>" onsubmit="return confirm('Cancel your registration for this event?');">
                  <input type="hidden" name="action" value="cancel_registration">
                  <button type="submit" class="ed-btn ed-btn-outline">Cancel registration</button>
                </form>
              <?php endif; ?>
            </div>
            <?php if ($attended): ?>
              <p class="ed-eval-note"><?= $evaluated ? 'Completed: you attended and your evaluation was submitted. Thank you for your feedback!' : 'Thanks for attending! Please tell us how the event went.' ?></p>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <div class="ed-admin-links">
              <a href="admin/event-organizer.php?edit=<?= urlencode($eventId) ?>">Edit event</a>
              <a href="admin/event-programs.php?event=<?= urlencode($eventId) ?>">Edit schedule</a>
              <a href="admin/event-registrations.php?event=<?= urlencode($eventId) ?>">Registrations</a>
              <a href="admin/attendance-print.php?event=<?= urlencode($eventId) ?>">Print attendance</a>
              <a href="admin/event-evaluations.php?event=<?= urlencode($eventId) ?>">Evaluation results</a>
            </div>
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
      <?php foreach ($programCards as $i => $p): ?>
      <a class="ed-program-card" href="event-details.php?id=<?= urlencode($p['id']) ?>">
        <!-- IMAGE SLOTS 3-5: Program card image (used when the event has no poster_url) | file: assets/images/event-details/program-<?= $i + 1 ?>.svg | 225×200 -->
        <img src="<?= htmlspecialchars(asset_url($p['poster_url'] ?? null, $img . 'program-' . ($i + 1) . '.svg')) ?>" alt="" class="ed-program-img">
        <div class="ed-program-body">
          <span class="ed-program-badge"><?= htmlspecialchars($p['categories']['name'] ?? 'Event') ?></span>
          <h3 class="ed-program-title"><?= htmlspecialchars($p['title']) ?></h3>
          <p class="ed-program-sub"><?= htmlspecialchars(excerpt($p['description'] ?? '', 90)) ?></p>
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
        <p class="ed-empty">No schedule posted yet.<?php if ($canManage && $event): ?> <a href="admin/event-programs.php?event=<?= urlencode($eventId) ?>">Add one</a>.<?php endif; ?></p>
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

<?= notifications_bootstrap($notifications) ?>
<script src="assets/js/sidebar.js"></script>
<?= presence_script_tag() ?>
</body>
</html>
