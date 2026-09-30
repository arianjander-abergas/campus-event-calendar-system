<?php
/**
 * The sidebar — the same on every page (public pages, student pages, admin
 * and moderator pages). Set $activeNav before including to highlight the
 * current page:
 *   'home' | 'dashboard' | 'events' | 'my-events' | 'event' | 'organizations' | 'profile'
 *   'manage-events' | 'import' | 'my-officers'           (moderators and admins)
 *   'admin-dashboard' | 'admin-reports' | 'admin-evaluation' | 'admin-taxonomy'
 *   'admin-calendar' | 'admin-users'                      (admins)
 * Logged-out visitors see the public links plus Log in / Sign up.
 */
$activeNav = $activeNav ?? '';
$navUser = current_user();
$navRole = $navUser['role'] ?? '';
$navActive = fn(string $key): string => $activeNav === $key ? ' class="active" aria-current="page"' : '';
$eventsOpen = in_array($activeNav, ['events', 'my-events', 'event'], true);
?>
<aside class="sidebar">
  <div class="sidebar-top">
    <a href="<?= BASE_URL ?>/index.php" class="brand">
      <img src="<?= htmlspecialchars(school_logo_url()) ?>" alt="<?= htmlspecialchars(SCHOOL_NAME) ?> logo" class="brand-logo" width="44" height="44">
      <span class="brand-text">
        <span class="campus">CAMPUS</span>
        <span class="hub">EVENT HUB</span>
      </span>
    </a>
  </div>

  <nav class="side-nav" aria-label="Main">
    <a href="<?= BASE_URL ?>/index.php"<?= $navActive('home') ?>><span class="icon"><?= icon('home') ?></span> Home</a>
    <?php if ($navUser): ?>
      <a href="<?= BASE_URL ?>/enduser/dashboard.php"<?= $navActive('dashboard') ?>><span class="icon"><?= icon('dashboard') ?></span> Dashboard</a>
    <?php endif; ?>

    <div class="side-nav-item has-submenu">
      <button type="button" class="submenu-toggle<?= $eventsOpen ? ' is-current' : '' ?>" id="eventDropdownBtn" aria-expanded="<?= $eventsOpen ? 'true' : 'false' ?>" aria-controls="eventSubmenu">
        <span class="icon"><?= icon('ticket') ?></span>
        <span>Events</span>
        <span class="chevron" aria-hidden="true"><?= icon('chevron-down', 14) ?></span>
      </button>
      <div class="event-submenu" id="eventSubmenu"<?= $eventsOpen ? '' : ' hidden' ?>>
        <a href="<?= BASE_URL ?>/events.php"<?= $navActive('events') ?>><span class="icon"><?= icon('calendar', 16) ?></span> Event Calendar</a>
        <?php if ($navUser): ?>
          <a href="<?= BASE_URL ?>/events.php?mine=1"<?= $navActive('my-events') ?>><span class="icon"><?= icon('check', 16) ?></span> My Events</a>
          <a href="<?= BASE_URL ?>/event-details.php"<?= $navActive('event') ?>><span class="icon"><?= icon('list', 16) ?></span> Next Event</a>
        <?php endif; ?>
      </div>
    </div>

    <a href="<?= BASE_URL ?>/organizations.php"<?= $navActive('organizations') ?>><span class="icon"><?= icon('organizations') ?></span> Organizations</a>
    <?php if ($navUser): ?>
      <a href="<?= BASE_URL ?>/enduser/profile.php"<?= $navActive('profile') ?>><span class="icon"><?= icon('profile') ?></span> My Profile</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/auth/login.php"><span class="icon"><?= icon('profile') ?></span> Log in</a>
      <a href="<?= BASE_URL ?>/auth/register.php"><span class="icon"><?= icon('register') ?></span> Sign up</a>
    <?php endif; ?>
  </nav>

  <?php if ($navRole === 'moderator'): ?>
    <nav class="side-nav side-admin" aria-label="Moderator">
      <div class="side-group-label">Moderator</div>
      <a href="<?= BASE_URL ?>/admin/event-organizer.php"<?= $navActive('manage-events') ?>><span class="icon"><?= icon('tools') ?></span> Manage Events</a>
      <a href="<?= BASE_URL ?>/admin/import.php"<?= $navActive('import') ?>><span class="icon"><?= icon('import') ?></span> Import &amp; Verify</a>
      <?php if (!empty($navUser['organization_id'])): ?>
        <a href="<?= BASE_URL ?>/admin/organization-officers.php?org=<?= urlencode($navUser['organization_id']) ?>"<?= $navActive('my-officers') ?>><span class="icon"><?= icon('people') ?></span> Our Officers</a>
      <?php endif; ?>
    </nav>
  <?php elseif ($navRole === 'admin'): ?>
    <nav class="side-nav side-admin" aria-label="Admin">
      <div class="side-group-label">Admin</div>
      <a href="<?= BASE_URL ?>/admin/dashboard.php"<?= $navActive('admin-dashboard') ?>><span class="icon"><?= icon('chart') ?></span> Admin Dashboard</a>
      <a href="<?= BASE_URL ?>/admin/event-organizer.php"<?= $navActive('manage-events') ?>><span class="icon"><?= icon('tools') ?></span> Manage Events</a>
      <a href="<?= BASE_URL ?>/admin/reports.php"<?= $navActive('admin-reports') ?>><span class="icon"><?= icon('reports') ?></span> Reports</a>
      <a href="<?= BASE_URL ?>/admin/import.php"<?= $navActive('import') ?>><span class="icon"><?= icon('import') ?></span> Import &amp; Verify</a>
      <a href="<?= BASE_URL ?>/admin/evaluation-form.php"<?= $navActive('admin-evaluation') ?>><span class="icon"><?= icon('evaluation') ?></span> Evaluation Form</a>
      <a href="<?= BASE_URL ?>/admin/admin-taxonomy.php"<?= $navActive('admin-taxonomy') ?>><span class="icon"><?= icon('tag') ?></span> Categories &amp; Orgs</a>
      <a href="<?= BASE_URL ?>/admin/school-calendar.php"<?= $navActive('admin-calendar') ?>><span class="icon"><?= icon('schedule') ?></span> School Calendar</a>
      <a href="<?= BASE_URL ?>/admin/users.php"<?= $navActive('admin-users') ?>><span class="icon"><?= icon('people') ?></span> Users &amp; Roles</a>
    </nav>
  <?php endif; ?>
</aside>
