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
 * Login page sliding panel — same idea as
 * github.com/BOSS0exe/Sign-in-and-Sign-up-page (a class on the card makes the
 * panel and the form swap sides; CSS animates it).
 *   Admin Login [data-toggle-admin]: panel slides RIGHT, form slides LEFT and
 *     they stay there; the form now logs in to the admin pages.
 *   Google [data-google]: panel slides back LEFT, form slides RIGHT and they
 *     stay there (no page reload). If Google sign-in is switched on, Google
 *     opens after the slide; otherwise its message shows in the card.
 *   Sign up [data-slide="right"]: panel and form slide RIGHT, then sign-up opens.
 *   Log in on the sign-up page [data-slide="left"]: panel and form slide LEFT,
 *     then the login page opens (its panel is on the left).
 * New-tab clicks just follow the link.
 */
const slideCard = document.querySelector(".login-glass .login-card");
const SLIDE_MS = 600; // keep in step with the 0.6s slides in auth.css
const reducedMotion = () => window.matchMedia("(prefers-reduced-motion: reduce)").matches;
const isPlainClick = (e) => e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;

function setAdminMode(on) {
  slideCard.classList.toggle("is-admin", on);
  const redirect = slideCard.querySelector('input[name="redirect"]');
  if (redirect) redirect.value = on ? slideCard.dataset.adminRedirect : slideCard.dataset.userRedirect;
  const adminNotice = slideCard.querySelector(".login-admin-notice");
  if (adminNotice) adminNotice.hidden = !on;
  const googleNotice = slideCard.querySelector(".login-google-notice");
  if (googleNotice && on) googleNotice.hidden = true;
  document.querySelectorAll("[data-toggle-admin]").forEach((b) => b.setAttribute("aria-pressed", String(on)));
}

document.querySelectorAll("[data-toggle-admin]").forEach((btn) => {
  btn.addEventListener("click", (e) => {
    if (!slideCard || !isPlainClick(e)) return;
    e.preventDefault();
    setAdminMode(!slideCard.classList.contains("is-admin"));
  });
});

document.querySelectorAll("[data-google]").forEach((link) => {
  link.addEventListener("click", (e) => {
    if (!slideCard || !isPlainClick(e)) return;
    e.preventDefault();
    const wasAdmin = slideCard.classList.contains("is-admin");
    setAdminMode(false); // panel slides back to the LEFT and stays
    if (link.dataset.google === "on") {
      setTimeout(() => { window.location.href = link.href; }, wasAdmin && !reducedMotion() ? SLIDE_MS : 0);
    } else {
      const notice = slideCard.querySelector(".login-google-notice");
      if (notice) notice.hidden = false;
    }
  });
});

// Links that leave the page with a slide: Sign up (right, login page) and
// Log in (left, sign-up page).
document.querySelectorAll("a[data-slide]").forEach((link) => {
  link.addEventListener("click", (e) => {
    if (!slideCard || !isPlainClick(e) || reducedMotion()) return;
    if (slideCard.classList.contains("is-admin")) return; // just open the page
    e.preventDefault();
    slideCard.classList.remove("slide-left", "slide-right");
    void slideCard.offsetWidth; // restart the animation if clicked twice
    slideCard.classList.add(link.dataset.slide === "left" ? "slide-left" : "slide-right");
    setTimeout(() => { window.location.href = link.href; }, SLIDE_MS);
  });
});

// Coming back with the browser's Back button: show the form again.
window.addEventListener("pageshow", (e) => {
  if (e.persisted && slideCard) slideCard.classList.remove("slide-left", "slide-right");
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
 * summary (Regular / Irregular) and the admin / Google messages. Other
 * scripts keep using the plain `hidden` attribute; this watches it and
 * animates the height and fade instead of letting the card jump.
 */
(() => {
  if (!slideCard) return;
  const targets = slideCard.querySelectorAll(
    "[data-student-only], [data-irregular-summary], .login-admin-notice, .login-google-notice"
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
