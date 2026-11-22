/**
 * Campus Event Hub — Event Calendar (events.php).
 * Clicking a date shows every event on that date in a pop-up, without
 * reloading the page. Each day's list is already on the page (one hidden
 * <section id="day-YYYY-MM-DD"> per day with events, inside the
 * <dialog data-day-dialog>); this only switches which one is visible and
 * opens the pop-up. Clicking an event in it opens the event pop-up on top
 * (event-modal.js). Without JavaScript the date links reload the page with
 * ?date=... and the server shows the same list (the dialog starts open).
 */
document.addEventListener("DOMContentLoaded", () => {
  const table = document.querySelector("[data-calendar]");
  const dialog = document.querySelector("[data-day-dialog]");
  if (!table || !dialog || typeof dialog.showModal !== "function") return;

  const empty = document.getElementById("day-empty");
  const emptyTitle = empty?.querySelector("[data-empty-date]");
  const body = dialog.querySelector(".event-modal-body");

  function formatDate(date) {
    const [y, m, d] = date.split("-").map(Number);
    return new Date(y, m - 1, d).toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric", year: "numeric" });
  }

  function openModal() {
    if (dialog.open) dialog.close(); // started open (no-JS fallback): reopen as a modal
    dialog.showModal();
    document.documentElement.classList.add("cal-day-open");
    if (body) body.scrollTop = 0;
  }

  function select(link) {
    const date = link.dataset.date;
    table.querySelectorAll(".cal-cell.is-selected").forEach((c) => c.classList.remove("is-selected"));
    table.querySelectorAll("[aria-current='date']").forEach((a) => a.removeAttribute("aria-current"));
    link.closest(".cal-cell").classList.add("is-selected");
    link.setAttribute("aria-current", "date");

    dialog.querySelectorAll(".cal-day-events").forEach((s) => { s.hidden = true; });
    const day = document.getElementById("day-" + date);
    if (day) {
      day.hidden = false;
    } else if (empty) {
      if (emptyTitle) emptyTitle.textContent = formatDate(date);
      empty.hidden = false;
    }
    dialog.setAttribute("aria-label", "Events on " + formatDate(date));
    openModal();
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

  // Close: ✕, a click on the dark backdrop, Esc.
  dialog.querySelector("[data-day-close]")?.addEventListener("click", () => dialog.close());
  let downOnBackdrop = false;
  dialog.addEventListener("mousedown", (e) => { downOnBackdrop = e.target === dialog; });
  dialog.addEventListener("click", (e) => { if (e.target === dialog && downOnBackdrop) dialog.close(); });
  dialog.addEventListener("close", () => {
    document.documentElement.classList.remove("cal-day-open");
    // Forget the date, so a reload doesn't open the pop-up again.
    const url = new URL(location.href);
    if (url.searchParams.has("date")) {
      url.searchParams.delete("date");
      history.replaceState(null, "", url.href);
    }
  });

  // A link that chose a date (search results, a reload after registering): show it.
  if (new URL(location.href).searchParams.has("date")) openModal();
});
