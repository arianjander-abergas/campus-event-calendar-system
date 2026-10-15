<?php
/**
 * One event card (home page, dashboard, events list). Expects $ev — an
 * event row selected with EVENT_CARD_SELECT. Shows a "Today" / "Ended" /
 * "Completed" pill when it applies (or $ev['my_label'] on My Events, e.g.
 * "Registered", "Completed"), where and for whom the event is (with its year
 * levels), the live "N going" count and the organizing organization.
 */
/** @var array $ev set by the page that includes this card */
$cardToday = date('Y-m-d');
$cardLive = ($ev['start_date'] ?? '') <= $cardToday && ($ev['end_date'] ?? '') >= $cardToday;
$cardEnded = ($ev['end_date'] ?? $cardToday) < $cardToday || ($ev['status'] ?? '') === 'completed';
$cardLevels = year_levels_label($ev['year_levels'] ?? []);
$cardOnline = ($ev['venue_type'] ?? '') === 'online';
$cardOrg = $ev['organizations'] ?? null;
?>
<a class="event-card" href="<?= BASE_URL ?>/event-details.php?id=<?= urlencode((string) $ev['id']) ?>">
  <div class="event-thumb" style="background-image:url('<?= css_url(event_poster_url($ev['poster_url'] ?? null)) ?>');">
    <span class="event-tag"><?= htmlspecialchars(event_category_label($ev)) ?></span>
    <?php if (!empty($ev['my_label'])): ?>
      <span class="status-pill status-<?= htmlspecialchars(strtolower($ev['my_label'])) ?>"><?= htmlspecialchars($ev['my_label']) ?></span>
    <?php elseif (($ev['status'] ?? '') === 'completed'): ?>
      <span class="status-pill status-completed">Completed</span>
    <?php elseif ($cardLive): ?>
      <span class="status-pill status-today">Today</span>
    <?php elseif ($cardEnded): ?>
      <span class="status-pill status-ended">Ended</span>
    <?php endif; ?>
  </div>
  <div class="event-body">
    <h3 class="event-name"><?= htmlspecialchars($ev['title']) ?></h3>
    <div class="event-meta"><?= icon('calendar', 14) ?> <?= htmlspecialchars(event_date_range($ev)) ?><?= !empty($ev['start_time']) ? ' · ' . htmlspecialchars(format_event_time($ev['start_time'])) : '' ?></div>
    <?php if ($cardOnline): ?>
      <div class="event-meta"><?= icon('online', 14) ?> Online (Webinar)</div>
    <?php else: ?>
      <div class="event-meta"><?= icon('location', 14) ?> <?= htmlspecialchars(event_place($ev)) ?><?= !empty($ev['venue_type']) ? ' · ' . htmlspecialchars(label_of(VENUE_TYPES, $ev['venue_type'], '')) : '' ?></div>
    <?php endif; ?>
    <?php if (!empty($ev['audience'])): ?>
      <div class="event-meta"><?= icon('audience', 14) ?> For <?= htmlspecialchars(label_of(EVENT_AUDIENCES, $ev['audience'], '')) ?><?= $cardLevels !== '' ? ' · ' . htmlspecialchars($cardLevels) : '' ?></div>
    <?php endif; ?>
    <div class="event-meta"><?= icon('people', 14) ?> <?= get_registration_count((string) $ev['id']) ?> going</div>
    <?php if (!empty($cardOrg['name'])): ?>
      <div class="event-org">
        <?php if (!empty($cardOrg['logo_url'])): ?>
          <img src="<?= htmlspecialchars(asset_url($cardOrg['logo_url'])) ?>" alt="" class="event-org-logo" width="22" height="22">
        <?php else: ?>
          <span class="event-org-logo event-org-initials" aria-hidden="true"><?= htmlspecialchars(initials($cardOrg['name'])) ?></span>
        <?php endif; ?>
        <span><?= htmlspecialchars($cardOrg['name']) ?></span>
      </div>
    <?php endif; ?>
  </div>
</a>
