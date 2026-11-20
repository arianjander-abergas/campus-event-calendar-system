/**
 * Campus Event Hub — login / sign-up / password pages.
 * Any button with data-toggle-password="<input id>" shows/hides that
 * password field and swaps its eye icons (.icon-hidden / .icon-visible).
 */
document.querySelectorAll("[data-toggle-password]").forEach((btn) => {
  const input = document.getElementById(btn.dataset.togglePassword);
  if (!input) return;
  btn.addEventListener("click", () => {
    const show = input.type === "password";
    input.type = show ? "text" : "password";
    const hiddenIcon = btn.querySelector(".icon-hidden");
    const visibleIcon = btn.querySelector(".icon-visible");
    if (hiddenIcon) hiddenIcon.hidden = show;
    if (visibleIcon) visibleIcon.hidden = !show;
    btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
  });
});

/**
 * Login page Google button [data-google]: when Google sign-in is switched on
 * the link just opens Google. When it's off, its message shows in the card
 * instead of reloading the page. New-tab clicks just follow the link.
 */
const glassCard = document.querySelector(".login-glass .login-card");
const reducedMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
const isPlainClick = (e) => e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;

document.querySelectorAll('[data-google="off"]').forEach((link) => {
  link.addEventListener("click", (e) => {
    const notice = glassCard && glassCard.querySelector(".login-google-notice");
    if (!notice || !isPlainClick(e)) return;
    e.preventDefault();
    notice.hidden = false;
  });
});


/**
 * Forms marked data-loading-form (Forgot Password): while the request is on
 * its way, the submit button shows a spinner and can't be pressed twice.
 */
document.querySelectorAll("form[data-loading-form]").forEach((form) => {
  form.addEventListener("submit", () => {
    const btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    btn.classList.add("is-loading");
    btn.setAttribute("aria-busy", "true");
    setTimeout(() => { btn.disabled = true; }, 0); // after the submit has started
  });
});
// Back button after sending: the button works again.
window.addEventListener("pageshow", (e) => {
  if (!e.persisted) return;
  document.querySelectorAll("form[data-loading-form] .is-loading").forEach((btn) => {
    btn.classList.remove("is-loading");
    btn.removeAttribute("aria-busy");
    btn.disabled = false;
  });
});

/**
 * Smooth show / hide for what the options reveal on the glass login and
 * sign-up cards: the student-only fields (Student / Faculty), the irregular
 * summary (Regular / Irregular) and the Google message. Other
 * scripts keep using the plain `hidden` attribute; this watches it and
 * animates the height and fade instead of letting the card jump.
 */
(() => {
  if (!glassCard) return;
  const targets = glassCard.querySelectorAll(
    "[data-student-only], [data-irregular-summary], .login-google-notice"
  );
  if (!targets.length) return;

  const DURATION = 320;
  const EASING = "cubic-bezier(0.4, 0, 0.2, 1)";
  const CLOSED = {
    height: "0px", paddingTop: "0px", paddingBottom: "0px", marginTop: "0px", marginBottom: "0px",
    borderTopWidth: "0px", borderBottomWidth: "0px", opacity: 0, transform: "translateY(-8px)",
  };
  const shown = new WeakMap();   // last visibility we know about
  const running = new WeakMap(); // animation in progress
  const ownChanges = new WeakMap(); // `hidden` changes made here, to ignore

  const openFrame = (el) => {
    const cs = getComputedStyle(el);
    return {
      height: el.offsetHeight + "px", paddingTop: cs.paddingTop, paddingBottom: cs.paddingBottom,
      marginTop: cs.marginTop, marginBottom: cs.marginBottom,
      borderTopWidth: cs.borderTopWidth, borderBottomWidth: cs.borderBottomWidth,
      opacity: 1, transform: "none",
    };
  };
  const setHidden = (el, value) => {
    ownChanges.set(el, (ownChanges.get(el) || 0) + 1);
    el.hidden = value;
  };

  function play(el, show) {
    running.get(el)?.cancel();
    if (!show) setHidden(el, false); // keep it on screen while it closes
    const open = openFrame(el);
    el.style.overflow = "hidden";
    el.style.boxSizing = "border-box";
    const anim = el.animate(show ? [CLOSED, open] : [open, CLOSED], { duration: DURATION, easing: EASING });
    running.set(el, anim);
    const cleanUp = () => { el.style.overflow = ""; el.style.boxSizing = ""; running.delete(el); };
    anim.oncancel = cleanUp;
    anim.onfinish = () => { cleanUp(); if (!show) setHidden(el, true); };
  }

  const observer = new MutationObserver((records) => {
    records.forEach(({ target: el }) => {
      const own = ownChanges.get(el) || 0;
      if (own) { ownChanges.set(el, own - 1); return; }
      const visible = !el.hidden;
      if (visible === shown.get(el)) return;
      shown.set(el, visible);
      if (!reducedMotion()) play(el, visible);
    });
  });
  targets.forEach((el) => {
    shown.set(el, !el.hidden);
    observer.observe(el, { attributes: true, attributeFilter: ["hidden"] });
  });
})();
