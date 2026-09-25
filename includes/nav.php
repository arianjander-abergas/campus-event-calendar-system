
<aside class="sidebar">
    <div class="sidebar-top">
      <a href="<?= BASE_URL ?>/index.php" class="brand">
        <span class="brand-icon">📅</span>
        <span class="brand-text nav-label">
          <span class="campus">CAMPUS</span>
          <span class="hub">EVENT HUB</span>
        </span>
      </a>
      <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar" aria-expanded="false">☰</button>
    </div>

    <nav class="side-nav">
      <a href="<?= BASE_URL ?>/index.php"><span class="icon">🏠</span> <span class="nav-label">Home</span></a>
      <a href="<?= BASE_URL ?>/enduser/dashboard.php" class="active"><span class="icon">🗂️</span> <span class="nav-label">Dashboard</span></a>

      <div class="side-nav-item has-submenu">
        <button type="button" class="submenu-toggle" id="eventDropdownBtn" aria-expanded="false" aria-controls="eventSubmenu">
          <span class="icon">🎟️</span>
          <span class="nav-label">Event</span>
          <span class="chevron" aria-hidden="true">⌄</span>
        </button>
        <div class="event-submenu" id="eventSubmenu" hidden>
          <a href="<?= BASE_URL ?>/event-details.php" class="calendar-submenu-link">
            <span class="icon">📅</span> Calendar
          </a>
        </div>
      </div>
    </nav>

    <?php if (($user['role'] ?? 'user') === 'admin'): ?>
      <div style="margin-top:auto;">
        <a href="<?= BASE_URL ?>/admin/event-organizer.php" class="admin-view-link">
          <span class="icon">🛠️</span> <span class="nav-label">Admin View</span>
        </a>
      </div>
    <?php endif; ?>
    <!--new nav -->
  </aside>
  
  