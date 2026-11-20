/**
 * Campus Event Hub — the event pop-up. Clicking an event (any link with
 * data-event-modal: event cards, the Event Calendar's day list, dashboard
 * lists…) shows its details in a pop-up on the same page instead of opening
 * event-details.php. Loaded by includes/nav.php on every page with the sidebar.
 *
 *   - The content is event-details.php?id=…&modal=1: the same details as the
 *     full page (includes/event-detail.php), styled by event-details.css,
 *     which is loaded the first time a pop-up opens (data-css below).
 *   - Register / Cancel registration work inside the pop-up; when it closes
 *     after one of them, the page reloads so "N going" and badges are current.
 *   - Ctrl/⌘-click, middle-click and "Open the full page" still open the
 *     page. If the pop-up can't be shown (logged out, offline…), the link
 *     simply opens as before.
 *   - Esc, ✕, a click on the dark backdrop and the phone's Back gesture close it.
 */
(function () {
  if (typeof HTMLDialogElement !== "function" || !window.fetch) return; // links work as plain links

  var cssUrl = document.currentScript && document.currentScript.getAttribute("data-css");
  var dialog = null, body = null, stylesReady = null;
  var changed = false; // registered / cancelled while open
  var ticket = 0;      // the latest request; older answers are ignored

  function loadStyles() {
    if (!stylesReady) {
      stylesReady = document.querySelector('link[href*="event-details.css"]') || !cssUrl
        ? Promise.resolve()
        : new Promise(function (resolve) {
            var link = document.createElement("link");
            link.rel = "stylesheet";
            link.href = cssUrl;
            link.onload = link.onerror = function () { resolve(); };
            document.head.appendChild(link);
          });
    }
    return stylesReady;
  }

  function build() {
    if (dialog) return;
    dialog = document.createElement("dialog");
    dialog.className = "event-modal";
    dialog.innerHTML =
      '<button type="button" class="event-modal-close" aria-label="Close">&times;</button>' +
      '<div class="event-modal-body"></div>';
    body = dialog.querySelector(".event-modal-body");
    document.body.appendChild(dialog);

    dialog.querySelector(".event-modal-close").addEventListener("click", close);
    // A click on the backdrop (the dialog itself, outside its content) closes
    // it — but not a text selection that started inside and ended outside.
    var downOnBackdrop = false;
    dialog.addEventListener("mousedown", function (e) { downOnBackdrop = e.target === dialog; });
    dialog.addEventListener("click", function (e) { if (e.target === dialog && downOnBackdrop) close(); });
    dialog.addEventListener("submit", send);
    // However it closes (✕, backdrop, Esc, the phone's Back gesture): tidy up.
    dialog.addEventListener("close", closed);
  }

  // The pop-up's HTML, or an error when the answer isn't it (e.g. the login page).
  function load(url, init) {
    init = init || {};
    init.credentials = "same-origin";
    return fetch(url, init).then(function (res) {
      if (!res.ok || res.headers.get("X-Event-Modal") !== "1") throw new Error("Not the event pop-up");
      return res.text();
    });
  }

  function fill(html) {
    body.innerHTML = html; // our own page's HTML: every value in it is escaped by PHP
    dialog.removeAttribute("aria-busy");
    if (body.querySelector("#edModalTitle")) dialog.setAttribute("aria-labelledby", "edModalTitle");
    else dialog.setAttribute("aria-label", "Event details");
    body.scrollTop = 0;
  }

  function open(href) {
    build();
    var url = new URL(href, location.href);
    url.searchParams.set("modal", "1");
    var mine = ++ticket;
    body.innerHTML = '<p class="event-modal-loading" role="status">Loading the event…</p>';
    dialog.setAttribute("aria-busy", "true");
    dialog.removeAttribute("aria-labelledby");
    dialog.setAttribute("aria-label", "Event details");
    if (!dialog.open) dialog.showModal();
    document.documentElement.classList.add("event-modal-open");

    Promise.all([load(url.href), loadStyles()]).then(function (answers) {
      if (mine === ticket) fill(answers[0]);
    }).catch(function () {
      if (mine === ticket) window.location.href = href; // show the full page instead
    });
  }

  // Register / Cancel registration inside the pop-up. (Cancel asks first:
  // confirm.js stops the first submit and sends the form again on "yes".)
  function send(e) {
    var form = e.target;
    // getAttribute(), not form.action / form.method: these forms have an
    // <input name="action">, which form.action would return instead.
    if (e.defaultPrevented || !(form instanceof HTMLFormElement) || (form.getAttribute("method") || "").toLowerCase() !== "post") return;
    e.preventDefault();
    var target = new URL(form.getAttribute("action") || location.href, location.href);
    var button = e.submitter || form.querySelector('[type="submit"]');
    if (button) { button.disabled = true; button.setAttribute("aria-busy", "true"); }
    var mine = ++ticket;
    load(target.href, { method: "POST", body: new FormData(form) }).then(function (html) {
      if (mine === ticket) {
        changed = true;
        fill(html);
      } else if (!dialog.open) {
        window.location.reload(); // closed while it was being sent: show the result on the page
      }
    }).catch(function () {
      // Open the event's page: it shows whether it went through.
      target.searchParams.delete("modal");
      window.location.href = target.href;
    });
  }

  function close() {
    if (dialog.open) dialog.close(); // → closed()
  }

  function closed() {
    ticket++; // answers still on their way are ignored
    document.documentElement.classList.remove("event-modal-open");
    if (changed) {
      changed = false;
      window.location.reload();
    }
  }

  document.addEventListener("click", function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var link = e.target.closest && e.target.closest("a[data-event-modal]");
    if (!link || !link.href) return;
    e.preventDefault();
    open(link.href);
  });
})();
