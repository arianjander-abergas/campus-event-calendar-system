/*
 * Chart tooltips (admin dashboard, reports, evaluation results).
 * Any element with data-tip shows its text on hover or keyboard focus.
 * The same numbers are always in a table on the page too.
 */
(function () {
  const tip = document.createElement("div");
  tip.className = "chart-tip";
  tip.setAttribute("role", "status");
  tip.hidden = true;
  document.body.appendChild(tip);

  function show(el, x, y) {
    tip.textContent = el.getAttribute("data-tip"); // textContent: labels are data, never HTML
    tip.hidden = false;
    const box = tip.getBoundingClientRect();
    const left = Math.min(window.innerWidth - box.width - 8, Math.max(8, x - box.width / 2));
    const top = y - box.height - 12 < 8 ? y + 16 : y - box.height - 12;
    tip.style.left = left + window.scrollX + "px";
    tip.style.top = top + window.scrollY + "px";
  }
  function hide() { tip.hidden = true; }

  document.addEventListener("pointermove", function (e) {
    const el = e.target.closest && e.target.closest("[data-tip]");
    if (el) show(el, e.clientX, e.clientY); else hide();
  });
  document.addEventListener("focusin", function (e) {
    const el = e.target.closest && e.target.closest("[data-tip]");
    if (!el) return hide();
    const r = el.getBoundingClientRect();
    show(el, r.left + r.width / 2, r.top);
  });
  document.addEventListener("focusout", hide);
  document.addEventListener("scroll", hide, { passive: true });

  // Strip native <title> tooltips once JS tooltips are on (avoids two popups).
  document.querySelectorAll("[data-tip] > title").forEach(function (t) {
    t.parentNode.setAttribute("aria-label", t.textContent);
    t.remove();
  });
  document.querySelectorAll("[data-tip][title]").forEach(function (el) {
    el.setAttribute("aria-label", el.getAttribute("title"));
    el.removeAttribute("title");
  });

  // Print buttons.
  document.querySelectorAll("[data-print]").forEach(function (btn) {
    btn.addEventListener("click", function () { window.print(); });
  });
})();
