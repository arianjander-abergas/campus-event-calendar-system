/**
 * Campus Event Hub — front-end interactions.
 * Uses the `db` placeholder client from supabase-client.js wherever it
 * would eventually talk to Supabase.
 */

// This file lives in public/assets/js/, so "../../" is the public/ folder.
// Used to build page links that work from both index.php and enduser/dashboard.php.
const SITE_ROOT = new URL("../../", document.currentScript.src).href;

document.addEventListener("DOMContentLoaded", () => {
  buildCalendar();
  wireEventCardClicks();
  wireSidebarToggle();
  wireLiveStats();
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

  // Exposed so the Event dropdown (sidebar.js) can force-expand the sidebar when needed.
  window.__expandSidebar = () => setExpanded(true);
}

/* ---------------- Calendar (right rail on the dashboard) ---------------- */
// Shows the real current month; ‹ › change month. Days with a published
// event (window.CALENDAR_EVENTS, from Supabase via enduser/dashboard.php)
// get a dot, and hovering the day lists the event titles.
function buildCalendar() {
  const grid = document.getElementById("calendar-grid");
  if (!grid) return; // not on the dashboard page

  const label = document.getElementById("calendar-month");
  const events = window.CALENDAR_EVENTS || [];
  const today = new Date();
  let year = today.getFullYear();
  let month = today.getMonth(); // 0-11

  const pad = (n) => String(n).padStart(2, "0");
  const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
  // Titles of events running on a date (start <= date <= end).
  const eventsOn = (date) =>
    events.filter((e) => e.start <= date && date <= (e.end || e.start)).map((e) => e.title);

  const render = () => {
    if (label) {
      label.textContent = new Date(year, month, 1).toLocaleString("en-US", { month: "long", year: "numeric" });
    }

    let html = "";
    ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].forEach((d) => (html += `<div class="dow">${d}</div>`));

    const firstDow = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrev = new Date(year, month, 0).getDate();
    const totalCells = Math.ceil((firstDow + daysInMonth) / 7) * 7;

    for (let i = 0; i < totalCells; i++) {
      const day = i - firstDow + 1;
      if (day < 1 || day > daysInMonth) {
        // Leading/trailing days from the neighbouring months.
        const shown = day < 1 ? daysInPrev + day : day - daysInMonth;
        html += `<div class="day muted">${shown}</div>`;
        continue;
      }
      const date = iso(year, month, day);
      const titles = eventsOn(date);
      const isToday = date === iso(today.getFullYear(), today.getMonth(), today.getDate());
      html += `<div class="day${isToday ? " today" : ""}"${titles.length ? ` title="${escapeAttr(titles.join("\n"))}"` : ""}>${day}${
        titles.length ? '<span class="marker marker-blue"></span>' : ""
      }</div>`;
    }
    grid.innerHTML = html;
  };

  const step = (delta) => {
    month += delta;
    if (month < 0) { month = 11; year--; }
    if (month > 11) { month = 0; year++; }
    render();
  };
  document.getElementById("calPrev")?.addEventListener("click", () => step(-1));
  document.getElementById("calNext")?.addEventListener("click", () => step(1));

  render();
}

function escapeAttr(str) {
  return String(str).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
}

/* ---------------- Event card clicks -> Event Details page ---------------- */
function wireEventCardClicks() {
  document.querySelectorAll(".event-card[data-event-id]").forEach((card) => {
    card.style.cursor = "pointer";
    card.addEventListener("click", () => {
      const eventId = card.dataset.eventId;
      window.location.href = `${SITE_ROOT}event-details.php?id=${encodeURIComponent(eventId)}`;
    });
  });
}
/* ---------------- Home page stats: refresh live every 30s ---------------- */
// The numbers come from the site_stats() database function; Active Users
// goes up/down as logged-in people open or close the site.
function wireLiveStats() {
  const block = document.querySelector(".stats-block");
  if (!block || !window.db) return; // not on the home page

  const refresh = async () => {
    const stats = await window.db.getSiteStats();
    if (!stats) return;
    block.querySelectorAll("[data-stat]").forEach((el) => {
      const value = stats[el.dataset.stat];
      if (value !== undefined) el.textContent = formatStatCount(value);
    });
  };
  setInterval(refresh, 30000);
}

// 950 -> "950", 1234 -> "1.2k", 3400000 -> "3.4M" (same rule as format_stat_count() in PHP).
function formatStatCount(n) {
  n = Number(n) || 0;
  if (n < 1000) return String(n);
  const short = (v) => v.toFixed(1).replace(/\.0$/, "");
  return n < 1000000 ? short(n / 1000) + "k" : short(n / 1000000) + "M";
}
