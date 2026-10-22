<?php
/**
 * The sidebar — the same on every page (public pages, student pages, admin
 * and moderator pages). Set $activeNav before including to highlight the
 * current page:
 *   'home' | 'dashboard' | 'events' | 'my-events' | 'event' | 'organizations' | 'profile'
 *   'manage-events' | 'import' | 'my-officers'           (moderators and admins)
 *   'admin-dashboard' | 'admin-reports' | 'admin-evaluation' | 'admin-taxonomy'
 *   'admin-calendar' | 'admin-users'                      (admins)
 * Logged-out visitors see the public links (Log in / Sign up are in the top
 * bar; on phones they're also at the bottom of this menu).
 *
 * Screen sizes (style.css, assets/js/sidebar.js):
 *   computer  full sidebar with labels
 *   tablet    slim icon-only sidebar; the top bar's menu button opens the full one
 *   phone     hidden; the top bar's menu button slides it in
 */
$activeNav = $activeNav ?? '';
$navUser = current_user();
$navRole = $navUser['role'] ?? '';
$navActive = fn(string $key): string => $activeNav === $key ? ' class="active" aria-current="page"' : '';
/** One sidebar link: icon + label (the label is hidden on the slim tablet sidebar, so it's also the tooltip). */
$navLink = fn(string $href, string $key, string $icon, string $label, int $size = 18): string =>
    '<a href="' . BASE_URL . $href . '"' . $navActive($key) . ' title="' . $label . '"><span class="icon">' . icon($icon, $size) . '</span><span class="nav-label">' . $label . '</span></a>';
$eventsOpen = in_array($activeNav, ['events', 'my-events', 'event'], true);
?>
<aside class="sidebar" id="sidebar" aria-label="Site menu">
  <div class="sidebar-top">
    <a href="<?= BASE_URL ?>/index.php" class="brand">
<<<<<<< Updated upstream
      <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="brand-logo" width="44" height="44">
=======
      <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="Campus Event Hub logo" class="brand-logo" width="44" height="44">
>>>>>>> Stashed changes
      <span class="brand-text">
        <span class="campus">CAMPUS</span>
        <span class="hub">EVENT HUB</span>
      </span>
    </a>
    <button type="button" class="nav-close" data-nav-close aria-label="Close menu"><?= icon('close', 20) ?></button>
  </div>

  <nav class="side-nav" aria-label="Main">
    <?= $navLink('/index.php', 'home', 'home', 'Home') ?>
    <?php if ($navUser): ?>
      <?= $navLink('/enduser/dashboard.php', 'dashboard', 'dashboard', 'Dashboard') ?>

      <div class="side-nav-item has-submenu">
        <button type="button" class="submenu-toggle<?= $eventsOpen ? ' is-current' : '' ?>" id="eventDropdownBtn" aria-expanded="<?= $eventsOpen ? 'true' : 'false' ?>" aria-controls="eventSubmenu" title="Events">
          <span class="icon"><?= icon('ticket') ?></span>
          <span class="nav-label">Events</span>
          <span class="chevron" aria-hidden="true"><?= icon('chevron-down', 14) ?></span>
        </button>
        <div class="event-submenu" id="eventSubmenu"<?= $eventsOpen ? '' : ' hidden' ?>>
          <?= $navLink('/events.php', 'events', 'calendar', 'Event Calendar', 16) ?>
          <?= $navLink('/events.php?mine=1', 'my-events', 'check', 'My Events', 16) ?>
          <?= $navLink('/event-details.php', 'event', 'list', 'Next Event', 16) ?>
        </div>
      </div>
    <?php else: ?>
      <?php /* Visitors only have the calendar, so "Events" is a plain link instead of a one-item dropdown. */ ?>
      <?= $navLink('/events.php', 'events', 'calendar', 'Event Calendar') ?>
    <?php endif; ?>

    <?= $navLink('/organizations.php', 'organizations', 'organizations', 'Organizations') ?>
    <?php if ($navUser): ?>
      <?= $navLink('/enduser/profile.php', 'profile', 'profile', 'My Profile') ?>
    <?php endif; ?>
  </nav>

  <?php if (!$navUser): ?>
    <div class="side-auth">
      <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-primary">Log in</a>
      <a href="<?= BASE_URL ?>/auth/register.php" class="btn btn-outline">Sign up</a>
    </div>
  <?php endif; ?>

  <?php if ($navRole === 'moderator'): ?>
    <nav class="side-nav side-admin" aria-label="Moderator">
      <div class="side-group-label">Moderator</div>
      <?= $navLink('/admin/event-organizer.php', 'manage-events', 'tools', 'Manage Events') ?>
      <?= $navLink('/admin/import.php', 'import', 'import', 'Import &amp; Verify') ?>
      <?php if (!empty($navUser['organization_id'])): ?>
        <?= $navLink('/admin/organization-officers.php?org=' . urlencode($navUser['organization_id']), 'my-officers', 'people', 'Our Officers') ?>
      <?php endif; ?>
    </nav>
  <?php elseif ($navRole === 'admin'): ?>
    <nav class="side-nav side-admin" aria-label="Admin">
      <div class="side-group-label">Admin</div>
      <?= $navLink('/admin/dashboard.php', 'admin-dashboard', 'chart', 'Admin Dashboard') ?>
      <?= $navLink('/admin/event-organizer.php', 'manage-events', 'tools', 'Manage Events') ?>
      <?= $navLink('/admin/reports.php', 'admin-reports', 'reports', 'Reports') ?>
      <?= $navLink('/admin/import.php', 'import', 'import', 'Import &amp; Verify') ?>
      <?= $navLink('/admin/evaluation-form.php', 'admin-evaluation', 'evaluation', 'Evaluation Form') ?>
      <?= $navLink('/admin/admin-taxonomy.php', 'admin-taxonomy', 'tag', 'Categories &amp; Orgs') ?>
      <?= $navLink('/admin/school-calendar.php', 'admin-calendar', 'schedule', 'School Calendar') ?>
      <?= $navLink('/admin/users.php', 'admin-users', 'people', 'Users &amp; Roles') ?>
    </nav>
  <?php endif; ?>
</aside>
<div class="nav-backdrop" data-nav-close hidden></div>
