/**
 * Campus Event Hub — the site's own confirmation pop-up (instead of the
 * browser's "localhost says" box). Loaded on every page with the sidebar
 * (includes/nav.php) and on the sign-up page.
 *
 * In the HTML — a form that asks first:
 *   <form method="POST" data-confirm="Delete this program?"
 *         data-confirm-title="Delete program" data-confirm-ok="Delete"
 *         data-confirm-cancel="Keep it" data-confirm-tone="danger">
 *   The message can name a chosen option: {select:role} is replaced with the
 *   text of the form's "role" dropdown. Links / buttons can carry the same
 *   data-confirm attributes.
 *
 * In scripts:
 *   cehConfirm("Message", {title, ok, cancel, tone}).then(function (yes) { … });
 *   cehAlert("Message", {title, ok});
 */
(function () {
  var dialog, titleEl, messageEl, okBtn, cancelBtn, resolveOpen = null;

  function build() {
    if (dialog) return;
    dialog = document.createElement("dialog");
    dialog.className = "ceh-confirm";
    dialog.setAttribute("aria-labelledby", "cehConfirmTitle");
    dialog.setAttribute("aria-describedby", "cehConfirmMessage");
    dialog.innerHTML =
      '<div class="ceh-confirm-box">' +
      '<div class="ceh-confirm-icon" aria-hidden="true">!</div>' +
      '<div class="ceh-confirm-body">' +
      '<h2 class="ceh-confirm-title" id="cehConfirmTitle"></h2>' +
      '<p class="ceh-confirm-message" id="cehConfirmMessage"></p>' +
      "</div>" +
      '<div class="ceh-confirm-actions">' +
      '<button type="button" class="btn btn-outline" data-ceh-cancel></button>' +
      '<button type="button" class="btn btn-primary" data-ceh-ok></button>' +
      "</div></div>";
    document.body.appendChild(dialog);
    titleEl = dialog.querySelector(".ceh-confirm-title");
    messageEl = dialog.querySelector(".ceh-confirm-message");
    okBtn = dialog.querySelector("[data-ceh-ok]");
    cancelBtn = dialog.querySelector("[data-ceh-cancel]");
    okBtn.addEventListener("click", function () { finish(true); });
    cancelBtn.addEventListener("click", function () { finish(false); });
    // Esc, and a click on the dark backdrop, mean "no".
    dialog.addEventListener("cancel", function (e) { e.preventDefault(); finish(false); });
    dialog.addEventListener("click", function (e) { if (e.target === dialog) finish(false); });
  }

  function finish(answer) {
    if (typeof dialog.close === "function") dialog.close(); else dialog.removeAttribute("open");
    var done = resolveOpen;
    resolveOpen = null;
    if (done) done(answer);
  }

  function open(message, options, isAlert) {
    build();
    options = options || {};
    if (resolveOpen) finish(false); // one at a time
    var danger = options.tone === "danger";
    dialog.classList.toggle("is-danger", danger);
    dialog.classList.toggle("is-alert", !!isAlert);
    titleEl.textContent = options.title || (isAlert ? "Please note" : "Are you sure?");
    messageEl.textContent = message || "";
    okBtn.textContent = options.ok || "OK";
    okBtn.className = "btn " + (danger ? "btn-danger" : "btn-primary");
    cancelBtn.textContent = options.cancel || "Cancel";
    cancelBtn.hidden = !!isAlert;
    return new Promise(function (resolve) {
      resolveOpen = resolve;
      if (typeof dialog.showModal === "function") dialog.showModal(); else dialog.setAttribute("open", "");
      // Risky actions start on the safe button; everything else on OK.
      (danger && !isAlert ? cancelBtn : okBtn).focus();
    });
  }

  window.cehConfirm = function (message, options) { return open(message, options, false); };
  window.cehAlert = function (message, options) { return open(message, options, true); };

  // The data-confirm attributes of a form / link / button, as options.
  function optionsOf(el) {
    return {
      title: el.getAttribute("data-confirm-title") || "",
      ok: el.getAttribute("data-confirm-ok") || "",
      cancel: el.getAttribute("data-confirm-cancel") || "",
      tone: el.getAttribute("data-confirm-tone") || "",
    };
  }
  function messageOf(el, form) {
    return (el.getAttribute("data-confirm") || "").replace(/\{select:([\w-]+)\}/g, function (all, name) {
      var field = form && form.elements.namedItem(name);
      return field && field.options && field.selectedIndex >= 0 ? field.options[field.selectedIndex].text : "";
    });
  }

  // Forms: ask first, then send the form as it was going to be sent.
  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute("data-confirm")) return;
    if (form.dataset.cehConfirmed === "1") { delete form.dataset.cehConfirmed; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    var submitter = e.submitter || null;
    cehConfirm(messageOf(form, form), optionsOf(form)).then(function (yes) {
      if (!yes) return;
      form.dataset.cehConfirmed = "1";
      if (typeof form.requestSubmit === "function") form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
      else form.submit();
    });
  }, true);

  // Every POST form is sent once: a second click (or Enter) while the page
  // is still loading is ignored, so nothing is saved twice. Listens last
  // (bubbling, on window), so forms a script stopped — a pop-up waiting for
  // an answer, a field to fix — aren't locked. Unlocks after 10 seconds in
  // case the answer was a download, and when the page is shown again (Back).
  window.addEventListener("submit", function (e) {
    var form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement) || (form.getAttribute("method") || "").toLowerCase() !== "post") return;
    if (form.dataset.cehSending === "1") { e.preventDefault(); return; }
    form.dataset.cehSending = "1";
    if (e.submitter) e.submitter.setAttribute("aria-busy", "true");
    setTimeout(function () { unlock(form); }, 10000);
  });
  function unlock(form) {
    delete form.dataset.cehSending;
    form.querySelectorAll("[aria-busy]").forEach(function (b) { b.removeAttribute("aria-busy"); });
  }
  window.addEventListener("pageshow", function () {
    document.querySelectorAll("form[data-ceh-sending]").forEach(unlock);
  });

  // Links and plain buttons with data-confirm.
  document.addEventListener("click", function (e) {
    var el = e.target.closest && e.target.closest("a[data-confirm], button[data-confirm][type='button']");
    if (!el || el.dataset.cehConfirmed === "1") { if (el) delete el.dataset.cehConfirmed; return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    cehConfirm(messageOf(el, el.form), optionsOf(el)).then(function (yes) {
      if (!yes) return;
      el.dataset.cehConfirmed = "1";
      el.click();
    });
  }, true);
})();
