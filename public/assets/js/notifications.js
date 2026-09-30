/**
 * Campus Event Hub — notification bell (dashboard, admin pages, event details).
 *
 * The notifications come from the page itself: window.USER_NOTIFICATIONS is
 * filled in by PHP with the user's login (only they can read their own
 * notifications). Opening the bell marks them all as read.
 *
 * Markup: [data-notifications] containing [data-notif-btn], [data-notif-count],
 * [data-notif-dropdown] and [data-notif-list].
 */
(function () {
  const root = document.querySelector("[data-notifications]");
  if (!root) return;

  const btn = root.querySelector("[data-notif-btn]");
  const count = root.querySelector("[data-notif-count]");
  const dropdown = root.querySelector("[data-notif-dropdown]");
  const list = root.querySelector("[data-notif-list]");
  const items = window.USER_NOTIFICATIONS || [];
  let unread = items.filter((n) => n.status !== "read").length;

  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  // "just now", "5 min ago", "3 hr ago", "2 days ago", or the date.
  function timeAgo(iso) {
    const then = new Date(iso);
    if (isNaN(then)) return "";
    const s = Math.floor((Date.now() - then.getTime()) / 1000);
    if (s < 60) return "just now";
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} hr ago`;
    if (s < 7 * 86400) return `${Math.floor(s / 86400)} day${s < 172800 ? "" : "s"} ago`;
    return then.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
  }

  if (items.length) {
    list.innerHTML = items
      .map((n) => {
        // Notifications about an event link to it.
        const tag = n.event_id ? "a" : "div";
        const href = n.event_id ? ` href="${esc(window.EVENT_URL + encodeURIComponent(n.event_id))}"` : "";
        return `<${tag} class="notif-item${n.status !== "read" ? " unread" : ""}"${href}>
          <p class="notif-title">${esc(n.title)}</p>
          <p class="notif-message">${esc(n.message)}</p>
          <p class="notif-time">${esc(timeAgo(n.created_at))}</p>
        </${tag}>`;
      })
      .join("");
  }

  function setOpen(open) {
    dropdown.hidden = !open;
    btn.setAttribute("aria-expanded", String(open));
    if (open && unread > 0) {
      unread = 0;
      if (count) count.hidden = true;
      const body = new FormData();
      body.append("action", "mark_read");
      fetch(window.NOTIF_READ_URL, { method: "POST", body, credentials: "same-origin" }).catch(() => {});
    }
  }

  btn.addEventListener("click", () => setOpen(dropdown.hidden));
  document.addEventListener("click", (e) => {
    if (!dropdown.hidden && !root.contains(e.target)) setOpen(false);
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !dropdown.hidden) {
      setOpen(false);
      btn.focus();
    }
  });
})();
