<?php
/**
 * One event card (home page, dashboard, events list). Expects $ev — an
 * event row selected with EVENT_CARD_SELECT. Shows a "Today" / "Ended" pill
 * when it applies and the live "N going" count.
 */
$cardToday = date('Y-m-d');
$cardLive = ($ev['start_date'] ?? '') <= $cardToday && ($ev['end_date'] ?? '') >= $cardToday;
$cardEnded = ($ev['end_date'] ?? $cardToday) < $cardToday;
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
    <div class="event-meta">📍 <?= htmlspecialchars(event_place($ev)) ?></div>
    <div class="event-meta">👥 <?= get_registration_count((string) $ev['id']) ?> going</div>
  </div>
</a>
