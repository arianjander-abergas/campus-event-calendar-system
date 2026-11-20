<?php
/**
 * One event's details: the status banner, poster, what / when / where / for
 * whom, the organizers, and where the logged-in person stands (Register /
 * Registered ✓ + Cancel / Evaluate), plus a folded "Manage event" menu for
 * the event's admins.
 *
 * Used by event-details.php for the full page AND for the pop-up that opens
 * when an event is clicked (?modal=1, assets/js/event-modal.js). The pop-up
 * is shown on pages in every folder, so every link here starts at BASE_URL.
 *
 * Expects from event-details.php: $event, $eventId, $posterUrl, $orgLogo,
 * $categoryName, $orgName, $coOrganizers, $going, $reg, $attended,
 * $evaluated, $canManage, $openTo, $banner, $schedule, $edModal (true in the pop-up).
 */
$edUrl = BASE_URL . '/event-details.php?id=' . urlencode($eventId);
$edFormAction = $edUrl . ($edModal ? '&modal=1' : '');
$edManageUrl = BASE_URL . '/admin/event-organizer.php?edit=' . urlencode($eventId);
$edTitleTag = $edModal ? 'h2' : 'h1';
$edOnline = ($event['venue_type'] ?? '') === 'online';
// Where: "Gymnasium — Address · On-campus" on one line (online events: "Online / Webinar").
$edVenueType = label_of(VENUE_TYPES, $event['venue_type'] ?? null, 'On-campus');
$edPlace = event_place($event) === 'Online' ? $edVenueType
    : event_place($event) . (!empty($event['address']) ? ' — ' . $event['address'] : '') . ' · ' . $edVenueType;

// One line saying where the person stands, and only the buttons that apply:
//   open → Register Now! · registered → Registered ✓ + Cancel registration
//   attended → Evaluate / Completed ✓ · ended or closed → just the line.
// "Required" only matters while the event hasn't ended.
$ended = event_has_ended($event);
$requiredForMe = !$ended && is_required_for_me($eventId);
$requiredLabel = $ended ? '' : required_for_label($event);
$statusLine = $reg['state'] === 'registered' && $requiredForMe ? "You're registered. Your attendance is required." : $reg['note'];
?>
<?php if ($banner): ?>
  <div class="ed-flash <?= $banner[0] === 'error' ? 'ed-flash-error' : 'ed-flash-info' ?>"><?= htmlspecialchars($banner[1]) ?></div>
<?php endif; ?>

