/**
 * Campus Event Hub — small interface touches shared by every page with the
 * sidebar (loaded right after sidebar.js). Each part does nothing on pages
 * that don't have what it needs.
 *
 *   - Cards fade / slide up as they scroll into view (style.css "MOTION").
 *   - Home page stats count up from 0 the first time they're seen.
 *   - Flash messages get a ✕ button; success messages close by themselves.
 *   - A "back to top" button appears after scrolling down a long page.
 *   - The top bar gets a deeper shadow once the page is scrolled (phones).
 *
 * Everything that moves is skipped when the visitor's device asks for
 * reduced motion; the page works the same without this file.
 */
(function () {
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------------- Reveal on scroll ---------------- */
  // Items already on screen at load show at once (no flash of empty space);
  // the rest fade in as they're scrolled to. Siblings in a grid are staggered.
  const REVEAL = [
    ".event-card", ".list-card", ".rail-card", ".org-card", ".about-section",
    ".feature-strip", ".upcoming-panel", ".cal-card", ".cal-day-panel", ".cal-ongoing-item",
    ".eval-callout", ".admin-panel", ".admin-card", ".kpi", ".admin-table-wrap",
    ".ed-hero", ".ed-program-card", ".ed-schedule", ".trusted-strip",
  ].join(",");

  let pendingReveal = [];

  function show(el) {
    if (el.classList.contains("is-visible")) return;
    el.classList.add("is-visible");
    pendingReveal = pendingReveal.filter((x) => x !== el);
    // Once shown, give the element its own transitions back (e.g. the
    // quick lift on hover of event cards).
    el.addEventListener("transitionend", () => {
      el.classList.remove("reveal", "is-visible");
      el.style.removeProperty("--reveal-delay");
    }, { once: true });
  }

  // Backup for the observer, run on scroll: anything that has reached the
  // screen is shown, so content can never stay hidden.
  function checkReveal() {
    const fold = window.innerHeight;
    pendingReveal.slice().forEach((el) => {
      if (el.getBoundingClientRect().top < fold) show(el);
    });
  }

  function setupReveal() {
    if (reduceMotion || !("IntersectionObserver" in window)) return;
    const items = Array.from(document.querySelectorAll(REVEAL));
    if (!items.length) return;
    const fold = window.innerHeight;
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        show(entry.target);
      });
    }, { rootMargin: "0px 0px -40px 0px" });

    items.forEach((el) => {
      if (el.getBoundingClientRect().top < fold) return; // visible now: leave as is
      // Stagger cards that sit next to each other (same parent).
      const index = Array.prototype.indexOf.call(el.parentElement.children, el);
      el.style.setProperty("--reveal-delay", Math.min(index % 4, 3) * 70 + "ms");
      el.classList.add("reveal");
      pendingReveal.push(el);
      observer.observe(el);
    });
  }

  /* ---------------- Count-up stats (home) ---------------- */
  function setupCountUp() {
    const values = document.querySelectorAll(".stats-block [data-stat]");
    if (!values.length || reduceMotion || !("IntersectionObserver" in window)) return;

    function run(el) {
      const target = parseInt(el.textContent, 10);
      // Only plain whole numbers ("48"); formatted ones ("1.2k") stay as they are.
      if (!/^\d+$/.test(el.textContent.trim()) || target === 0) return;
      const start = performance.now();
      const duration = 900;
      function frame(now) {
        const t = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - t, 3);
        el.textContent = String(Math.round(target * eased));
        if (t < 1) requestAnimationFrame(frame);
        else el.textContent = String(target);
      }
      requestAnimationFrame(frame);
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        run(entry.target);
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.6 });
    values.forEach((el) => observer.observe(el));
  }

  /* ---------------- Flash messages ---------------- */
  function setupFlashes() {
    document.querySelectorAll(".flash, .ed-flash").forEach((flash) => {
      const close = document.createElement("button");
      close.type = "button";
      close.className = "flash-close";
      close.setAttribute("aria-label", "Dismiss message");
      close.innerHTML = "&times;";
      close.addEventListener("click", () => dismiss(flash));
      flash.appendChild(close);

      const success = flash.classList.contains("flash-success") || flash.classList.contains("ed-flash-ok");
      if (success) setTimeout(() => dismiss(flash), 6000);
    });

    function dismiss(flash) {
      if (!flash.isConnected) return;
      if (reduceMotion) { flash.remove(); return; }
      flash.classList.add("is-leaving");
      // Removed when the 0.3s slide-out is over (a timer, so it also works
      // when the browser skips animation events, e.g. in a background tab).
      setTimeout(() => flash.remove(), 320);
    }
  }

  /* ---------------- Back to top + scrolled top bar ---------------- */
  function setupScrollBits() {
    const topbar = document.querySelector(".dash-topbar");
    const button = document.createElement("button");
    button.type = "button";
    button.className = "back-to-top";
    button.setAttribute("aria-label", "Back to top");
    button.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M6 15l6-6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    button.hidden = true;
    button.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
    });
    document.body.appendChild(button);

    function update() {
      const y = window.scrollY;
      button.hidden = y < 600;
      if (topbar) topbar.classList.toggle("is-scrolled", y > 8);
      if (pendingReveal.length) checkReveal();
    }
    window.addEventListener("scroll", update, { passive: true });
    update();
  }

  function init() {
    document.documentElement.classList.add("js-ui");
    setupReveal();
    setupCountUp();
    setupFlashes();
    setupScrollBits();
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
