<?php
/**
 * Start of every top bar (includes/topbar.php and the event details page),
 * shown on phones / foldables only (≤ 760px), where the sidebar is hidden:
 * the menu button that slides the sidebar in (assets/js/sidebar.js) and the
 * brand — logo + CAMPUS / EVENT HUB in the same font as the sidebar's.
 */
?>
<div class="topbar-start">
  <button type="button" class="nav-toggle" data-nav-toggle aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><?= icon('menu', 22) ?></button>
  <a href="<?= BASE_URL ?>/index.php" class="brand topbar-brand">
    <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="" width="36" height="36">
    <span class="brand-text">
      <span class="campus">CAMPUS</span>
      <span class="hub">EVENT HUB</span>
    </span>
  </a>
</div>
