/**
 * Page-specific behavior for event-details.php.
 * Registration itself is a plain PHP form POST (see event-details.php +
 * register_for_event() in config/supabase.php) rather than a client-side
 * insert, since this app's auth session lives server-side in PHP — the
 * anonymous `db` client here has no signed-in session to satisfy RLS with.
 */

document.addEventListener("DOMContentLoaded", () => {
  wireHeroGallery();
  wireNotificationBell();
});

function wireHeroGallery() {
  const heroImage = document.getElementById("heroImage");
  document.querySelectorAll(".hero-dot").forEach((dot) => {
    dot.addEventListener("click", () => {
      document.querySelectorAll(".hero-dot").forEach((d) => d.classList.remove("active"));
      dot.classList.add("active");
      if (heroImage) heroImage.style.backgroundImage = `url('${dot.dataset.src}')`;
    });
  });
}

async function wireNotificationBell() {
  const btn = document.getElementById("notifBtn");
  const dropdown = document.getElementById("notifDropdown");
  const dot = document.getElementById("notifDot");
  const list = document.getElementById("notifList");
  if (!btn || !window.db) return;

  const notifications = await window.db.getNotifications(window.CURRENT_USER_ID);
  const unread = (notifications || []).filter((n) => n.status !== "read");

  if (unread.length > 0) {
    dot.hidden = false;
    dot.textContent = unread.length > 9 ? "9+" : String(unread.length);
  }

  if (notifications && notifications.length > 0) {
    list.innerHTML = notifications
      .map(
        (n) => `
        <div class="notif-item ${n.status !== "read" ? "unread" : ""}">
          <p class="notif-title">${escapeHtml(n.title || "")}</p>
          <p class="notif-message">${escapeHtml(n.message || "")}</p>
        </div>`
      )
      .join("");
  }

  btn.addEventListener("click", () => {
    dropdown.hidden = !dropdown.hidden;
  });
  document.addEventListener("click", (e) => {
    if (!dropdown.hidden && !dropdown.contains(e.target) && !btn.contains(e.target)) {
      dropdown.hidden = true;
    }
  });
}

function escapeHtml(str) {
  const div = document.createElement("div");
  div.textContent = str;
  return div.innerHTML;
}
