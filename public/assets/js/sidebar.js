/**
 * Campus Event Hub — the sidebar (includes/nav.php).
 *
 * "Events" group: clicking it opens/closes its sub-links. It starts open on
 * pages that belong to it (All Events, My Events, Next Event).
 *
 * Phones / foldables (≤ 760px): the top bar's menu button ([data-nav-toggle],
 * includes/topbar-start.php) slides the sidebar in (body.nav-open). The ✕
 * button, the dark backdrop, Escape or following a link closes it again; it
 * also closes when the window grows to tablet size. (Tablets expand the slim
 * sidebar on hover instead — CSS only, style.css.)
 */
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("eventDropdownBtn");
  const submenu = document.getElementById("eventSubmenu");
  if (btn && submenu) {
    btn.addEventListener("click", () => {
      const open = btn.getAttribute("aria-expanded") === "true";
      submenu.hidden = open;
      btn.setAttribute("aria-expanded", String(!open));
    });
  }

  const sidebar = document.getElementById("sidebar");
  const toggles = document.querySelectorAll("[data-nav-toggle]");
  const backdrop = document.querySelector(".nav-backdrop");
  if (!sidebar || !toggles.length) return;

  const isOpen = () => document.body.classList.contains("nav-open");

  function setOpen(open) {
    document.body.classList.toggle("nav-open", open);
    if (backdrop) backdrop.hidden = !open;
    toggles.forEach((t) => {
      t.setAttribute("aria-expanded", String(open));
      t.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    });
    if (open) {
      sidebar.querySelector(".nav-close")?.focus();
    } else if (sidebar.contains(document.activeElement)) {
      toggles[0].focus();
    }
  }

  toggles.forEach((t) => t.addEventListener("click", () => setOpen(!isOpen())));
  document.querySelectorAll("[data-nav-close]").forEach((el) => el.addEventListener("click", () => setOpen(false)));
  sidebar.addEventListener("click", (e) => {
    if (isOpen() && e.target.closest("a[href]")) setOpen(false);
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && isOpen()) setOpen(false);
  });
  // Same breakpoint as style.css: from 761px up the sidebar is always on screen.
  window.matchMedia("(min-width: 761px)").addEventListener("change", (e) => {
    if (e.matches && isOpen()) setOpen(false);
  });
});
