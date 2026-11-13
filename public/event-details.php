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

<div class="dashboard-shell">

  <?php include __DIR__ . '/../includes/nav.php'; ?>

  <?php include __DIR__ . '/../includes/topbar.php'; ?>

  <!-- Main content -->
  <main class="dash-main ed-main">
    <a href="events.php" class="ed-back">
      <?= icon('arrow-left', 18) ?>
      Back to All Events
    </a>

    <?= render_flashes() ?>

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
          <div class="ed-meta-row"><?= icon('audience', 12) ?> For <?= htmlspecialchars(event_audience_label($event)) ?><?= $openTo !== '' && in_array('students', event_audiences($event), true) ? ' · Open to ' . htmlspecialchars($openTo) . ' students' : '' ?></div>
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
          <img loading="lazy" decoding="async" src="<?= htmlspecialchars($orgLogo) ?>" alt="" class="ed-org-logo">
          <div class="ed-org-text">
            <span>Organize by</span>
            <?php if (!empty($event['organizations']['id'])): ?>
              <a href="organizations.php#org-<?= htmlspecialchars($event['organizations']['id']) ?>"><?= htmlspecialchars($orgName) ?></a>
            <?php else: ?>
              <span><?= htmlspecialchars($orgName) ?></span>
            <?php endif; ?>
            <?php foreach ($coOrganizers as $i => $co): ?>
              <?= $i === count($coOrganizers) - 1 ? '&amp;' : ',' ?>
              <a href="organizations.php#org-<?= htmlspecialchars($co['id']) ?>"><?= htmlspecialchars($co['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>

        <?php /* id="register": the "Register" button in notification emails opens the page here. */ ?>
        <?php
        // One line saying where the person stands, and only the buttons that apply:
        //   open → Register Now! · registered → Registered ✓ + Cancel registration
        //   attended → Evaluate / Completed ✓ · ended or closed → just the line.
        // "Required" only matters while the event hasn't ended.
        $ended = event_has_ended($event);
        $requiredForMe = !$ended && is_required_for_me($eventId);
        $requiredLabel = $ended ? '' : required_for_label($event);
        $statusLine = $reg['state'] === 'registered' && $requiredForMe ? "You're registered. Your attendance is required." : $reg['note'];
        ?>
        <div class="ed-register" id="register">
          <?php if ($requiredForMe && $reg['state'] !== 'registered'): ?>
            <p class="ed-required-note" role="status"><strong>Your attendance is required.</strong><?= $reg['can_register'] ? ' Please register below.' : '' ?></p>
          <?php endif; ?>
          <?php if ($statusLine !== ''): ?>
            <p class="ed-reg-note ed-reg-<?= htmlspecialchars($reg['state']) ?>"><?= htmlspecialchars($statusLine) ?></p>
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
                <form method="POST" action="event-details.php?id=<?= urlencode($eventId) ?>" data-confirm="Cancel your registration for this event?" data-confirm-title="Cancel registration" data-confirm-ok="Cancel registration" data-confirm-cancel="Stay registered" data-confirm-tone="danger">
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
            <?php if ($requiredLabel !== '' && !$requiredForMe): ?><p class="ed-reg-note ed-reg-staff"><?= htmlspecialchars($requiredLabel) ?>.</p><?php endif; ?>
            <div class="ed-admin-links">
              <a href="admin/event-organizer.php?edit=<?= urlencode($eventId) ?>">Edit event</a>
              <a href="admin/event-organizer.php?edit=<?= urlencode($eventId) ?>#sec-program">Edit program flow</a>
              <a href="admin/event-registrations.php?event=<?= urlencode($eventId) ?>">Registrations</a>
              <a href="admin/attendance-print.php?event=<?= urlencode($eventId) ?>">Print attendance</a>
              <a href="admin/event-evaluations.php?event=<?= urlencode($eventId) ?>">Evaluation results</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </main>

  <!-- Right rail: the program flow (section 9 of the event form) -->
  <aside class="right-rail ed-rail">
    <div class="ed-schedule" id="program-flow">
      <h2 class="ed-schedule-title">Program Flow</h2>

      <?php if (empty($schedule)): ?>
        <p class="ed-empty">No program flow posted yet.<?php if ($canManage && $event): ?> <a href="admin/event-organizer.php?edit=<?= urlencode($eventId) ?>#sec-program">Add one</a>.<?php endif; ?></p>
      <?php else: ?>
      <ol class="ed-flow">
        <?php foreach ($schedule as $i => $item): ?>
        <li class="ed-flow-item">
          <span class="ed-flow-num" aria-hidden="true"><?= $i + 1 ?></span>
          <div class="ed-flow-body">
            <span class="ed-flow-title"><?= htmlspecialchars($item['title']) ?></span>
            <?php if (!empty($item['speaker'])): ?><span class="ed-flow-speaker"><?= htmlspecialchars($item['speaker']) ?></span><?php endif; ?>
            <?php if (!empty($item['description'])): ?><span class="ed-flow-notes"><?= htmlspecialchars($item['description']) ?></span><?php endif; ?>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
    </div>
  </aside>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script src="assets/js/sidebar.js?v=<?= filemtime(__DIR__ . '/assets/js/sidebar.js') ?>"></script>
<script src="assets/js/ui.js?v=<?= filemtime(__DIR__ . '/assets/js/ui.js') ?>"></script>
<?= presence_script_tag() ?>
</body>
</html>
