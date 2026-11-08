<?php
/**
 * Appearance switcher — Light / Dim / Dark — next to the notification bell
 * (includes/topbar.php, event-details.php). The choice is saved in the
 * "theme" cookie for a year; PHP puts it on <html data-theme> (theme_attr())
 * so pages open in the chosen look without a flash. Styles: the
 * "Dim & dark themes" section at the end of style.css.
 */
$themeNow = current_theme();
$themeIcons = [
    'light' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'dim'   => '<circle cx="12" cy="12" r="9"/><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"/>',
    'dark'  => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
];
$themeSvg = fn(string $t, int $size) => '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-theme-icon="' . $t . '">' . $themeIcons[$t] . '</svg>';
?>
<details class="theme-menu" data-theme-menu>
  <summary class="theme-btn" aria-label="Appearance: <?= THEMES[$themeNow] ?>" title="Appearance">
    <?php foreach (THEMES as $t => $label): ?>
      <span class="theme-btn-icon" data-theme-show="<?= $t ?>"<?= $t === $themeNow ? '' : ' hidden' ?>><?= $themeSvg($t, 18) ?></span>
    <?php endforeach; ?>
  </summary>
  <div class="theme-dropdown" role="group" aria-label="Appearance">
    <div class="theme-dropdown-head">Appearance</div>
    <?php foreach (THEMES as $t => $label): ?>
      <button type="button" class="theme-option" data-theme-choice="<?= $t ?>" aria-pressed="<?= $t === $themeNow ? 'true' : 'false' ?>">
        <?= $themeSvg($t, 16) ?><span><?= $label ?></span><span class="theme-check" aria-hidden="true">✓</span>
      </button>
    <?php endforeach; ?>
  </div>
</details>
<script>
(function () {
  var menu = document.currentScript.previousElementSibling;
  var label = { light: 'Light', dim: 'Dim', dark: 'Dark' };
  menu.querySelectorAll('[data-theme-choice]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var theme = btn.getAttribute('data-theme-choice');
      if (theme === 'light') document.documentElement.removeAttribute('data-theme');
      else document.documentElement.setAttribute('data-theme', theme);
      document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
      menu.querySelectorAll('[data-theme-choice]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
      menu.querySelectorAll('[data-theme-show]').forEach(function (s) { s.hidden = s.getAttribute('data-theme-show') !== theme; });
      menu.querySelector('summary').setAttribute('aria-label', 'Appearance: ' + label[theme]);
      menu.open = false;
    });
  });
  // A click outside or Escape closes it.
  document.addEventListener('click', function (e) { if (menu.open && !menu.contains(e.target)) menu.open = false; });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.open) { menu.open = false; menu.querySelector('summary').focus(); }
  });
})();
</script>
