/**
 * Event Hub Assistant — the chat box in the bottom-right corner of every
 * page with the sidebar (includes/assistant-widget.php; answers come from
 * api/assistant.php).
 *
 *   - The conversation is kept on the server (PHP session), so the same chat
 *     is there on every page until "New chat" or logging out.
 *   - Whether the box is open, an unsent message and a reply that arrived
 *     while it was closed are remembered per browser tab (sessionStorage),
 *     so the box comes back the way it was after changing page.
 *   - When the assistant opens a page, the browser goes there after the reply
 *     (and the box reopens there) — unless a form on this page has unsaved
 *     changes; then only the "Open …" button is shown.
 *   - A reply still on its way when the page changed shows up on the next page.
 *
 * Replies are shown as text: only **bold**, lists and links to pages of this
 * site are formatted, so nothing in a reply can run on the page.
 */
(function () {
  const root = document.querySelector("[data-assistant]");
  const bootEl = document.getElementById("assistantData");
  if (!root || !bootEl) return;
  let boot;
  try {
    boot = JSON.parse(bootEl.textContent);
  } catch (e) {
    return;
  }

  const memory = {
    get(key) {
      try { return sessionStorage.getItem("assistant:" + key); } catch (e) { return null; }
    },
    set(key, value) {
      try {
        if (value === null) sessionStorage.removeItem("assistant:" + key);
        else sessionStorage.setItem("assistant:" + key, value);
      } catch (e) { /* storage blocked: the box just starts closed */ }
    },
  };

  const launcher = root.querySelector("[data-assistant-toggle]");
  const panel = root.querySelector("[data-assistant-panel]");
  const log = root.querySelector("[data-assistant-log]");
  const form = root.querySelector("[data-assistant-form]");
  const input = root.querySelector("[data-assistant-input]");
  const sendBtn = root.querySelector("[data-assistant-send]");
  const resetBtn = root.querySelector("[data-assistant-reset]");
  const closeBtn = root.querySelector("[data-assistant-close]");
  const dot = root.querySelector("[data-assistant-dot]");
  const phone = window.matchMedia("(max-width: 760px)");
  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  let entries = Array.isArray(boot.log) ? boot.log : [];
  let waiting = false;
  let typingRow = null;
  let unsavedForm = false;

  // Last in the page, after the content, for keyboard and screen-reader order.
  document.body.appendChild(root);
  root.hidden = false;

  /* ---------------- Safe formatting ---------------- */
  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  }

  // A page of this site (a path under the site's base, e.g. /…/public/events.php?q=x), or null.
  function siteUrl(url) {
    const base = boot.base + "/";
    url = String(url || "");
    if (url.indexOf(base) !== 0) return null;
    return /^[a-z0-9-]+(\/[a-z0-9-]+)*\.php(\?[\w\-=&%.+]*)?(#[\w-]*)?$/i.test(url.slice(base.length)) ? url : null;
  }

  function bold(html) {
    return html.replace(/\*\*([^*\n]+)\*\*/g, "<strong>$1</strong>");
  }

  // One line: [label](url) links (only to pages of this site) and **bold**.
  function inline(raw) {
    const link = /\*{0,2}\[([^\]\n]{1,160})\]\(([^)\s]{1,400})\)\*{0,2}/g;
    let html = "";
    let last = 0;
    let m;
    while ((m = link.exec(raw))) {
      html += bold(escapeHtml(raw.slice(last, m.index)));
      const url = siteUrl(m[2]);
      const label = escapeHtml(m[1].replace(/\*\*/g, ""));
      html += url ? '<a href="' + escapeHtml(url) + '">' + label + "</a>" : label;
      last = link.lastIndex;
    }
    html += bold(escapeHtml(raw.slice(last)));
    return html.replace(/\*\*/g, ""); // a leftover half of a **pair**
  }

  // Paragraphs, "- " / "1. " lists, and headings shown as bold lines.
  function formatReply(raw) {
    const lines = String(raw || "").replace(/\r/g, "").split("\n");
    let html = "";
    let para = [];
    let list = null;
    const flush = () => {
      if (para.length) html += "<p>" + para.map(inline).join("<br>") + "</p>";
      para = [];
    };
    const closeList = () => {
      if (list) html += "</" + list + ">";
      list = null;
    };
    lines.forEach((line) => {
      const text = line.trim();
      const item = /^(?:[-*•]|(\d{1,2})[.)])\s+(.+)$/.exec(text);
      if (item) {
        flush();
        const type = item[1] ? "ol" : "ul";
        if (list !== type) {
          closeList();
          html += "<" + type + ">";
          list = type;
        }
        html += "<li>" + inline(item[2]) + "</li>";
      } else if (!text) {
        flush();
        closeList();
      } else {
        closeList();
        const heading = /^#{1,6}\s+(.+)$/.exec(text);
        para.push(heading ? "**" + heading[1].replace(/\*\*/g, "") + "**" : text);
      }
    });
    flush();
    closeList();
    return html;
  }

  /* ---------------- Drawing the conversation ---------------- */
  function scrollToEnd() {
    log.scrollTop = log.scrollHeight;
  }

  function goButton(link) {
    const url = siteUrl(link && link.url);
    if (!url) return null;
    const a = document.createElement("a");
    a.className = "assistant-go";
    a.href = url;
    a.textContent = "Open " + link.label + " →";
    a.addEventListener("click", () => memory.set("open", phone.matches ? null : "1"));
    return a;
  }

  function renderEntry(entry) {
    const row = document.createElement("div");
    row.className = "assistant-msg " + (entry.who === "user" ? "is-user" : "is-bot");
    const bubble = document.createElement("div");
    bubble.className = "assistant-bubble";
    if (entry.who === "user") bubble.textContent = entry.text;
    else bubble.innerHTML = formatReply(entry.text);
    row.appendChild(bubble);
    const go = entry.who === "user" ? null : goButton(entry.link);
    if (go) row.appendChild(go);
    log.appendChild(row);
    return row;
  }

  function renderAll() {
    log.textContent = "";
    typingRow = null;
    const hello = renderEntry({ who: "bot", text: boot.greeting });
    if (!entries.length && boot.suggestions && boot.suggestions.length) {
      const chips = document.createElement("div");
      chips.className = "assistant-suggestions";
      boot.suggestions.forEach((question) => {
        const chip = document.createElement("button");
        chip.type = "button";
        chip.className = "assistant-chip";
        chip.textContent = question;
        chip.addEventListener("click", () => send(question));
        chips.appendChild(chip);
      });
      hello.appendChild(chips);
    }
    entries.forEach(renderEntry);
    if (waiting) showTyping(true);
    scrollToEnd();
  }

  function showTyping(show) {
    if (show && !typingRow) {
      typingRow = document.createElement("div");
      typingRow.className = "assistant-msg is-bot";
      typingRow.innerHTML = '<div class="assistant-bubble assistant-typing" role="status" aria-label="The assistant is typing"><span></span><span></span><span></span></div>';
      log.appendChild(typingRow);
      scrollToEnd();
    } else if (!show && typingRow) {
      typingRow.remove();
      typingRow = null;
    }
  }

  function notice(text) {
    const row = document.createElement("div");
    row.className = "assistant-notice";
    row.setAttribute("role", "status");
    row.textContent = text;
    log.appendChild(row);
    scrollToEnd();
  }

  function setWaiting(on) {
    waiting = on;
    if (sendBtn) sendBtn.disabled = on;
    if (resetBtn) resetBtn.disabled = on;
    log.querySelectorAll(".assistant-chip").forEach((chip) => { chip.disabled = on; });
    showTyping(on);
  }

  /* ---------------- Open / close ---------------- */
  function setOpen(open, focus) {
    panel.hidden = !open;
    root.classList.toggle("is-open", open);
    launcher.setAttribute("aria-expanded", String(open));
    launcher.setAttribute("aria-label", open ? "Close the Event Hub Assistant" : "Open the Event Hub Assistant");
    memory.set("open", open ? "1" : null);
    if (open) {
      memory.set("unread", null);
      dot.hidden = true;
      scrollToEnd();
      // Phones: no keyboard popping up until they tap the box.
      if (focus && input && !phone.matches) input.focus();
    } else if (focus) {
      launcher.focus();
    }
  }

  launcher.addEventListener("click", () => setOpen(panel.hidden, true));
  closeBtn.addEventListener("click", () => setOpen(false, true));
  panel.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      e.stopPropagation();
      setOpen(false, true);
    }
  });

  /* ---------------- Asking ---------------- */
  async function send(text) {
    text = String(text || "").trim().slice(0, boot.maxLength);
    if (!text || waiting || !boot.ready) return;
    const chips = log.querySelector(".assistant-suggestions");
    if (chips) chips.remove();
    const mine = renderEntry({ who: "user", text });
    if (input) {
      input.value = "";
      autoGrow();
    }
    memory.set("draft", null);
    setWaiting(true);

    let data;
    try {
      const res = await fetch(boot.endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ action: "send", message: text, page: location.pathname + location.search, title: document.title }),
      });
      data = await res.json();
    } catch (e) {
      data = { ok: false, error: "Couldn't reach the assistant. Check your connection and try again." };
    }
    setWaiting(false);

    if (!data || !data.ok || !data.entry) {
      // Not saved: take the question back so it can be sent again.
      mine.remove();
      if (input && !input.value) {
        input.value = text;
        autoGrow();
      }
      notice((data && data.error) || "Sorry, I couldn't answer that. Please try again.");
      return;
    }
    entries.push({ who: "user", text: text, link: null }, data.entry);
    renderEntry(data.entry);
    scrollToEnd();
    if (data.entry.link) follow(data.entry.link);
  }

  // The assistant opened a page: go there after the reply (the box reopens there).
  function follow(link) {
    const url = siteUrl(link.url);
    if (!url) return;
    const target = new URL(url, location.href);
    if (target.pathname === location.pathname && target.search === location.search) {
      if (target.hash) location.hash = target.hash;
      return;
    }
    if (unsavedForm) {
      notice("You have unsaved changes on this page, so I didn't leave it. Use the Open button when you're ready.");
      return;
    }
    // On phones the box would cover the page, so it waits closed, with a dot.
    memory.set("open", phone.matches ? null : "1");
    if (phone.matches) memory.set("unread", "1");
    setTimeout(() => { location.href = url; }, reduceMotion ? 300 : phone.matches ? 1300 : 900);
  }

  // A reply that was still on its way when the last page closed.
  function waitForReply(tries) {
    setWaiting(true);
    fetch(boot.endpoint + "?action=history", { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then((res) => res.json())
      .then((data) => {
        if (data && data.ok && data.busy && tries > 0) {
          setTimeout(() => waitForReply(tries - 1), 1500);
          return;
        }
        setWaiting(false);
        if (data && data.ok && Array.isArray(data.log)) {
          entries = data.log;
          renderAll();
          if (panel.hidden && entries.length) dot.hidden = false;
        }
      })
      .catch(() => setWaiting(false));
  }

  /* ---------------- Message box ---------------- */
  function autoGrow() {
    if (!input) return;
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 120) + "px";
  }

  if (form && input) {
    input.value = memory.get("draft") || "";
    autoGrow();
    input.addEventListener("input", () => {
      autoGrow();
      memory.set("draft", input.value || null);
    });
    input.addEventListener("keydown", (e) => {
      // Enter sends; Shift+Enter is a new line.
      if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        send(input.value);
      }
    });
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      send(input.value);
    });
  }

  if (resetBtn) {
    resetBtn.addEventListener("click", () => {
      if (waiting) return;
      entries = [];
      renderAll();
      fetch(boot.endpoint, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ action: "reset" }),
      }).catch(() => {});
      if (input && !phone.matches) input.focus();
    });
  }

  // Unsaved work on the page (a form being filled in, e.g. a new event):
  // the assistant won't take the user away from it by itself.
  document.addEventListener("input", (e) => {
    const f = e.target && e.target.closest ? e.target.closest("form") : null;
    if (f && !root.contains(f) && (f.getAttribute("method") || "").toLowerCase() === "post") unsavedForm = true;
  }, true);
  document.addEventListener("submit", (e) => {
    if (!root.contains(e.target)) unsavedForm = false;
  }, true);

  /* ---------------- Start ---------------- */
  renderAll();
  if (memory.get("unread")) dot.hidden = false;
  if (memory.get("open") === "1") setOpen(true, false);
  if (boot.busy) waitForReply(80);
})();
