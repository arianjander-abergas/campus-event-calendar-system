<?php
/**
 * Top bar for every page with the sidebar: breadcrumbs (or a search box),
 * the notification bell and the user menu — or Log in / Sign up for
 * visitors who aren't logged in. Set before including:
 *   $breadcrumbs  = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['Events', null]]
 *   $topbarSearch = true   // show the event search box instead of breadcrumbs
 *   $notifications         // optional, loaded here if not set
 */
$topUser = current_user();
$notifications = $topUser ? ($notifications ?? get_my_notifications()) : [];
$unreadCount = unread_count($notifications);
?>
<header class="dash-topbar">
  <?php include __DIR__ . '/topbar-start.php'; ?>
  <?php if (!empty($topbarSearch)): ?>
    <form class="search-box" action="<?= BASE_URL ?>/events.php" method="get" role="search">
      <?= icon('search', 16) ?>
      <input type="search" name="q" value="<?= htmlspecialchars(basename($_SERVER['SCRIPT_NAME'] ?? '') === 'events.php' ? get_param('q') : '') ?>" placeholder="Search for events, organizations, or keywords" aria-label="Search events">
    </form>
  <?php else: ?>
    <nav class="breadcrumb" aria-label="Breadcrumb">
      <?php foreach (($breadcrumbs ?? []) as $i => [$label, $href]): ?>
        <?php if ($i > 0): ?><span class="crumb-sep" aria-hidden="true">›</span><?php endif; ?>
        <?php if ($href): ?>
          <a href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
        <?php else: ?>
          <span class="crumb-current" aria-current="page"><?= htmlspecialchars($label) ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="topbar-right">
    <?php if (!$topUser): ?>
      <a href="<?= BASE_URL ?>/auth/login.php" class="btn-login">LOG IN</a>
      <a href="<?= BASE_URL ?>/auth/register.php" class="btn btn-outline topbar-signup">Sign up</a>
    <?php else: ?>
    <div class="notif" data-notifications>
      <button class="bell" type="button" data-notif-btn aria-label="Notifications" aria-expanded="false">
        <?= icon('bell', 20) ?><span class="dot" data-notif-count<?= $unreadCount ? '' : ' hidden' ?>><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
      </button>
      <div class="notif-dropdown" data-notif-dropdown hidden>
        <div class="notif-head">Notifications</div>
        <div class="notif-list" data-notif-list><div class="notif-empty">No notifications yet.</div></div>
      </div>
    </div>

    <details class="user-menu">
      <summary class="user-chip">
        <span class="avatar"><?= htmlspecialchars(initials($topUser['full_name'] ?? '')) ?></span>
        <span class="user-text">
          <span class="user-name"><?= htmlspecialchars($topUser['full_name'] ?? '') ?></span>
          <span class="user-role"><?= htmlspecialchars(role_display($topUser['role'] ?? 'user', $topUser['account_type'] ?? null)) ?></span>
        </span>
        <span class="user-caret" aria-hidden="true"><?= icon('chevron-down', 12) ?></span>
      </summary>
      <div class="user-dropdown">
        <a href="<?= BASE_URL ?>/enduser/profile.php">My Profile</a>
        <a href="<?= BASE_URL ?>/events.php?mine=1">My Events</a>
        <a href="<?= BASE_URL ?>/auth/logout.php">Log out</a>
      </div>
    </details>
    <?php endif; ?>
  </div>
</header>
<?php if ($topUser): ?>
<?= notifications_bootstrap($notifications) ?>
<?php endif; ?>
