<?php
/**
 * Site footer for the user-facing pages only — home, Event Calendar / My Events,
 * event details, organizations, dashboard, My Profile and the evaluation form.
 * The admin / moderator pages don't include it.
 *
 * Include it inside .dashboard-shell, right after </main> (and after the right
 * column on pages that have one), so it spans the content area under both:
 *   <?php include __DIR__ . '/../includes/footer.php'; ?>
 *
 * One slim row: brand, links and copyright.
 */
$footerUser = current_user();
?>
<footer class="site-footer">
  <div class="site-footer-bar">
    <a href="<?= BASE_URL ?>/index.php" class="site-footer-brand">
      <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="" class="site-footer-logo" width="28" height="28">
      <span>
        <strong>Campus Event Hub</strong>
        <span><?= htmlspecialchars(SCHOOL_NAME) ?></span>
      </span>
    </a>
    <nav class="site-footer-links" aria-label="Footer">
      <a href="<?= BASE_URL ?>/events.php">Event Calendar</a>
      <a href="<?= BASE_URL ?>/organizations.php">Organizations</a>
      <a href="<?= BASE_URL ?>/index.php#about">About Us</a>
      <?php if ($footerUser): ?>
        <a href="<?= BASE_URL ?>/enduser/dashboard.php">Dashboard</a>
        <a href="<?= BASE_URL ?>/events.php?mine=1">My Events</a>
        <a href="<?= BASE_URL ?>/enduser/profile.php">My Profile</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/auth/login.php">Log in</a>
        <a href="<?= BASE_URL ?>/auth/register.php">Sign up</a>
      <?php endif; ?>
    </nav>
    <p class="site-footer-copy">© <?= date('Y') ?> Campus Event Hub</p>
  </div>
</footer>
