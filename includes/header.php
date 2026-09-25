<?php
/**
 * Header for the public pages (home, events, organizations). Set $activePage
 * ('home' | 'events' | 'organizations') to highlight the current link.
 */
if (!function_exists('current_user')) {
    require_once __DIR__ . '/../config/supabase.php';
}
$headerUser = current_user();
$activePage = $activePage ?? '';
$headerLink = fn(string $key): string => $activePage === $key ? ' class="active" aria-current="page"' : '';
?>
<header class="site-header">
  <a href="<?= BASE_URL ?>/index.php" class="brand">
    <span class="brand-icon">📅</span>
    <span class="brand-text">
      <span class="campus">CAMPUS</span>
      <span class="hub">EVENT HUB</span>
    </span>
  </a>

  <nav class="main-nav" aria-label="Main">
    <a href="<?= BASE_URL ?>/index.php"<?= $headerLink('home') ?>>Home</a>
    <a href="<?= BASE_URL ?>/events.php"<?= $headerLink('events') ?>>Events</a>
    <a href="<?= BASE_URL ?>/organizations.php"<?= $headerLink('organizations') ?>>Organizations</a>
    <a href="<?= BASE_URL ?>/index.php#about">About Us</a>
    <?php if ($headerUser): ?>
      <a href="<?= BASE_URL ?>/enduser/dashboard.php">Dashboard</a>
    <?php endif; ?>
  </nav>

  <div class="header-right">
    <form class="search-box" action="<?= BASE_URL ?>/events.php" method="get" role="search">
      <span aria-hidden="true">🔍</span>
      <input type="search" name="q" value="<?= htmlspecialchars(get_param('q')) ?>" placeholder="Search for events, organizations, or keywords" aria-label="Search events">
    </form>
    <?php if ($headerUser): ?>
      <div class="header-user">
        <a href="<?= BASE_URL ?>/enduser/profile.php" class="header-user-name" title="My profile"><?= htmlspecialchars($headerUser['full_name']) ?></a>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="btn-login btn-logout">LOG OUT</a>
      </div>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/auth/login.php" class="btn-login">LOGIN</a>
    <?php endif; ?>
  </div>
</header>
