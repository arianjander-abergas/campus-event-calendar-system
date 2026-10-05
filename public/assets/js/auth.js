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

document.querySelectorAll('a[data-slide="right"]').forEach((link) => {
  link.addEventListener("click", (e) => {
    if (!slideCard || !isPlainClick(e) || reducedMotion()) return;
    if (slideCard.classList.contains("is-admin")) return; // just open sign-up
    e.preventDefault();
    slideCard.classList.remove("slide-right");
    void slideCard.offsetWidth; // restart the animation if clicked twice
    slideCard.classList.add("slide-right");
    setTimeout(() => { window.location.href = link.href; }, SLIDE_MS);
  });
});

// Coming back with the browser's Back button: show the form again.
window.addEventListener("pageshow", (e) => {
  if (e.persisted && slideCard) slideCard.classList.remove("slide-right");
});
