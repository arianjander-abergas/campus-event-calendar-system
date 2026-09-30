/**
 * Campus Event Hub — the account fields of the sign-up form and My Profile
 * (includes/account-fields.php):
 *  - Student / Faculty: the student-only fields show (and are required) for students.
 *  - Department: the Organization list shows only that department's organizations.
 *  - Irregular: opens the pop-up to tick the year levels of the student's
 *    subjects (the highest year level is always ticked; higher ones can't be).
 * The server checks everything again (validate_account_fields()).
 */
document.addEventListener("DOMContentLoaded", () => {
  const root = document.querySelector("[data-account-fields]");
  if (!root) return;
  const form = root.closest("form");

  /* ---- Student / faculty ---- */
  const studentBox = root.querySelector("[data-student-only]");
  const typeInputs = form.querySelectorAll('[name="account_type"]');
  const accountType = () => {
    const checked = form.querySelector('input[name="account_type"]:checked') || form.querySelector('input[type="hidden"][name="account_type"]');
    return checked ? checked.value : "";
  };
  function syncType() {
    const student = accountType() === "student";
    if (studentBox) studentBox.hidden = !student;
    root.querySelectorAll("[data-student-required]").forEach((el) => { el.required = student; });
  }
  typeInputs.forEach((i) => i.addEventListener("change", syncType));
  syncType();

  /* ---- Department -> its organizations ---- */
  const dept = root.querySelector("#department_id");
  const org = root.querySelector("#organization_id");
  function syncOrgs() {
    if (!dept || !org || org.disabled) return;
    const d = dept.value;
    org.querySelectorAll("optgroup").forEach((g) => {
      // Organizations without a department stay available to everyone.
      const show = !d || g.dataset.department === d || g.dataset.department === "";
      g.hidden = !show;
      g.disabled = !show;
    });
    const current = org.selectedOptions[0];
    if (current && current.value && current.parentElement.disabled) org.value = "";
  }
  dept?.addEventListener("change", syncOrgs);
  // Choosing an organization first fills in its department.
  org?.addEventListener("change", () => {
    const d = org.selectedOptions[0]?.dataset.department;
    if (dept && d && dept.value !== d) { dept.value = d; syncOrgs(); }
  });
  syncOrgs();

  /* ---- Irregular students: the pop-up ---- */
  const modal = document.getElementById("irregularModal");
  if (!modal) return;
  const year = root.querySelector("#year_level");
  const statusInputs = root.querySelectorAll('input[name="student_type"]');
  const levelBoxes = modal.querySelectorAll('input[name="irregular_year_levels[]"]');
  const ack = modal.querySelector("[data-irregular-ack]");
  const error = modal.querySelector("[data-irregular-error]");
  const summary = root.querySelector("[data-irregular-summary]");
  const summaryText = root.querySelector("[data-irregular-text]");
  const ordinal = (n) => n + (["th", "st", "nd", "rd"][n] || "th");
  let confirmed = [...levelBoxes].some((b) => b.checked);
  let saved = [...levelBoxes].map((b) => b.checked);

  function syncLevels() {
    const highest = Number(year?.value) || 0;
    levelBoxes.forEach((b) => {
      const n = Number(b.value);
      if (highest && n === highest) b.checked = true;
      if (highest && n > highest) b.checked = false;
      b.disabled = highest > 0 && (n > highest || n === highest);
    });
  }
  function chosenLevels() {
    return [...levelBoxes].filter((b) => b.checked).map((b) => Number(b.value));
  }
  function describe(levels) {
    const words = levels.map(ordinal);
    const last = words.pop();
    return (words.length ? words.join(", ") + " & " : "") + last + " year";
  }
  function updateSummary() {
    const irregular = form.querySelector('input[name="student_type"]:checked')?.value === "irregular";
    summary.hidden = !irregular;
    const levels = chosenLevels();
    summaryText.textContent = confirmed && levels.length ? "Irregular · subjects in " + describe(levels) : "Irregular · tick your year levels";
  }
  function open() {
    if (!year?.value) {
      alert("Choose your highest year level first.");
      form.querySelector('input[name="student_type"][value="regular"]').checked = true;
      year?.focus();
      updateSummary();
      return;
    }
    saved = [...levelBoxes].map((b) => b.checked);
    syncLevels();
    error.hidden = true;
    if (typeof modal.showModal === "function") modal.showModal(); else modal.setAttribute("open", "");
  }
  function close() {
    if (typeof modal.close === "function") modal.close(); else modal.removeAttribute("open");
  }

  statusInputs.forEach((i) => i.addEventListener("change", () => {
    if (i.checked && i.value === "irregular") open();
    updateSummary();
  }));
  root.querySelector("[data-irregular-open]")?.addEventListener("click", open);
  year?.addEventListener("change", () => { syncLevels(); updateSummary(); });

  modal.querySelector("[data-irregular-confirm]").addEventListener("click", () => {
    const levels = chosenLevels();
    let message = "";
    if (levels.length < 2) message = "Tick at least one year level besides your highest year level. If all your subjects are in one year level, you're a regular student.";
    else if (!ack.checked) message = "Please confirm that these are the year levels of your enrolled subjects.";
    if (message) {
      error.textContent = message;
      error.hidden = false;
      return;
    }
    confirmed = true;
    close();
    updateSummary();
  });
  function cancel() {
    levelBoxes.forEach((b, i) => { b.checked = saved[i]; });
    if (!confirmed) form.querySelector('input[name="student_type"][value="regular"]').checked = true;
    close();
    updateSummary();
  }
  modal.querySelector("[data-irregular-cancel]").addEventListener("click", cancel);
  modal.addEventListener("cancel", (e) => { e.preventDefault(); cancel(); }); // Esc key

  // Disabled checkboxes aren't submitted: enable them just before sending.
  form.addEventListener("submit", () => { levelBoxes.forEach((b) => { b.disabled = false; }); });

  syncLevels();
  updateSummary();
});
