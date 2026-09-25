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
