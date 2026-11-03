/**
 * Campus Event Hub — install the site on a phone (a home-screen shortcut
 * that opens like an app). Loaded on every page (app_head_tags()).
 *  - Registers sw.js (needed for Chrome / Edge / Samsung Internet to offer
 *    "Install app"; on a real site that needs HTTPS — localhost works too).
 *  - "Install app" buttons ([data-install-app], e.g. in the sidebar) and,
 *    once per phone, a small banner. Where the browser offers its own
 *    install prompt it's used; otherwise (iPhone / iPad, or no HTTPS) a
 *    pop-up shows the two taps that add the shortcut.
 */
(function () {
  var script = document.currentScript;
  var base = script ? script.src.replace(/\/assets\/js\/app\.js(\?.*)?$/, '') : '';

  if ('serviceWorker' in navigator && (location.protocol === 'https:' || /^(localhost|127\.0\.0\.1)$/.test(location.hostname))) {
    navigator.serviceWorker.register(base + '/sw.js').catch(function () { /* the shortcut still works without it */ });
  }

  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (standalone) return; // already opened from the home screen

  var ua = navigator.userAgent;
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var isPhone = isIOS || /android|mobile/i.test(ua) || window.matchMedia('(pointer: coarse) and (max-width: 900px)').matches;
  var prompt = null; // the browser's own "Install app" prompt, when it offers one
  var DISMISSED = 'ceh-install-dismissed';

  function store(key, value) {
    try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch (e) { return null; }
    return null;
  }

  function showButtons() {
    document.querySelectorAll('[data-install-app]').forEach(function (b) {
      var label = b.querySelector('[data-install-label]');
      if (label && !isPhone && b.getAttribute('data-desktop-label')) label.textContent = b.getAttribute('data-desktop-label');
      b.hidden = false;
    });
  }
  function hideAll() {
    document.querySelectorAll('[data-install-app]').forEach(function (b) { b.hidden = true; });
    var banner = document.querySelector('.install-banner');
    if (banner) banner.remove();
  }

  function help() {
    var steps = isIOS
      ? ['Tap the <strong>Share</strong> button (the square with an arrow) in Safari.', 'Choose <strong>Add to Home Screen</strong>.', 'Tap <strong>Add</strong>. Campus Event Hub is now on your home screen.']
      : isPhone
        ? ['Open your browser\'s menu (<strong>⋮</strong> at the top right).', 'Choose <strong>Add to Home screen</strong> or <strong>Install app</strong>.', 'Tap <strong>Add</strong>. Campus Event Hub is now on your home screen.']
        : ['In Chrome or Edge, click the <strong>install</strong> icon at the right end of the address bar —', 'or open the menu (<strong>⋮</strong> / <strong>…</strong>) and choose <strong>Install Campus Event Hub</strong> (Edge: Apps › Install this site as an app).', 'On a phone: open this site and use <strong>Add to Home screen</strong>.'];
    var box = document.createElement('dialog');
    box.className = 'install-help';
    box.innerHTML = '<h2>Add Campus Event Hub to your ' + (isPhone ? 'phone' : 'device') + '</h2><ol>'
      + steps.map(function (s) { return '<li>' + s + '</li>'; }).join('')
      + '</ol><p>It opens like an app, straight to the site — no app store needed.</p>'
      + '<div class="install-help-actions"><button type="button" class="btn btn-primary" data-close>Got it</button></div>';
    document.body.appendChild(box);
    box.querySelector('[data-close]').addEventListener('click', function () { box.close(); box.remove(); });
    box.addEventListener('close', function () { box.remove(); });
    if (typeof box.showModal === 'function') box.showModal(); else box.setAttribute('open', '');
  }

  function install() {
    if (prompt) {
      prompt.prompt();
      prompt.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') hideAll();
        prompt = null;
      });
    } else {
      help();
    }
  }

  function banner() {
    if (!isPhone || store(DISMISSED) || document.querySelector('.install-banner')) return;
    var bar = document.createElement('div');
    bar.className = 'install-banner';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Add to home screen');
    bar.innerHTML = '<img src="' + base + '/assets/images/app/icon-192.png" alt="" width="36" height="36">'
      + '<span>Add <strong>Campus Event Hub</strong> to your home screen</span>'
      + '<button type="button" class="install-banner-add">Add</button>'
      + '<button type="button" class="install-banner-close" aria-label="Not now">×</button>';
    document.body.appendChild(bar);
    bar.querySelector('.install-banner-add').addEventListener('click', function () { install(); });
    bar.querySelector('.install-banner-close').addEventListener('click', function () { store(DISMISSED, '1'); bar.remove(); });
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); // show our own button instead of the browser's mini bar
    prompt = e;
    showButtons();
    banner();
  });
  window.addEventListener('appinstalled', function () { store(DISMISSED, '1'); hideAll(); });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-install-app]');
    if (!btn) return;
    e.preventDefault();
    install();
  });

  document.addEventListener('DOMContentLoaded', function () {
    // Phones can always add a shortcut (with the browser's menu if there's no prompt).
    if (isPhone) { showButtons(); banner(); }
  });
  if (document.readyState !== 'loading' && isPhone) { showButtons(); banner(); }
})();
