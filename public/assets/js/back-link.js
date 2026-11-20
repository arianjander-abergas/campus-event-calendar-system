/**
 * Campus Event Hub — "Back" links that go back to where the person came
 * from, not to a fixed page. A link with data-back (the event page's Back,
 * the attendance printout's Back) keeps its href as the fallback, for when
 * the page was opened from outside the site, an email or a new tab.
 *
 * The page that opened this one is remembered per tab (sessionStorage), so
 * Back still works after something on the page reloads it (Register,
 * Cancel registration, switching the printout's mode): it goes to the page
 * from before, not to this page again. When that page is the previous step
 * in the browser's history, history.back() is used, so it comes back with
 * its scroll position and state (the chosen day on the calendar, the tab…).
 */
(function () {
  var links = document.querySelectorAll("a[data-back]");
  if (!links.length) return;

  // One page = one address + its event (?id= or ?event=); other parts of the
  // query (the printout's mode, a message) don't make it a different page.
  function pageOf(url) {
    var p = url.searchParams;
    return url.pathname + "#" + (p.get("id") || p.get("event") || "");
  }
  var here = pageOf(new URL(location.href));
  var ref = null;
  try { ref = document.referrer ? new URL(document.referrer) : null; } catch (e) { ref = null; }
  // Came from another page of this site (not this one again, not a login screen).
  var cameFrom = ref && ref.origin === location.origin && pageOf(ref) !== here && ref.pathname.indexOf("/auth/") === -1 ? ref.href : "";

  var from = cameFrom;
  try {
    var key = "ceh-back:" + here;
    if (cameFrom) sessionStorage.setItem(key, cameFrom);
    from = sessionStorage.getItem(key) || "";
  } catch (e) { /* storage blocked: this visit's referrer only */ }
  if (!from) return;

  links.forEach(function (link) {
    link.href = from; // a new tab / hovering shows where it really goes
    link.addEventListener("click", function (e) {
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      if (ref && ref.href === from && history.length > 1) {
        e.preventDefault();
        history.back();
      }
    });
  });
})();
