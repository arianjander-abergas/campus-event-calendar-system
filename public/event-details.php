<?php
/**
 * One event. Two ways to show it:
 *   event-details.php?id=…          the full page (no id: the next event)
 *   event-details.php?id=…&modal=1  just the details, for the pop-up that
 *                                   opens when an event is clicked
 *                                   (assets/js/event-modal.js)
 * Both use includes/event-detail.php, so they always show the same thing.
 */
require_once __DIR__ . '/../config/supabase.php';

$user = require_login(); // redirects to login.php if not authenticated

$eventId = get_param('id');
$edModal = get_param('modal') === '1' && is_uuid($eventId);
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
    // From the pop-up: back to the pop-up's details, with the message in them.
    header('Location: event-details.php?id=' . urlencode($eventId) . ($edModal ? '&modal=1' : ''));
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
$img = BASE_URL . '/assets/images/event-details/';
$posterUrl = asset_url($event['poster_url'] ?? null, $img . 'event-poster.svg');
$orgLogo = asset_url($event['organizations']['logo_url'] ?? null, $img . 'org-logo.svg');
$categoryName = event_category_label($event);
$orgName = $event['organizations']['name'] ?? 'Organizer';
$coOrganizers = get_event_partner_orgs([$eventId])[$eventId] ?? []; // multi-organization events

$going = $event ? get_registration_count($eventId) : 0;
$myRegistration = $event ? get_my_registration($eventId) : null;
$reg = $event ? registration_state($event, $myRegistration, $going) : null;

// Attendees evaluate the event once (one standard form for every event).
$attended = ($myRegistration['status'] ?? '') === 'attended';
$evaluated = $attended && has_evaluated($eventId);

// Program Flow: this event's parts from event_programs, in running order (sort_order).
$schedule = $event ? get_event_programs($eventId) : [];


$breadcrumbs = [['Dashboard', 'enduser/dashboard.php'], ['Events', 'events.php'], ['Event Details', null]];

// Admins, and moderators of the event's organization, get the management links.
$canManage = $event && can_manage_event($event, $user);
$openTo = $event ? year_levels_label($event['year_levels'] ?? []) : '';

// Banner for events that aren't simply "published and upcoming".
$banner = null;
if ($event) {
    if ($event['status'] === 'cancelled') $banner = ['error', 'This event was cancelled.'];
    elseif ($event['status'] === 'draft' && ($event['approval_status'] ?? null) === 'pending') $banner = ['info', "Waiting for a Super Admin's approval — it's published once approved. Until then only admins and its organization's moderators can see it."];
    elseif ($event['status'] === 'draft') $banner = ['info', "Draft — only Super Admins and its organization's Admins can see this event until it is published."];
    elseif ($event['status'] === 'completed') $banner = ['info', 'Completed — this event has ended.'];
    elseif (($event['end_date'] ?? '') < date('Y-m-d')) $banner = ['info', 'This event has ended.'];
}

// ---- The pop-up: only the details (no sidebar or top bar) ----
// X-Event-Modal tells event-modal.js this is the pop-up's content; anything
// else (the login page after a logout, an error page) makes it open the
// full page instead.
if ($edModal) {
    header('X-Event-Modal: 1');
    header('Cache-Control: no-store');
    if (!$event) {
        echo '<p class="ed-empty ed-modal-missing">This event doesn\'t exist or isn\'t published yet.</p>';
        exit;
    }
    ?>
    <?php foreach (take_flashes() as $f): /* Registered / cancelled: said right here */ ?>
      <div class="ed-flash <?= $f['type'] === 'success' ? 'ed-flash-ok' : ($f['type'] === 'error' ? 'ed-flash-error' : 'ed-flash-info') ?>" role="<?= $f['type'] === 'error' ? 'alert' : 'status' ?>"><?= htmlspecialchars($f['message']) ?></div>
    <?php endforeach; ?>
    <?php include __DIR__ . '/../includes/event-detail.php'; ?>
    <details class="ed-modal-flow"<?= $schedule ? '' : ' open' ?>>
      <summary>Program flow <span class="ed-modal-count"><?= $schedule ? count($schedule) . ' part' . (count($schedule) === 1 ? '' : 's') : 'none yet' ?></span></summary>
      <?php if ($schedule): ?>
        <?php include __DIR__ . '/../includes/event-program-flow.php'; ?>
      <?php else: ?>
        <p class="ed-empty">No program flow posted yet.<?php if ($canManage): ?> <a href="<?= htmlspecialchars(BASE_URL . '/admin/event-organizer.php?edit=' . urlencode($eventId)) ?>#sec-program">Add one</a>.<?php endif; ?></p>
      <?php endif; ?>
    </details>
    <p class="ed-modal-foot"><a href="<?= htmlspecialchars(BASE_URL . '/event-details.php?id=' . urlencode($eventId)) ?>">Open the full page ›</a></p>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= app_head_tags() ?>
<title><?= htmlspecialchars($event['title'] ?? 'Event Details') ?> — Campus Event Hub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
<link rel="stylesheet" href="assets/css/event-details.css?v=<?= filemtime(__DIR__ . '/assets/css/event-details.css') ?>">
</head>
<body class="event-details-page neu">

<?php /* No event to show: no right column. */ ?>
<div class="dashboard-shell<?= $event ? '' : ' no-rail' ?>">

  <?php include __DIR__ . '/../includes/nav.php'; ?>

  <?php include __DIR__ . '/../includes/topbar.php'; ?>

  <!-- Main content -->
  <main class="dash-main ed-main">
    <?php /* data-back: goes back to the page the person came from (assets/js/back-link.js); events.php without it. */ ?>
    <a href="events.php" class="ed-back" data-back>
      <?= icon('arrow-left', 18) ?>
      Back
    </a>

    <?= render_flashes() ?>

    <?php if (!$event): ?>
    <section class="ed-hero ed-not-found">
      <h1 class="ed-title"><?= $eventId === '' ? 'No upcoming events' : 'Event not found' ?></h1>
      <p><?= $eventId === '' ? 'There are no upcoming events right now.' : "This event doesn't exist or isn't published yet." ?> <a href="events.php">Browse all events</a>.</p>
    </section>
    <?php else: ?>
      <?php include __DIR__ . '/../includes/event-detail.php'; ?>
    <?php endif; ?>
  </main>

  <?php if ($event): ?>
  <!-- Right rail: the program flow (section 9 of the event form) -->
  <aside class="right-rail ed-rail">
    <div class="ed-schedule" id="program-flow">
      <h2 class="ed-schedule-title">Program Flow</h2>
      <?php if ($schedule): ?>
        <?php include __DIR__ . '/../includes/event-program-flow.php'; ?>
      <?php else: ?>
        <p class="ed-empty">No program flow posted yet.<?php if ($canManage): ?> <a href="admin/event-organizer.php?edit=<?= urlencode($eventId) ?>#sec-program">Add one</a>.<?php endif; ?></p>
      <?php endif; ?>
    </div>
  </aside>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script src="assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/assets/js/sidebar.js') ?>"></script>
<script src="assets/js/ui.js?v=<?= filemtime(__DIR__ . '/assets/js/ui.js') ?>"></script>
<script src="assets/js/back-link.js?v=<?= filemtime(__DIR__ . '/assets/js/back-link.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
