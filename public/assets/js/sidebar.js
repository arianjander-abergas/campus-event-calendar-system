/**
 * Campus Event Hub — sidebar "Events" group (includes/nav.php).
 * Clicking "Events" opens/closes its sub-links. It starts open on pages
 * that belong to it (All Events, My Events, Next Event).
 */
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("eventDropdownBtn");
  const submenu = document.getElementById("eventSubmenu");
  if (!btn || !submenu) return;

  btn.addEventListener("click", () => {
    const open = btn.getAttribute("aria-expanded") === "true";
    submenu.hidden = open;
    btn.setAttribute("aria-expanded", String(!open));
  });
});
