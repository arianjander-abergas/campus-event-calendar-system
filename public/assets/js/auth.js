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
 * Login page: links with data-slide="left|right" (Google, Admin Login,
 * Sign up) play the sliding-panel animation in that direction, then open
 * the link. Skipped for new-tab clicks and when the user prefers reduced motion.
 */
const slideCard = document.querySelector(".login-glass .login-card");
const SLIDE_MS = 650;

document.querySelectorAll("a[data-slide]").forEach((link) => {
  link.addEventListener("click", (e) => {
    if (!slideCard || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    e.preventDefault();
    slideCard.classList.remove("slide-left", "slide-right");
    void slideCard.offsetWidth; // restart the animation if clicked twice
    slideCard.classList.add(link.dataset.slide === "right" ? "slide-right" : "slide-left");
    setTimeout(() => { window.location.href = link.href; }, SLIDE_MS);
  });
});

// Coming back with the browser's Back button: show the form again.
window.addEventListener("pageshow", (e) => {
  if (e.persisted && slideCard) slideCard.classList.remove("slide-left", "slide-right");
});