<section class="ed-hero">
  <!-- IMAGE SLOT 1: Event poster (used when the event has no poster_url) | file: assets/images/event-details/event-poster.svg | 340×345 -->
  <img src="<?= htmlspecialchars($posterUrl) ?>" alt="<?= htmlspecialchars($event['title']) ?> poster" class="ed-poster">

  <div class="ed-hero-info">
    <span class="ed-category"><?= htmlspecialchars($categoryName) ?></span>
    <<?= $edTitleTag ?> class="ed-title"<?= $edModal ? ' id="edModalTitle"' : '' ?>><?= htmlspecialchars($event['title']) ?></<?= $edTitleTag ?>>
    <?php if (trim((string) ($event['description'] ?? '')) !== ''): ?>
      <p class="ed-desc"><?= nl2br(htmlspecialchars($event['description'])) ?></p>
    <?php endif; ?>

    <div class="ed-meta">
      <div class="ed-meta-row"><?= icon('calendar', 12) ?> <?= htmlspecialchars(event_when($event)) ?></div>
      <div class="ed-meta-row"><?= icon($edOnline ? 'online' : 'location', 12) ?> <?= htmlspecialchars($edPlace) ?></div>
      <div class="ed-meta-row"><?= icon('audience', 12) ?> For <?= htmlspecialchars(event_audience_label($event)) ?><?= $openTo !== '' && in_array('students', event_audiences($event), true) ? ' · Open to ' . htmlspecialchars($openTo) . ' students' : '' ?></div>
      <div class="ed-meta-row"><?= icon('people', 12) ?> <?= $going ?> going<?= !empty($event['registration_limit']) ? ' · ' . (int) $event['registration_limit'] . ' slots' : '' ?></div>
      <?php if (!empty($event['online_event_link']) && preg_match('~^https?://~i', $event['online_event_link'])): ?>
        <div class="ed-meta-row"><?= icon('link', 12) ?> <a href="<?= htmlspecialchars($event['online_event_link']) ?>" target="_blank" rel="noopener">Join online</a></div>
      <?php endif; ?>
      <?php if (!empty($event['contact_email']) || !empty($event['contact_number'])): ?>
        <div class="ed-meta-row"><?= icon('email', 12) ?> <?= htmlspecialchars(implode(' · ', array_filter([$event['contact_email'] ?? '', $event['contact_number'] ?? '']))) ?></div>
      <?php endif; ?>
    </div>

    <div class="ed-organizer">
      <!-- IMAGE SLOT 2: Organization logo (used when the org has no logo_url) | file: assets/images/event-details/org-logo.svg | 72×72 -->
      <img loading="lazy" decoding="async" src="<?= htmlspecialchars($orgLogo) ?>" alt="" class="ed-org-logo">
      <div class="ed-org-text">
        <span>Organized by</span>
        <?php if (!empty($event['organizations']['id'])): ?>
          <a href="<?= BASE_URL ?>/organizations.php#org-<?= htmlspecialchars($event['organizations']['id']) ?>"><?= htmlspecialchars($orgName) ?></a>
        <?php else: ?>
          <span><?= htmlspecialchars($orgName) ?></span>
        <?php endif; ?>
        <?php foreach ($coOrganizers as $i => $co): ?>
          <?= $i === count($coOrganizers) - 1 ? '&amp;' : ',' ?>
          <a href="<?= BASE_URL ?>/organizations.php#org-<?= htmlspecialchars($co['id']) ?>"><?= htmlspecialchars($co['name']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <?php /* id="register": the "Register" button in notification emails opens the full page here. */ ?>
    <div class="ed-register"<?= $edModal ? '' : ' id="register"' ?>>
      <?php if ($requiredForMe && $reg['state'] !== 'registered'): ?>
        <p class="ed-required-note" role="status"><strong>Your attendance is required.</strong><?= $reg['can_register'] ? ' Please register below.' : '' ?></p>
      <?php endif; ?>
      <?php if ($statusLine !== ''): ?>
        <p class="ed-reg-note ed-reg-<?= htmlspecialchars($reg['state']) ?>"><?= htmlspecialchars($statusLine) ?></p>
      <?php endif; ?>
      <?php if ($reg['can_register']): ?>
        <form method="POST" action="<?= htmlspecialchars($edFormAction) ?>">
          <input type="hidden" name="action" value="register">
          <button type="submit" class="ed-btn">Register Now!</button>
        </form>
      <?php elseif ($reg['state'] === 'registered' || $reg['state'] === 'attended'): ?>
        <div class="ed-reg-actions">
          <?php if ($attended && !$evaluated): ?>
            <a href="<?= BASE_URL ?>/enduser/evaluate.php?event=<?= urlencode($eventId) ?>" class="ed-btn ed-btn-evaluate">Evaluate this event</a>
          <?php else: ?>
            <button class="ed-btn" disabled><?= $evaluated ? 'Completed ✓' : ($reg['state'] === 'attended' ? 'Attended ✓' : 'Registered ✓') ?></button>
          <?php endif; ?>
          <?php if ($reg['can_cancel']): ?>
            <form method="POST" action="<?= htmlspecialchars($edFormAction) ?>" data-confirm="Cancel your registration for this event?" data-confirm-title="Cancel registration" data-confirm-ok="Cancel registration" data-confirm-cancel="Stay registered" data-confirm-tone="danger">
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
        <?php /* The admin tools, folded into one menu so they don't crowd the page. */ ?>
        <details class="ed-manage">
          <summary>Manage event <span aria-hidden="true">▾</span></summary>
          <div class="ed-admin-links">
            <a href="<?= htmlspecialchars($edManageUrl) ?>">Edit event</a>
            <a href="<?= htmlspecialchars($edManageUrl) ?>#sec-program"><?= $schedule ? 'Edit program flow' : 'Add a program flow' ?></a>
            <a href="<?= BASE_URL ?>/admin/event-registrations.php?event=<?= urlencode($eventId) ?>">Registrations</a>
            <a href="<?= BASE_URL ?>/admin/attendance-print.php?event=<?= urlencode($eventId) ?>">Print attendance</a>
            <a href="<?= BASE_URL ?>/admin/event-evaluations.php?event=<?= urlencode($eventId) ?>">Evaluation results</a>
          </div>
        </details>
      <?php endif; ?>
    </div>
  </div>
</section>
