/**
 * Campus Event Hub — front-end interactions.
 * Uses the `db` placeholder client from supabase-client.js wherever it
 * would eventually talk to Supabase.
 */

document.addEventListener("DOMContentLoaded", () => {
  buildCalendar();
  wireLoginButton();
  wireEventCardClicks();
});

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

/* ---------------- Login button (placeholder auth flow) ---------------- */
function wireLoginButton() {
  const loginBtn = document.querySelector(".btn-login");
  if (!loginBtn) return;

  loginBtn.addEventListener("click", async () => {
    // Placeholder: swap this for a real login modal / page, then call
    // db.signIn(email, password) once Supabase Auth is configured.
    console.info("[Auth] Login clicked — wire this up to db.signIn(email, password).");
    window.location.href = "dashboard.php";
  });
}

/* ---------------- Event card clicks (placeholder registration) ---------------- */
function wireEventCardClicks() {
  document.querySelectorAll(".event-card").forEach((card) => {
    card.addEventListener("click", async () => {
      const title = card.querySelector(".event-name")?.textContent ?? "this event";
      // Placeholder: once real event IDs + a logged-in user are available,
      // call db.registerForEvent(eventId, userId) here.
      console.info(`[Events] Clicked "${title}" — hook up db.registerForEvent() here.`);
    });
  });
}
