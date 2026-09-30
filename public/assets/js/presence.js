/**
 * Campus Event Hub — "Active Users" heartbeat.
 * Only loaded for logged-in users (presence_script_tag() in config/supabase.php).
 *
 * - While a page is open and visible, it tells the server "still here" every
 *   30 seconds. The server counts a user as active if they checked in within
 *   the last 2 minutes, so a crashed browser drops off on its own.
 * - Closing the tab/window sends "leave" right away (Active Users -1).
 *   Moving between pages of this site doesn't count as leaving.
 */
(function () {
  const url = window.PRESENCE_URL;
  if (!url) return;

  const INTERVAL_MS = 30000;
  let navigatingInSite = false;

  function send(action) {
    const body = new FormData();
    body.append("action", action);
    if (action === "leave" && navigator.sendBeacon) {
      // sendBeacon still gets delivered while the page is closing.
      navigator.sendBeacon(url, body);
      return;
    }
    fetch(url, { method: "POST", body, credentials: "same-origin", keepalive: true }).catch(() => {});
  }

  function ping() {
    if (document.visibilityState === "visible") send("ping");
  }

  // Clicking a link to another page of this site (or submitting a form) is
  // navigation, not leaving — the next page pings again as soon as it loads.
  document.addEventListener("click", (e) => {
    const link = e.target.closest("a[href]");
    if (!link || link.target === "_blank" || link.origin !== location.origin) return;
    const samePageAnchor = link.pathname === location.pathname && link.search === location.search && link.hash;
    if (!samePageAnchor) navigatingInSite = true;
  });
  document.addEventListener("submit", () => { navigatingInSite = true; });

  window.addEventListener("pagehide", () => {
    if (!navigatingInSite) send("leave");
  });
  // Back/forward cache restored the page: count them again.
  window.addEventListener("pageshow", (e) => {
    if (e.persisted) {
      navigatingInSite = false;
      ping();
    }
  });
  document.addEventListener("visibilitychange", ping);

  ping();
  setInterval(ping, INTERVAL_MS);
})();
