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
 * Top: "Trusted by campus organizations" (their logos link to the
 * Organizations page). Bottom: brand, links and copyright.
 */
$footerUser = current_user();
$footerOrgs = array_slice(get_organizations(), 0, 6);
?>
<footer class="site-footer">
  <?php if ($footerOrgs): ?>
    <section class="trusted-strip" aria-labelledby="trustedTitle">
      <div class="trusted-text">
        <h2 class="trusted-title" id="trustedTitle">Trusted by campus organizations</h2>
        <p class="trusted-copy">
          Campus Event Hub helps our organizations connect, organize and engage more students in campus activities.
          <a href="<?= BASE_URL ?>/organizations.php">Meet the organizations →</a>
        </p>
      </div>
      <div class="trusted-logos">
        <?php foreach ($footerOrgs as $o): ?>
          <a href="<?= BASE_URL ?>/organizations.php#org-<?= htmlspecialchars($o['id']) ?>" title="<?= htmlspecialchars($o['name']) ?>">
            <?php if (!empty($o['logo_url'])): ?>
              <img src="<?= htmlspecialchars(asset_url($o['logo_url'])) ?>" alt="<?= htmlspecialchars($o['name']) ?>" width="48" height="48">
            <?php else: ?>
              <span aria-label="<?= htmlspecialchars($o['name']) ?>"><?= htmlspecialchars(initials($o['name'])) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <div class="site-footer-bar">
    <a href="<?= BASE_URL ?>/index.php" class="site-footer-brand">
<<<<<<< Updated upstream
      <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="" class="site-footer-logo" width="48" height="48">
=======
      <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="" class="site-footer-logo" width="28" height="28">
>>>>>>> Stashed changes
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
