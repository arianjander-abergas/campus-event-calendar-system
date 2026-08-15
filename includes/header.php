<?php
// This partial is included from pages that have already required
// config/supabase.php, so current_user()/session_start_once() exist.
// Guard just in case a page includes header.php on its own.
if (!function_exists('current_user')) {
    require_once __DIR__ . '/../config/supabase.php';
}
session_start_once();
$__headerUser = current_user();
?>
<header class="site-header">
  <a href="index.php" class="brand">
    <span class="brand-icon">📅</span>
    <span class="brand-text">
      <span class="campus">CAMPUS</span>
      <span class="hub">EVENT HUB</span>
    </span>
  </a>

  <nav class="main-nav">
    <a href="index.php">Home</a>
    <a href="dashboard.php">Dashboard</a>
    <a href="#organizations">Organizations</a>
    <a href="#about">About Us</a>
  </nav>

  <div class="header-right">
    <div class="search-box">
      <span>🔍</span>
      <input type="text" placeholder="Search for events, organizations, or keywords">
    </div>
    <?php if ($__headerUser): ?>
      <div class="user-chip">
        <span class="user-name"><?= htmlspecialchars($__headerUser['full_name']) ?></span>
        <a href="logout.php" class="btn-login" style="background:#6b7280;">LOG OUT</a>
      </div>
    <?php else: ?>
      <a href="login.php" class="btn-login">LOGIN</a>
    <?php endif; ?>
  </div>
</header>
