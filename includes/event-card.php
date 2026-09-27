<?php
/**
 * One event card (home page, dashboard, events list). Expects $ev — an
 * event row selected with EVENT_CARD_SELECT. Shows a "Today" / "Ended" pill
 * when it applies, where and for whom the event is, the live "N going"
 * count and the organizing organization with its logo.
 */
$cardToday = date('Y-m-d');
$cardLive = ($ev['start_date'] ?? '') <= $cardToday && ($ev['end_date'] ?? '') >= $cardToday;
$cardEnded = ($ev['end_date'] ?? $cardToday) < $cardToday;
$cardOnline = ($ev['venue_type'] ?? '') === 'online';
$cardOrg = $ev['organizations'] ?? null;
?>
<a class="event-card" href="<?= BASE_URL ?>/event-details.php?id=<?= urlencode((string) $ev['id']) ?>">
  <div class="event-thumb" style="background-image:url('<?= css_url(event_poster_url($ev['poster_url'] ?? null)) ?>');">
    <span class="event-tag"><?= htmlspecialchars($ev['categories']['name'] ?? EVENT_TYPES[$ev['event_type'] ?? 'other'] ?? 'Event') ?></span>
    <?php if ($cardLive): ?>
      <span class="status-pill status-today">Today</span>
    <?php elseif ($cardEnded): ?>
      <span class="status-pill status-ended">Ended</span>
    <?php endif; ?>
  </div>
  <div class="event-body">
    <h3 class="event-name"><?= htmlspecialchars($ev['title']) ?></h3>
    <div class="event-meta">📅 <?= htmlspecialchars(format_event_date($ev['start_date'] ?? null)) ?><?= !empty($ev['start_time']) ? ' · ' . htmlspecialchars(format_event_time($ev['start_time'])) : '' ?></div>
    <?php if ($cardOnline): ?>
      <div class="event-meta">💻 Online (Webinar)</div>
    <?php else: ?>
      <div class="event-meta">📍 <?= htmlspecialchars(event_place($ev)) ?><?= !empty($ev['venue_type']) ? ' · ' . htmlspecialchars(label_of(VENUE_TYPES, $ev['venue_type'], '')) : '' ?></div>
    <?php endif; ?>
    <?php if (!empty($ev['audience'])): ?>
      <div class="event-meta">🎓 For <?= htmlspecialchars(label_of(EVENT_AUDIENCES, $ev['audience'], '')) ?></div>
    <?php endif; ?>
    <div class="event-meta">👥 <?= get_registration_count((string) $ev['id']) ?> going</div>
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
