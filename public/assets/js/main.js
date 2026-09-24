/**
 * Campus Event Hub — front-end interactions.
 * Uses the `db` placeholder client from supabase-client.js wherever it
 * would eventually talk to Supabase.
 */

document.addEventListener("DOMContentLoaded", () => {
  buildCalendar();
  wireEventCardClicks();
  wireSidebarToggle();
  wireEventDropdown();
});

/* ---------------- Sidebar: collapsed by default, expands on press ---------------- */
function wireSidebarToggle() {
  const shell = document.querySelector(".dashboard-shell");
  const toggleBtn = document.getElementById("sidebarToggle");
  if (!shell || !toggleBtn) {
    // Not necessarily an error — some pages may not use the dashboard
    // shell — but flag it during development in case markup drifted.
    console.warn(
      "[sidebar] .dashboard-shell or #sidebarToggle not found; sidebar toggle is inactive on this page."
    );
    return;
  }

  const STORAGE_KEY = "sidebarExpanded";

  const setExpanded = (expanded) => {
    shell.classList.toggle("sidebar-expanded", expanded);
    toggleBtn.setAttribute("aria-expanded", String(expanded));
    try {
      localStorage.setItem(STORAGE_KEY, expanded ? "1" : "0");
    } catch (e) {
      /* localStorage unavailable (e.g. private browsing) — state just won't persist */
    }
  };

  // Remembers the user's last choice; defaults to collapsed (icon rail only).
  let initiallyExpanded = false;
  try {
    initiallyExpanded = localStorage.getItem(STORAGE_KEY) === "1";
  } catch (e) {
    /* ignore */
  }
  setExpanded(initiallyExpanded);

  toggleBtn.addEventListener("click", () => {
    const isExpanded = shell.classList.contains("sidebar-expanded");
    setExpanded(!isExpanded);
    // Collapsing the sidebar should also close any open submenu inside it.
    if (isExpanded) closeEventSubmenu();
  });

  // Exposed so wireEventDropdown() can force-expand the sidebar when needed.
  window.__expandSidebar = () => setExpanded(true);
}

/* ---------------- Event dropdown (sidebar submenu) ---------------- */
function closeEventSubmenu() {
  const btn = document.getElementById("eventDropdownBtn");
  const submenu = document.getElementById("eventSubmenu");
  if (!btn || !submenu) return;
  submenu.hidden = true;
  btn.setAttribute("aria-expanded", "false");
}

function wireEventDropdown() {
  const btn = document.getElementById("eventDropdownBtn");
  const submenu = document.getElementById("eventSubmenu");
  if (!btn || !submenu) {
    console.warn(
      "[event-dropdown] #eventDropdownBtn or #eventSubmenu not found; Event dropdown is inactive on this page."
    );
    return;
  }

  btn.addEventListener("click", () => {
    const isOpen = btn.getAttribute("aria-expanded") === "true";

    // If the sidebar is collapsed (icon rail), pressing the dropdown
    // expands it first so the submenu labels are actually visible.
    if (!isOpen && typeof window.__expandSidebar === "function") {
      window.__expandSidebar();
    }

    submenu.hidden = isOpen;
    btn.setAttribute("aria-expanded", String(!isOpen));
  });

  document.addEventListener("click", (e) => {
    if (
      !submenu.hidden &&
      !submenu.contains(e.target) &&
      !btn.contains(e.target)
    ) {
      closeEventSubmenu();
    }
  });
}

/* ---------------- Calendar (right rail on the dashboard) ---------------- */
function buildCalendar() {
  const grid = document.getElementById("calendar-grid");
  if (!grid) return; // not on the dashboard page

  const dows = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

  // Matches the screenshot: May 2026, starting on a Sunday, with a few
  // marker dots on scattered days. Replace with real event dates once
  // events are pulled from Supabase (see db.getEvents()).
  const weeks = [
    [28, 29, 30, 1, 2, 3, 4],
    [5, 6, 7, 8, 9, 10, 11],
    [12, 13, 14, 15, 16, 17, 18],
    [19, 20, 21, 22, 23, 24, 25],
    [26, 27, 28, 29, 30, 31, 1],
  ];
  const mutedDays = new Set([28, 29, 30, 31, 1]); // days belonging to adjacent months
  const markers = {
    "3-4": "marker-blue",
    "3-5": "marker-purple",
    "4-1": "marker-orange",
    "4-2": "marker-red",
    "4-3": "marker-purple",
  };

  let html = "";
  dows.forEach((d) => (html += `<div class="dow">${d}</div>`));

  weeks.forEach((week, wi) => {
    week.forEach((day, di) => {
      const isMuted =
        (wi === 0 && day > 20) || (wi === weeks.length - 1 && day < 20);
      const markerClass = markers[`${wi}-${di}`];
      html += `<div class="day${isMuted ? " muted" : ""}">${day}${
        markerClass ? `<span class="marker ${markerClass}"></span>` : ""
      }</div>`;
    });
  });

  grid.innerHTML = html;
}

/* ---------------- Event card clicks -> Event Details page ---------------- */
function wireEventCardClicks() {
  document.querySelectorAll(".event-card[data-event-id]").forEach((card) => {
    card.style.cursor = "pointer";
    card.addEventListener("click", () => {
      const eventId = card.dataset.eventId;
      window.location.href = `event-details.php?id=${encodeURIComponent(eventId)}`;
    });
  });
}