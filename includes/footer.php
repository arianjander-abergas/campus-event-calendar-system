<?php /** Footer for the public pages (home, events, organizations). */ ?>
<footer class="site-footer">
  <div class="container site-footer-inner">
    <div class="site-footer-brand">
      <strong>Campus Event Hub</strong>
      <span>La Consolacion University Philippines</span>
    </div>
    <nav class="site-footer-links" aria-label="Footer">
      <a href="<?= BASE_URL ?>/events.php">Events</a>
      <a href="<?= BASE_URL ?>/organizations.php">Organizations</a>
      <a href="<?= BASE_URL ?>/index.php#about">About Us</a>
      <?php if (current_user()): ?>
        <a href="<?= BASE_URL ?>/enduser/dashboard.php">Dashboard</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/auth/register.php">Sign Up</a>
      <?php endif; ?>
    </nav>
    <div class="site-footer-copy">© <?= date('Y') ?> Campus Event Hub</div>
  </div>
</footer>
