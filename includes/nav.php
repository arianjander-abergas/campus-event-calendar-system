<?php
/**
 * Sidebar for every dashboard-style page (student + admin).
 * Set $activeNav before including to highlight the current page:
 *   'dashboard' | 'events' | 'my-events' | 'event' | 'organizations' | 'profile'
 *   'admin-dashboard' | 'admin-events' | 'admin-reports' | 'admin-evaluation'
 *   'admin-taxonomy' | 'admin-calendar' | 'admin-users'
 */
$activeNav = $activeNav ?? '';
$navUser = current_user();
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
    <a href="<?= BASE_URL ?>/index.php"><span class="icon"><?= icon('home') ?></span> Home</a>
    <a href="<?= BASE_URL ?>/enduser/dashboard.php"<?= $navActive('dashboard') ?>><span class="icon"><?= icon('dashboard') ?></span> Dashboard</a>

    <div class="side-nav-item has-submenu">
      <button type="button" class="submenu-toggle<?= $eventsOpen ? ' is-current' : '' ?>" id="eventDropdownBtn" aria-expanded="<?= $eventsOpen ? 'true' : 'false' ?>" aria-controls="eventSubmenu">
        <span class="icon"><?= icon('ticket') ?></span>
        <span>Events</span>
        <span class="chevron" aria-hidden="true"><?= icon('chevron-down', 14) ?></span>
      </button>
      <div class="event-submenu" id="eventSubmenu"<?= $eventsOpen ? '' : ' hidden' ?>>
        <a href="<?= BASE_URL ?>/events.php"<?= $navActive('events') ?>><span class="icon"><?= icon('list', 16) ?></span> All Events</a>
        <a href="<?= BASE_URL ?>/events.php?mine=1"<?= $navActive('my-events') ?>><span class="icon"><?= icon('check', 16) ?></span> My Events</a>
        <a href="<?= BASE_URL ?>/event-details.php"<?= $navActive('event') ?>><span class="icon"><?= icon('calendar', 16) ?></span> Next Event</a>
      </div>
    </div>

    <a href="<?= BASE_URL ?>/organizations.php"<?= $navActive('organizations') ?>><span class="icon"><?= icon('organizations') ?></span> Organizations</a>
    <a href="<?= BASE_URL ?>/enduser/profile.php"<?= $navActive('profile') ?>><span class="icon"><?= icon('profile') ?></span> My Profile</a>
  </nav>

  <?php if (($navUser['role'] ?? '') === 'admin'): ?>
    <nav class="side-nav side-admin" aria-label="Admin">
      <div class="side-group-label">Admin</div>
      <a href="<?= BASE_URL ?>/admin/dashboard.php"<?= $navActive('admin-dashboard') ?>><span class="icon"><?= icon('chart') ?></span> Admin Dashboard</a>
      <a href="<?= BASE_URL ?>/admin/event-organizer.php"<?= $navActive('admin-events') ?>><span class="icon"><?= icon('tools') ?></span> Manage Events</a>
      <a href="<?= BASE_URL ?>/admin/reports.php"<?= $navActive('admin-reports') ?>><span class="icon"><?= icon('reports') ?></span> Reports</a>
      <a href="<?= BASE_URL ?>/admin/evaluation-form.php"<?= $navActive('admin-evaluation') ?>><span class="icon"><?= icon('evaluation') ?></span> Evaluation Form</a>
      <a href="<?= BASE_URL ?>/admin/admin-taxonomy.php"<?= $navActive('admin-taxonomy') ?>><span class="icon"><?= icon('tag') ?></span> Categories &amp; Orgs</a>
      <a href="<?= BASE_URL ?>/admin/school-calendar.php"<?= $navActive('admin-calendar') ?>><span class="icon"><?= icon('schedule') ?></span> School Calendar</a>
      <a href="<?= BASE_URL ?>/admin/users.php"<?= $navActive('admin-users') ?>><span class="icon"><?= icon('people') ?></span> Users</a>
    </nav>
  <?php endif; ?>
</aside>
