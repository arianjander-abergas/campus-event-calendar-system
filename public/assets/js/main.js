/**
 * Campus Event Hub — home page + dashboard behaviour:
 * the dashboard calendar, the "Upcoming events" carousel and the live
 * home-page stats. Each part does nothing on pages that don't have it.
 */
document.addEventListener("DOMContentLoaded", () => {
  buildCalendar();
  wireCarousel();
  wireLiveStats();
});

/* ---------------- Calendar (dashboard right rail) ---------------- */
// Real current month; ‹ › change month. Days with a published or completed
// event (window.CALENDAR_EVENTS from enduser/dashboard.php) get a dot;
// clicking a day lists ALL of that day's events below the calendar, with a
// link to the day on the full Event Calendar.
function buildCalendar() {
  const grid = document.getElementById("calendar-grid");
  if (!grid) return;

  const label = document.getElementById("calendar-month");
  const list = document.getElementById("calendar-day-list");
  const events = window.CALENDAR_EVENTS || [];
  const eventUrl = grid.dataset.eventUrl || "";
  const calendarUrl = grid.dataset.calendarUrl || "";
  const today = new Date();
  let year = today.getFullYear();
  let month = today.getMonth(); // 0-11
  let selected = null;

  const pad = (n) => String(n).padStart(2, "0");
  const iso = (y, m, d) => `${y}-${pad(m + 1)}-${pad(d)}`;
  const eventsOn = (date) => events.filter((e) => e.start <= date && date <= (e.end || e.start));
  const esc = (s) => String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");

  function showDay(date) {
    selected = date;
    grid.querySelectorAll(".day.selected").forEach((d) => d.classList.remove("selected"));
    grid.querySelector(`[data-date="${date}"]`)?.classList.add("selected");
    if (!list) return;
    const [y, m, d] = date.split("-").map(Number);
    const title = new Date(y, m - 1, d).toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
    const onDay = eventsOn(date);
    const shown = 6; // the full list is one click away on the Event Calendar
    const more = onDay.length - shown;
    list.innerHTML = `<h4>${esc(title)} · ${onDay.length} event${onDay.length === 1 ? "" : "s"}</h4>`
      + (onDay.length
        ? onDay.slice(0, shown).map((e) => `<a href="${esc(eventUrl + encodeURIComponent(e.id))}" data-event-modal><strong>${esc(e.title)}</strong>`
            + `<span>${esc([e.time || "All day", e.place].filter(Boolean).join(" · "))}${e.status === "completed" ? " · Completed" : ""}</span></a>`).join("")
        : "<p>No events on this date.</p>")
      + (calendarUrl ? `<a class="cal-open-link" href="${esc(calendarUrl + "?date=" + date)}">${more > 0 ? "See all " + onDay.length + " in the Event Calendar ›" : "Open this day in the Event Calendar ›"}</a>` : "");
  }

  function render() {
    if (label) {
      label.textContent = new Date(year, month, 1).toLocaleString("en-US", { month: "long", year: "numeric" });
    }
    let html = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"].map((d) => `<div class="dow">${d}</div>`).join("");

    const firstDow = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrev = new Date(year, month, 0).getDate();
    const totalCells = Math.ceil((firstDow + daysInMonth) / 7) * 7;
    const todayIso = iso(today.getFullYear(), today.getMonth(), today.getDate());

    for (let i = 0; i < totalCells; i++) {
      const day = i - firstDow + 1;
      if (day < 1 || day > daysInMonth) {
        html += `<div class="day muted">${day < 1 ? daysInPrev + day : day - daysInMonth}</div>`;
        continue;
      }
      const date = iso(year, month, day);
      const onDay = eventsOn(date);
      const cls = `day${date === todayIso ? " today" : ""}${onDay.length ? " has-event" : ""}${date === selected ? " selected" : ""}`;
      const tip = onDay.length ? onDay.map((e) => e.title).join("\n") : "No events";
      html += `<button type="button" class="${cls}" data-date="${date}" title="${esc(tip)}" aria-label="${esc(date + ": " + onDay.length + " event" + (onDay.length === 1 ? "" : "s"))}">${day}${onDay.length ? '<span class="marker marker-blue"></span>' : ""}</button>`;
    }
    grid.innerHTML = html;
  }

  function step(delta) {
    month += delta;
    if (month < 0) { month = 11; year--; }
    if (month > 11) { month = 0; year++; }
    render();
  }
  grid.addEventListener("click", (e) => {
    const day = e.target.closest("button.day");
    if (day) showDay(day.dataset.date);
  });
  document.getElementById("calPrev")?.addEventListener("click", () => step(-1));
  document.getElementById("calNext")?.addEventListener("click", () => step(1));
  render();
  // Start with today's events listed.
  showDay(iso(today.getFullYear(), today.getMonth(), today.getDate()));
}

/* ---------------- Carousel (dashboard "Upcoming events") ---------------- */
// [data-carousel] shows data-per-page cards at a time; the dots in the
// element named by data-carousel switch pages. No dots when one page fits all.
function wireCarousel() {
  document.querySelectorAll("[data-carousel]").forEach((track) => {
    const cards = Array.from(track.children).filter((el) => el.classList.contains("event-card"));
    const perPage = Number(track.dataset.perPage) || 3;
    const pages = Math.ceil(cards.length / perPage);
    const dots = document.querySelector(track.dataset.carousel);
    if (!dots) return;
    if (pages <= 1) {
      dots.hidden = true;
      return;
    }

    function show(page) {
      cards.forEach((card, i) => { card.hidden = Math.floor(i / perPage) !== page; });
      dots.querySelectorAll("button").forEach((b, i) => {
        b.classList.toggle("active", i === page);
        b.setAttribute("aria-current", i === page ? "true" : "false");
      });
    }

    dots.innerHTML = "";
    for (let p = 0; p < pages; p++) {
      const b = document.createElement("button");
      b.type = "button";
      b.setAttribute("aria-label", `Show events ${p * perPage + 1}–${Math.min((p + 1) * perPage, cards.length)}`);
      b.addEventListener("click", () => show(p));
      dots.appendChild(b);
    }
    show(0);
  });
}

/* ---------------- Home page stats: refresh live every 30s ---------------- */
// Numbers come from api/stats.php (the site_stats() database function);
// Active Users goes up/down as logged-in people open or close the site.
// A stat that is 0 is hidden ("0 Active Users" puts people off) and comes
// back as soon as it goes up.
function wireLiveStats() {
  const block = document.querySelector("[data-stats-url]");
  if (!block) return;

  async function refresh() {
    try {
      const res = await fetch(block.dataset.statsUrl, { cache: "no-store" });
      if (!res.ok) return;
      const stats = await res.json();
      block.querySelectorAll("[data-stat]").forEach((el) => {
        const value = stats[el.dataset.stat];
        if (value === undefined) return;
        el.textContent = value;
        el.closest(".stat").hidden = String(value) === "0";
      });
    } catch (e) {
      /* offline — keep the last numbers */
    }
  }
  setInterval(refresh, 30000);
}
