/**
 * Campus Event Hub — forms start clean.
 * Forms marked data-autoreset go back to what the server sent (a blank
 * form, or the event being edited) whenever the page is shown again from
 * the browser's back / forward cache — e.g. after publishing an event and
 * pressing Back, or after leaving the page and returning. Together with
 * autocomplete="off" (no refilling on refresh) and the server showing typed
 * values only once after an error (remember_form() / recall_form()), a form
 * is cleared once it's published, refreshed or navigated away from.
 */
window.addEventListener("pageshow", (e) => {
  if (!e.persisted) return;
  document.querySelectorAll("form[data-autoreset]").forEach((form) => {
    form.reset();
    form.dispatchEvent(new Event("change", { bubbles: true }));
  });
});
