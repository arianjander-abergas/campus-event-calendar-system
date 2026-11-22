<?php
/**
 * The sidebar — the same on every page (public pages, student pages, admin
 * and moderator pages). Set $activeNav before including to highlight the
 * current page:
 *   'home' | 'dashboard' | 'events' | 'my-events' | 'event' | 'organizations' | 'profile'
 *   'manage-create' | 'manage-update' | 'import' | 'my-officers'  (moderators and admins)
 *   'admin-dashboard' | 'admin-reports' | 'admin-evaluation' | 'admin-calendar'
 *   'admin-categories' | 'admin-departments' | 'admin-organizations' | 'admin-venues'  (Categories ▾)
 *   'admin-users' | 'admin-roles'  (Users & Roles ▾, admins)
 *   'admin-approvals-events' | 'admin-approvals-profiles'  (Approvals ▾, staff)
 * Logged-out visitors see the public links (Log in / Sign up are in the top
 * bar; on phones they're also at the bottom of this menu).
 *
 * It also brings the Event Hub Assistant chat box (includes/assistant-widget.php),
 * so the assistant is on every page that has this sidebar.
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
/** One sidebar link: icon + label (the label is hidden on the slim tablet sidebar, so it's also the tooltip). $count: a red number badge (0 = none). */
$navLink = fn(string $href, string $key, string $icon, string $label, int $size = 18, int $count = 0): string =>
    '<a href="' . BASE_URL . $href . '"' . $navActive($key) . ' title="' . $label . ($count ? ' (' . $count . ' waiting)' : '') . '"><span class="icon">' . icon($icon, $size) . '</span><span class="nav-label">' . $label . '</span>'
    . ($count ? '<span class="nav-count" aria-label="' . $count . ' waiting">' . ($count > 99 ? '99+' : $count) . '</span>' : '') . '</a>';
$eventsOpen = in_array($activeNav, ['events', 'my-events', 'event'], true);
$manageOpen = in_array($activeNav, ['manage-create', 'manage-update'], true);
/** "Manage Events" dropdown (moderators and admins): Create Event, Update Events. */
$manageMenu = fn(): string =>
    '<div class="side-nav-item has-submenu">'
    . '<button type="button" class="submenu-toggle' . ($manageOpen ? ' is-current' : '') . '" aria-expanded="' . ($manageOpen ? 'true' : 'false') . '" aria-controls="manageSubmenu" title="Manage Events">'
    . '<span class="icon">' . icon('tools') . '</span><span class="nav-label">Manage Events</span>'
    . '<span class="chevron" aria-hidden="true">' . icon('chevron-down', 14) . '</span></button>'
    . '<div class="event-submenu" id="manageSubmenu"' . ($manageOpen ? '' : ' hidden') . '>'
    . $navLink('/admin/event-organizer.php', 'manage-create', 'register', 'Create Event', 16)
    . $navLink('/admin/event-organizer.php?view=update', 'manage-update', 'list', 'Update Events', 16)
    . '</div></div>';
$approvalsOpen = in_array($activeNav, ['admin-approvals-events', 'admin-approvals-profiles'], true);
/** "Approvals" dropdown (staff): Event approvals, Profile edits — each with what's waiting, and the total on the button. */
$approvalsMenu = function () use ($approvalsOpen, $navLink): string {
    $waiting = approvals_waiting_counts();
    $total = array_sum($waiting);
    return '<div class="side-nav-item has-submenu">'
        . '<button type="button" class="submenu-toggle' . ($approvalsOpen ? ' is-current' : '') . '" aria-expanded="' . ($approvalsOpen ? 'true' : 'false') . '" aria-controls="approvalsSubmenu" title="Approvals' . ($total ? ' (' . $total . ' waiting)' : '') . '">'
        . '<span class="icon">' . icon('check') . '</span><span class="nav-label">Approvals</span>'
        . ($total ? '<span class="nav-count" aria-label="' . $total . ' waiting">' . ($total > 99 ? '99+' : $total) . '</span>' : '')
        . '<span class="chevron" aria-hidden="true">' . icon('chevron-down', 14) . '</span></button>'
        . '<div class="event-submenu" id="approvalsSubmenu"' . ($approvalsOpen ? '' : ' hidden') . '>'
        . $navLink('/admin/users.php?tab=approvals&amp;kind=events', 'admin-approvals-events', 'ticket', 'Event approvals', 16, $waiting['events'])
        . $navLink('/admin/users.php?tab=approvals&amp;kind=profiles', 'admin-approvals-profiles', 'profile', 'Profile edits', 16, $waiting['profiles'])
        . '</div></div>';
};
$categoriesOpen = in_array($activeNav, ['admin-categories', 'admin-departments', 'admin-organizations', 'admin-venues'], true);
$usersOpen = in_array($activeNav, ['admin-users', 'admin-roles'], true); // "Users & Roles" dropdown (Super Admins)
?>
<?php /* Scripts are on: event posters below wait until they scroll near (assets/js/ui.js);
         without scripts style.css shows them straight away. */ ?>
