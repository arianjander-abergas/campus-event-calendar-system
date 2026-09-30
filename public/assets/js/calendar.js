/**
 * Campus Event Hub — Event Calendar (events.php).
 * Clicking a date shows every event on that date in the panel beside the
 * calendar, without reloading the page. Each day's list is already on the
 * page (one hidden <section id="day-YYYY-MM-DD"> per day with events); this
 * only switches which one is visible. Without JavaScript the date links
 * reload the page with ?date=... and the server shows the same list.
 */
document.addEventListener("DOMContentLoaded", () => {
  const table = document.querySelector("[data-calendar]");
  if (!table) return;

  const panel = document.querySelector(".cal-day-panel");
  const empty = document.getElementById("day-empty");
  const emptyTitle = empty?.querySelector("[data-empty-date]");

  function select(link) {
    const date = link.dataset.date;
    table.querySelectorAll(".cal-cell.is-selected").forEach((c) => c.classList.remove("is-selected"));
    table.querySelectorAll("[aria-current='date']").forEach((a) => a.removeAttribute("aria-current"));
    link.closest(".cal-cell").classList.add("is-selected");
    link.setAttribute("aria-current", "date");

    panel.querySelectorAll(".cal-day-events").forEach((s) => { s.hidden = true; });
    const day = document.getElementById("day-" + date);
    if (day) {
      day.hidden = false;
    } else if (empty) {
      if (emptyTitle) {
        const [y, m, d] = date.split("-").map(Number);
        emptyTitle.textContent = new Date(y, m - 1, d).toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric", year: "numeric" });
      }
      empty.hidden = false;
    }
    // On narrow screens the list is below the calendar: bring it into view.
    if (window.matchMedia("(max-width: 1100px)").matches) {
      panel.scrollIntoView({ behavior: "smooth", block: "start" });
    }
    history.replaceState(null, "", link.href);
  }

  table.addEventListener("click", (e) => {
    const link = e.target.closest("a.cal-day");
    if (!link || e.ctrlKey || e.metaKey || e.shiftKey) return;
    // Days of the next / previous month load that month.
    if (link.closest(".cal-cell").classList.contains("is-outside")) return;
    e.preventDefault();
    select(link);
  });
});
