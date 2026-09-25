<?php
/**
 * Top bar for dashboard-style pages: breadcrumbs (or a search box), the
 * notification bell and the user menu. Set before including:
 *   $breadcrumbs  = [['Dashboard', BASE_URL . '/enduser/dashboard.php'], ['Events', null]]
 *   $topbarSearch = true   // show the event search box instead of breadcrumbs
 *   $notifications         // optional, loaded here if not set
 */
$topUser = current_user();
$notifications = $notifications ?? get_my_notifications();
$unreadCount = unread_count($notifications);
?>
<header class="dash-topbar">
  <?php if (!empty($topbarSearch)): ?>
    <form class="search-box" action="<?= BASE_URL ?>/events.php" method="get" role="search">
      <span aria-hidden="true">🔍</span>
      <input type="search" name="q" placeholder="Search for events, organizations, or keywords" aria-label="Search events">
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
    <div class="notif" data-notifications>
      <button class="bell" type="button" data-notif-btn aria-label="Notifications" aria-expanded="false">
        🔔<span class="dot" data-notif-count<?= $unreadCount ? '' : ' hidden' ?>><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
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
          <span class="user-role"><?= htmlspecialchars(role_display($topUser['role'] ?? 'user')) ?></span>
        </span>
        <span class="user-caret" aria-hidden="true">▾</span>
      </summary>
      <div class="user-dropdown">
        <a href="<?= BASE_URL ?>/enduser/profile.php">My Profile</a>
        <a href="<?= BASE_URL ?>/events.php?mine=1">My Events</a>
        <a href="<?= BASE_URL ?>/auth/logout.php">Log out</a>
      </div>
    </details>
  </div>
</header>
<?= notifications_bootstrap($notifications) ?>