<script>document.documentElement.classList.add("js-lazy");</script>
<?php /* .neu: the home page's dark-blue neumorphic sidebar on every page (style.css). */ ?>
<aside class="sidebar neu" id="sidebar" aria-label="Site menu">
  <div class="sidebar-top">
    <a href="<?= BASE_URL ?>/index.php" class="brand">
      <img src="<?= htmlspecialchars(school_logo_url(true)) ?>" alt="Campus Event Hub logo" class="brand-logo" width="44" height="44">
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
    <nav class="side-nav side-admin" aria-label="Admin">
      <div class="side-group-label">Admin</div>
      <?= $manageMenu() ?>
      <?= $navLink('/admin/import.php', 'import', 'import', 'Import &amp; Verify') ?>
      <?php if (!empty($navUser['organization_id'])): ?>
        <?= $navLink('/admin/organization-officers.php?org=' . urlencode($navUser['organization_id']), 'my-officers', 'people', 'Our Officers') ?>
        <?= $approvalsMenu() ?>
        <?= $navLink('/admin/evaluation-form.php', 'admin-evaluation', 'evaluation', 'Evaluation Form') ?>
      <?php endif; ?>
    </nav>
  <?php elseif ($navRole === 'admin'): ?>
    <nav class="side-nav side-admin" aria-label="Super Admin">
      <div class="side-group-label">Super Admin</div>
      <?= $navLink('/admin/dashboard.php', 'admin-dashboard', 'chart', 'Admin Dashboard') ?>
      <?= $manageMenu() ?>
      <?= $navLink('/admin/reports.php', 'admin-reports', 'reports', 'Reports') ?>
      <?= $navLink('/admin/import.php', 'import', 'import', 'Import &amp; Verify') ?>
      <?= $navLink('/admin/evaluation-form.php', 'admin-evaluation', 'evaluation', 'Evaluation Form') ?>
      <?= $navLink('/admin/school-calendar.php', 'admin-calendar', 'schedule', 'School Calendar') ?>
      <div class="side-nav-item has-submenu">
        <button type="button" class="submenu-toggle<?= $categoriesOpen ? ' is-current' : '' ?>" aria-expanded="<?= $categoriesOpen ? 'true' : 'false' ?>" aria-controls="categoriesSubmenu" title="Categories">
          <span class="icon"><?= icon('tag') ?></span>
          <span class="nav-label">Categories</span>
          <span class="chevron" aria-hidden="true"><?= icon('chevron-down', 14) ?></span>
        </button>
        <div class="event-submenu" id="categoriesSubmenu"<?= $categoriesOpen ? '' : ' hidden' ?>>
          <?= $navLink('/admin/admin-taxonomy.php?tab=categories', 'admin-categories', 'list', 'Event Categories', 16) ?>
          <?= $navLink('/admin/admin-taxonomy.php?tab=departments', 'admin-departments', 'campus', 'Departments', 16) ?>
          <?= $navLink('/admin/admin-taxonomy.php?tab=organizations', 'admin-organizations', 'organizations', 'Organizations', 16) ?>
          <?= $navLink('/admin/admin-taxonomy.php?tab=venues', 'admin-venues', 'location', 'Venues', 16) ?>
        </div>
      </div>
      <div class="side-nav-item has-submenu">
        <button type="button" class="submenu-toggle<?= $usersOpen ? ' is-current' : '' ?>" aria-expanded="<?= $usersOpen ? 'true' : 'false' ?>" aria-controls="usersSubmenu" title="Users &amp; Roles">
          <span class="icon"><?= icon('people') ?></span>
          <span class="nav-label">Users &amp; Roles</span>
          <span class="chevron" aria-hidden="true"><?= icon('chevron-down', 14) ?></span>
        </button>
        <div class="event-submenu" id="usersSubmenu"<?= $usersOpen ? '' : ' hidden' ?>>
          <?= $navLink('/admin/users.php', 'admin-users', 'people', 'Users', 16) ?>
          <?= $navLink('/admin/users.php?tab=roles', 'admin-roles', 'certificate', 'Roles', 16) ?>
        </div>
      </div>
      <?= $approvalsMenu() ?>
    </nav>
  <?php endif; ?>

  <?php /* Shown by assets/js/app.js on phones / when the browser can install the site (computers: "Install as an app"). */ ?>
  <button type="button" class="side-install" data-install-app data-desktop-label="Install as an app" hidden>
    <img src="<?= BASE_URL ?>/assets/images/app/icon-192.png" alt="" width="22" height="22"> <span data-install-label>Install app on this phone</span>
  </button>
</aside>
<div class="nav-backdrop" data-nav-close hidden></div>
<?php /* The Event Hub Assistant chat box in the bottom-right corner — every page with this sidebar. */ ?>
<?php /* The site's confirmation pop-up (data-confirm on forms, cehConfirm() / cehAlert()) — every page with this sidebar. */ ?>
<script src="<?= BASE_URL ?>/assets/js/confirm.js?v=<?= filemtime(__DIR__ . '/../public/assets/js/confirm.js') ?>" defer></script>
<?php /* The event pop-up: links with data-event-modal show the event's details here instead of opening
         its page. Logged in only: visitors are asked to log in first, so their clicks open the page as before. */ ?>
<?php if ($navUser): ?>
<script src="<?= BASE_URL ?>/assets/js/event-modal.js?v=<?= filemtime(__DIR__ . '/../public/assets/js/event-modal.js') ?>"
        data-css="<?= BASE_URL ?>/assets/css/event-details.css?v=<?= filemtime(__DIR__ . '/../public/assets/css/event-details.css') ?>" defer></script>
<?php endif; ?>
<?php include __DIR__ . '/assistant-widget.php'; ?>
