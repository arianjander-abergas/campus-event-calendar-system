<?php
/**
 * An event's Program Flow: its parts from event_programs, in running order
 * (sort_order), numbered on a line. Shown in the right column of
 * event-details.php and folded under the details in the event pop-up.
 * Expects $schedule (get_event_programs(), not empty).
 */
?>
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
