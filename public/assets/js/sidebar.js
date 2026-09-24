/**
 * Campus Event Hub — sidebar "Event" dropdown.
 * Loaded on every page that has the dashboard sidebar
 * (enduser/dashboard.php, event-details.php, admin/*.php).
 *
 * Expects this markup:
 *   <button id="eventDropdownBtn" aria-expanded="false" aria-controls="eventSubmenu">…</button>
 *   <div id="eventSubmenu" hidden>…links…</div>
 */

function closeEventSubmenu() {
  const btn = document.getElementById("eventDropdownBtn");
  const submenu = document.getElementById("eventSubmenu");
  if (!btn || !submenu) return;
  submenu.hidden = true;
  btn.setAttribute("aria-expanded", "false");
}

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("eventDropdownBtn");
  const submenu = document.getElementById("eventSubmenu");
  if (!btn || !submenu) return; // page has no Event dropdown

  // Open/close when the Event button is pressed.
  btn.addEventListener("click", () => {
    const isOpen = btn.getAttribute("aria-expanded") === "true";

    // If the sidebar is collapsed (icon rail), expand it first so the
    // submenu labels are actually visible. (No-op when the sidebar is pinned.)
    if (!isOpen && typeof window.__expandSidebar === "function") {
      window.__expandSidebar();
    }

    submenu.hidden = isOpen;
    btn.setAttribute("aria-expanded", String(!isOpen));
  });

  // Close when clicking anywhere outside the dropdown…
  document.addEventListener("click", (e) => {
    if (!submenu.hidden && !submenu.contains(e.target) && !btn.contains(e.target)) {
      closeEventSubmenu();
    }
  });

  // …or when pressing Escape.
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !submenu.hidden) {
      closeEventSubmenu();
      btn.focus();
    }
  });
});
